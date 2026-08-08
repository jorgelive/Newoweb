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
        private readonly EntityManagerInterface $em,
    ) {}

    public function soporta(): FinOrigenCobro
    {
        return FinOrigenCobro::PMS_RESERVA;
    }

    public function resolver(Uuid $origenId): ?FinOrigenCobroDto
    {
        $reserva = $this->reservas->find($origenId);

        if (!$reserva instanceof PmsReserva) {
            return null;
        }

        $info = $reserva->getInformacionFinanciera();

        // Sin cabecera financiera no hay saldo que cobrar todavía: se devuelve 0 y el
        // servicio lo rechaza con un mensaje claro en vez de reventar por null.
        $saldo = $info?->getSaldo() ?? '0.00';
        $moneda = $info?->getMoneda()?->getId() ?? $reserva->getMoneda()?->getId() ?? 'USD';

        return new FinOrigenCobroDto(
            descripcion: $this->describir($reserva),
            referencia: (string) $reserva->getLocalizador(),
            saldoPendiente: $saldo,
            moneda: $moneda,
            clienteNombre: $reserva->getNombreApellido(),
            clienteEmail: $reserva->getEmailCliente(),
            clienteTelefono: $reserva->getTelefonoContacto(),
        );
    }

    /**
     * Crea el `PmsPagoFinanciero` del cobro por pasarela.
     *
     * Detalles que no son obvios y que hacen que el saldo cuadre:
     *
     * - Se imputa el **neto** (`montoNeto`), no lo que se pasó por la tarjeta. El recargo
     *   cubre a la pasarela, no abona la estancia — es la misma regla que ya documenta
     *   `PmsPagoFinanciero::$monto`.
     * - Se marca `esAutomatico`: lo generó el sistema, igual que los depósitos de las OTA,
     *   y así se distingue de lo que teclea un operador.
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
            ->setEsAutomatico(true)
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
