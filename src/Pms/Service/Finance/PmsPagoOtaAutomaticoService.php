<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Service\Finance\TipoCambioDelDia;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Depósito automático de las OTA que cobran al huésped (Airbnb, VRBO).
 *
 * En esos canales (`PmsChannel::CANAL_PAGO_TOTAL`) el dinero no lo cobramos nosotros: la OTA
 * cobra y **deposita en la cuenta bancaria**. No hay ningún pago que teclear, así que la reserva
 * aparecía eternamente como impagada aunque estuviera cobrada al 100 %.
 *
 * Este servicio crea y mantiene ese pago, dejando en 0 el saldo DE LO QUE COBRÓ EL CANAL.
 *
 * DECISIONES:
 *
 *  · **Importe = total de cargos DEL CANAL** (`getTotalCargosDelCanal()`: los marcados como
 *    espejo contable, `PmsCargoFinanciero::$esAutomatico`), no el total de la cabecera.
 *    El canal deposita lo suyo; un cargo manual —la ampliación directa de una estancia de
 *    Airbnb, un consumo extra— lo paga el huésped a nosotros y tiene que MOVER el saldo.
 *    Cuando el depósito seguía a `totalCargos` entero, en una reserva mixta se tragaba los
 *    cargos manuales: el saldo volvía a cero tras cada movimiento del operador, y registrar
 *    el pago real lo dejaba en negativo. En una reserva OTA pura los dos criterios coinciden.
 *    Si algún día se quiere reflejar el neto que llega al banco (descontada la comisión de
 *    la OTA), hay que cambiar esto Y aceptar que ese saldo deje de ser cero.
 *  · **Fecha = llegada + 1 día.** Es cuando la OTA libera el depósito.
 *  · **Medio = transferencia bancaria**, que es como entra.
 *  · **Moneda = la de la cabecera**, así no necesita tipo de cambio y no depende de §12.2.
 *
 * ⚠️ NO se ejecuta al crear la cabecera, aunque sea lo intuitivo: en ese momento la reserva
 * todavía no tiene cargos (llegan después por webhook o por el pull de invoiceItems, §11), así
 * que el pago habría nacido en 0.00. Se ejecuta tras cada recálculo, que es cuando el importe
 * ya se conoce.
 *
 * 🔓 Y respeta el depósito INTERVENIDO: el que el operador fijó a mano abriendo el candado del
 * panel (`PmsPagoFinanciero::$intervenido`) no se toca. Es la válvula para el caso raro en que
 * la OTA deposita algo distinto de lo que facturó.
 */
