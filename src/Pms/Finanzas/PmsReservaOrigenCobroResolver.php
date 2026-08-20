<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Finanzas\Contract\FinOrigenCobroResolverInterface;
use App\Finanzas\Dto\FinOrigenCobroDto;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Repository\PmsReservaRepository;
use App\Pms\Service\Message\TelefonoDeContacto;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cómo se cobra una reserva de alojamiento del PMS.
 *
 * Vive en `src/Pms/` y no en `src/Finanzas/` a propósito: el módulo que sabe qué es un
 * saldo de reserva es el PMS. Finanzas sólo declara la interfaz. Cuando exista el módulo
 * de tours, su resolver será el gemelo de este archivo y Finanzas no cambiará una línea.
 *
 * @see \App\Finanzas\Contract\FinOrigenCobroResolverInterface
 */
final class PmsReservaOrigenCobroResolver implements FinOrigenCobroResolverInterface
{
    public function __construct(
        private readonly PmsReservaRepository $reservas,
        private readonly TelefonoDeContacto $telefonos,
        private readonly EntityManagerInterface $em,
    ) {}

    public function soporta(): FinOrigenCobro
    {
        return FinOrigenCobro::PMS_RESERVA;
    }

    public function resolver(Uuid $origenId, ?string $moneda = null): ?FinOrigenCobroDto
    {
        $reserva = $this->reservas->find($origenId);

        if (!$reserva instanceof PmsReserva) {
            return null;
        }

        $info = $reserva->getInformacionFinanciera();

        // 💱 EL SALDO DE UNA MONEDA, no un total convertido.
        //
        // Una pasarela cobra en UNA divisa. Si la reserva debe US$ 65 y S/ 50, cobrarle «un
        // total» exigiría convertir — justo lo que se retiró (§12.2b). Se emite un enlace por
        // moneda y cada uno pide exactamente lo que se debe en ella.
        //
        // Sin `$moneda`, la de mayor saldo: es lo que respondía antes para los llamantes que no
        // saben de esto, y es la respuesta útil cuando sólo hay una.
        $porMoneda = $info === null ? [] : PmsTotalesPorMoneda::de($info)->porMoneda;
        $eleccion = $moneda !== null && isset($porMoneda[$moneda])
            ? $moneda
            : $this->monedaConMasSaldo($porMoneda);

        // Sin cabecera financiera no hay saldo que cobrar todavía: se devuelve 0 y el
        // servicio lo rechaza con un mensaje claro en vez de reventar por null.
        $saldo = $eleccion !== null ? $porMoneda[$eleccion]['saldo'] : '0.00';
        $moneda = $eleccion
            ?? $info?->getMoneda()?->getId()
            ?? $reserva->getMoneda()?->getId()
            ?? 'USD';

        return new FinOrigenCobroDto(
            descripcion: $this->describir($reserva),
            referencia: (string) $reserva->getLocalizador(),
            saldoPendiente: $saldo,
            moneda: $moneda,
            clienteNombre: $reserva->getNombreApellido(),
            clienteEmail: $reserva->getEmailCliente(),
            clienteTelefono: $this->telefonos->para($reserva),
        );
    }

    /**
     * La moneda con más saldo pendiente, o `null` si no hay ninguna con deuda.
     *
     * Con una sola moneda —lo normal— devuelve esa. Con dos, la que más se debe: es la que un
     * operador que no ha elegido querría cobrar primero.
     *
     * @param array<string, array{cargos: string, pagos: string, saldo: string}> $porMoneda
     */
    private function monedaConMasSaldo(array $porMoneda): ?string
    {
        $mejor = null;
        $mayor = 0.0;

        foreach ($porMoneda as $moneda => $cifras) {
            $saldo = (float) $cifras['saldo'];

            if ($saldo > $mayor) {
                $mayor = $saldo;
                $mejor = $moneda;
            }
        }

        return $mejor;
    }

