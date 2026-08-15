<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
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

        $objetivo = $info->getTotalCargosDelCanal();
        $pago = $this->buscarAutomatico($info);

        // 🔓 INTERVENIDO: el operador abrió el candado y fijó el importe a mano (§12.4.5).
        // A partir de ahí manda su criterio y aquí no se toca NADA —ni el importe, ni la
        // fecha, ni el borrado de más abajo—, porque cualquiera de las tres cosas le
        // desharía la corrección en el siguiente recálculo, que es justo lo que hace inútil
        // un campo editable. Sigue encontrándose (`esAutomatico` no se apaga), así que no
        // nace un segundo depósito.
        //
        // La vuelta atrás es poner `intervenido` en false: el pago reaparece por aquí y se
        // vuelve a cuadrar solo —o se retira, si ya no quedan cargos del canal—.
        if ($pago !== null && $pago->isIntervenido()) {
            return false;
        }

        // Sin cargos del canal todavía (o reserva anulada sin penalización): no se inventa un
        // depósito. Si ya existía uno, se elimina en vez de dejarlo descuadrando el saldo.
        if ((float) $objetivo <= 0) {
            if ($pago !== null) {
                $this->sincronizando = true;
                try {
                    $info->removePago($pago);
                    $this->em->remove($pago);
                } finally {
                    $this->sincronizando = false;
                }

                return true;
            }

            return false;
        }

        $fecha = $this->fechaDeposito($info);

        if ($pago === null) {
            $this->sincronizando = true;
            try {
                $pago = new PmsPagoFinanciero();
                $pago->setEsAutomatico(true);
                $pago->setMoneda($info->getMoneda());
                $pago->setMedioPago(PmsMedioPago::TRANSFERENCIA_BANCARIA);
                $pago->setComisionPorcentaje('0.00');
                $pago->setNotas('Depósito automático del canal (cobra la OTA).');
                $pago->setMonto($objetivo);
                $pago->setFechaPago($fecha);

                $info->addPago($pago);
                $this->em->persist($pago);
            } finally {
                $this->sincronizando = false;
            }

            return true;
        }

        // Ya existe: sólo se toca si de verdad cambió algo, para no ensuciar el changeset
        // de Doctrine (y disparar recálculos) en cada flush.
        $cambia = (float) $pago->getMonto() !== (float) $objetivo
            || $pago->getFechaPago()?->format('Y-m-d') !== $fecha->format('Y-m-d');

        if (!$cambia) {
            return false;
        }

        $this->sincronizando = true;
        try {
            $pago->setMonto($objetivo);
            $pago->setFechaPago($fecha);
        } finally {
            $this->sincronizando = false;
        }

        return true;
    }

    /** El depósito automático de esta cabecera, si existe. */
    private function buscarAutomatico(PmsInformacionFinanciera $info): ?PmsPagoFinanciero
    {
        foreach ($info->getPagos() as $pago) {
            if ($pago->isEsAutomatico()) {
                return $pago;
            }
        }

        return null;
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