final class PmsPagoOtaAutomaticoService
{
    /** Marca de reentrada: distingue nuestras propias escrituras de las del operador. */
    private bool $sincronizando = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TipoCambioDelDia $tipoCambioDelDia,
        // Resuelve el id de moneda a su entidad. Hace falta desde que el depósito nace en la
        // moneda de los cargos que refleja y no en la de la cabecera.
        private readonly MonedaResolver $monedaResolver,
    ) {}

    public function estaSincronizando(): bool
    {
        return $this->sincronizando;
    }

    /** ¿Esta reserva la cobra la OTA por nosotros? */
    public function aplica(PmsInformacionFinanciera $info): bool
    {
        $canal = $info->getReserva()?->getChannel()?->getId();

        return $canal !== null && in_array($canal, PmsChannel::CANAL_PAGO_TOTAL, true);
    }

    /**
     * Deja el depósito cuadrado con los cargos. NO hace flush: lo hace quien llama.
     *
     * @return bool true si tocó algo (y por tanto hay que volver a recalcular los totales)
     */
    public function sincronizar(PmsInformacionFinanciera $info): bool
    {
        if (!$this->aplica($info)) {
            return false;
        }

        // 💱 UN DEPÓSITO POR MONEDA.
        //
        // `buscarAutomatico()` devolvía el PRIMERO y ya: con cargos del canal en dos monedas, el
        // segundo depósito no nacía nunca y el primero recibía el importe de la otra — diciendo
        // que la OTA remitió algo que nunca remitió. Con los datos de hoy es un no-op (cada canal
        // factura en una sola moneda), lo que lo hace seguro de desplegar.
        $objetivos = $info->getTotalCargosDelCanalPorMoneda();
        $existentes = $this->buscarAutomaticos($info);
        $tocado = false;

        // 1. Los que sobran: monedas que ya no tienen cargos del canal. Mismo motivo que el
        //    borrado de filas huérfanas del rollup — un depósito de una moneda sin cargos
        //    descuadra el saldo diciendo que se cobró algo que ya no existe.
        foreach ($existentes as $moneda => $pago) {
            if (isset($objetivos[$moneda]) && (float) $objetivos[$moneda] > 0) {
                continue;
            }

            // 🔓 Intervenido: manda el operador, ni se toca ni se borra (§12.4.5).
            if ($pago->isIntervenido()) {
                continue;
            }

            $this->sincronizando = true;
            try {
                $info->removePago($pago);
                $this->em->remove($pago);
                $tocado = true;
            } finally {
                $this->sincronizando = false;
            }
        }

        $fecha = $this->fechaDeposito($info);

        // 2. Los que faltan o cambiaron.
        foreach ($objetivos as $moneda => $objetivo) {
            if ((float) $objetivo <= 0) {
                continue;
            }

            $pago = $existentes[$moneda] ?? null;

            // 🔓 INTERVENIDO: el operador abrió el candado y fijó el importe a mano (§12.4.5).
            // A partir de ahí manda su criterio y aquí no se toca NADA, porque cualquier cambio
            // le desharía la corrección en el siguiente recálculo — que es justo lo que hace
            // inútil un campo editable. Sigue encontrándose (`esAutomatico` no se apaga), así
            // que tampoco nace un segundo depósito en esa moneda.
            //
            // La vuelta atrás es poner `intervenido` en false: reaparece por aquí y se vuelve a
            // cuadrar solo —o se retira, si ya no quedan cargos del canal en su moneda—.
            if ($pago !== null && $pago->isIntervenido()) {
                continue;
            }

            if ($pago === null) {
                $this->sincronizando = true;
                try {
                    $pago = new PmsPagoFinanciero();
                    $pago->setEsAutomatico(true);
                    // En la moneda de LOS CARGOS que refleja, no en la de la cabecera.
                    $pago->setMoneda($this->monedaResolver->resolve($moneda));
                    $pago->setMedioPago(PmsMedioPago::TRANSFERENCIA_BANCARIA);
                    $pago->setComisionPorcentaje('0.00');
                    $pago->setNotas('Depósito automático del canal (cobra la OTA).');
                    $pago->setMonto($objetivo);
                    $pago->setFechaPago($fecha);

                    // ⚠️ El TC del día, igual que hacen el persister de facturas y los cargos.
                    //
                    // Este servicio era el ÚNICO creador de registros financieros que no lo ponía,
                    // y se notaba sólo al mirar la ficha en soles: el depósito no convertía, así
                    // que el total pagado en PEN salía corto y el saldo pintaba en rojo una deuda
                    // que no existía. Visto en la reserva V6WDDQ el 15/08/2026.
                    //
                    // Hoy lo pondría igualmente `PmsTipoCambioSnapshotListener` en `prePersist`
                    // (§12.4.1b); se deja explícito porque aquí se conoce la FECHA del depósito,
                    // que no es la de hoy, y el listener sólo respeta lo que ya viene puesto.
                    $pago->setTipoCambio($this->tipoCambioDelDia->venta($fecha));

                    $info->addPago($pago);
                    $this->em->persist($pago);
                    $tocado = true;
                } finally {
                    $this->sincronizando = false;
                }

                continue;
            }

            // Ya existe: sólo se toca si de verdad cambió algo, para no ensuciar el changeset
            // de Doctrine (y disparar recálculos) en cada flush.
            $cambia = (float) $pago->getMonto() !== (float) $objetivo
                || $pago->getFechaPago()?->format('Y-m-d') !== $fecha->format('Y-m-d');

            if (!$cambia) {
                continue;
            }

            $this->sincronizando = true;
            try {
                $pago->setMonto($objetivo);
                $pago->setFechaPago($fecha);
                $tocado = true;
            } finally {
                $this->sincronizando = false;
            }
        }

        return $tocado;
    }

    /**
     * Los depósitos automáticos de esta cabecera, indexados por su moneda.
     *
     * Antes devolvía sólo el primero. Con cargos del canal en dos monedas eso significaba que el
     * segundo depósito no nacía nunca — y que el primero recibía un importe que no era suyo.
     *
     * @return array<string, PmsPagoFinanciero>
     */
    private function buscarAutomaticos(PmsInformacionFinanciera $info): array
    {
        $porMoneda = [];

        foreach ($info->getPagos() as $pago) {
            if ($pago->isEsAutomatico()) {
                $porMoneda[$pago->getMoneda()?->getId() ?? 'USD'] = $pago;
            }
        }

        return $porMoneda;
    }


    /**
     * Llegada + 1 día. Sin fecha de llegada (reserva a medio sincronizar) se usa hoy: es
     * preferible a dejar el pago sin fecha, que la columna no admite.
     */
    private function fechaDeposito(PmsInformacionFinanciera $info): DateTimeImmutable
    {
        $llegada = $info->getReserva()?->getFechaLlegada();

        $base = $llegada !== null
            ? new DateTimeImmutable($llegada->format('Y-m-d'))
            : new DateTimeImmutable('today');

        return $base->modify('+1 day');
    }
}