    /**
     * Crea el `PmsPagoFinanciero` del cobro por pasarela.
     *
     * Detalles que no son obvios y que hacen que el saldo cuadre:
     *
     * - Se imputa el **neto** (`montoNeto`), no lo que se pasó por la tarjeta. El recargo
     *   cubre a la pasarela, no abona la estancia — es la misma regla que ya documenta
     *   `PmsPagoFinanciero::$monto`.
     * - **NO se marca `esAutomatico`**, aunque lo genere el sistema. Esa bandera no
     *   significa "lo creó el sistema" sino "el sistema lo REGENERA solo": es el depósito
     *   espejo de las OTA de pago total, y de ella cuelgan dos guardas —no borrable
     *   (`PmsInformacionFinancieraCoherenciaListener`) y no editable
     *   (`assertPagoAutomaticoNoEditable`)— que existen para que el operador no pelee
     *   contra un registro que va a reaparecer.
     *
     *   Un cobro por pasarela no reaparece: es un hecho consumado, y al revés, tiene que
     *   poder borrarse (es el paso 2 de revertir un cobro, después de devolver el dinero
     *   en el Backoffice). Marcarlo bloqueaba el borrado con un 500 y un mensaje sobre
     *   "depósitos del canal" que no venía a cuento.
     *
     *   La trazabilidad no se pierde: el enlace apunta al pago con `movimientoGeneradoId`,
     *   y el pago lleva la referencia de la transacción y la nota con la pasarela.
     * - **Sin cobrador**: nadie recibió este dinero en mano, entra directo a la cuenta.
     *   Ver la nota de `PmsPagoFinanciero::$cobrador`.
     * - No se toca `total_pagos` ni el estado de pago de la reserva: de eso ya se encarga
     *   `PmsInformacionFinancieraCoherenciaListener` al persistir el pago.
     */
    public function registrarCobro(FinEnlacePago $enlace): ?Uuid
    {
        $reserva = $this->reservas->find($enlace->getOrigenId());

        if (!$reserva instanceof PmsReserva) {
            return null;
        }

        $info = $reserva->getInformacionFinanciera();

        if ($info === null) {
            return null;
        }

        $pago = new PmsPagoFinanciero();
        $pago
            ->setInformacionFinanciera($info)
            ->setMoneda($enlace->getMoneda())
            ->setMonto($enlace->getMontoNeto())
            ->setMedioPago(PmsMedioPago::TARJETA_CREDITO)
            ->setComisionPorcentaje($enlace->getRecargoPorcentaje())
            ->setFechaPago($enlace->getPagadoEn() ?? new DateTimeImmutable())
            ->setReferencia($enlace->getTransaccionUuid() ?? $enlace->getOrdenId())
            ->setNotas(sprintf(
                'Cobro por %s. Enlace de pago %s.',
                $enlace->getPasarela()->etiqueta(),
                $enlace->getOrdenId() ?? (string) $enlace->getId(),
            ));

        $this->em->persist($pago);

        // Sin flush: la transacción la cierra `FinEnlacePagoService::confirmarPago()`, para
        // que el enlace y su pago entren juntos o no entre ninguno. El id ya existe (el
        // constructor de la entidad lo genera), así que puede devolverse antes del INSERT.
        return $pago->getId();
    }

    private function describir(PmsReserva $reserva): string
    {
        $unidad = $reserva->getUnidadesAggregate() ?: $reserva->getNombreHabitacion();
        $llegada = $reserva->getFechaLlegada()?->format('d/m/Y');
        $salida = $reserva->getFechaSalida()?->format('d/m/Y');

        $descripcion = trim(sprintf('%s — %s', $reserva->getNombreHotel(), $unidad), ' —');

        if ($llegada && $salida) {
            $descripcion .= sprintf(' (%s al %s)', $llegada, $salida);
        }

        return substr($descripcion, 0, 255);
    }
}
