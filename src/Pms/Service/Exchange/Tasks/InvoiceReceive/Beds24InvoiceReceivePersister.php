<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\InvoiceReceive;

use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Dto\Beds24InvoiceItemDto;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Factory\PmsInformacionFinancieraFactory;
use App\Pms\Repository\PmsReservaRepository;
use App\Pms\Service\Finance\MonedaResolver;
use App\Pms\Service\Finance\TipoCambioDelDia;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Persiste la información financiera (invoiceItems) proveniente de Beds24 (Webhook o Pull).
 * Espejo de App\Message\Service\Exchange\Tasks\Beds24Receive\Beds24ReceivePersister.
 *
 * SRP: recibe DTOs de invoiceItems, hace find-or-create de la cabecera financiera de la
 * reserva y hace upsert idempotente de cada concepto (dedupe por beds24ItemId).
 */
readonly class Beds24InvoiceReceivePersister
{
    public function __construct(
        private EntityManagerInterface           $em,
        private PmsInformacionFinancieraFactory  $infoFactory,
        private MonedaResolver                   $monedaResolver,
        private TipoCambioDelDia                  $tipoCambioDelDia,
    ) {}

    /**
     * Sincroniza los conceptos financieros entrantes contra la base local.
     *
     * @param string                 $targetBookId El ID de la reserva en Beds24
     * @param Beds24InvoiceItemDto[]  $items       Conceptos financieros fuertemente tipados
     * @return array<string, int> Estadísticas ['imported', 'updated', 'skipped']
     */
    public function upsertCargos(string $targetBookId, array $items): array
    {
        /** @var PmsReservaRepository $repo */
        $repo = $this->em->getRepository(PmsReserva::class);
        $reserva = $repo->findByAnyBeds24Id($targetBookId);

        if (!$reserva) {
            throw new RuntimeException("Reserva Beds24 $targetBookId no encontrada.");
        }

        $info = $this->infoFactory->upsertForReserva($reserva);

        // Beds24 no envía moneda en los invoiceItems → default USD (regla de negocio).
        // Resolvemos y snapshoteamos el TC una sola vez por llamada (evita N+1 y llamadas SUNAT repetidas).
        $monedaUsd = $this->monedaResolver->resolve(null);
        $tcVenta = $this->tipoCambioDelDia->venta();

        if ($info->getMoneda() === null) {
            $info->setMoneda($monedaUsd);
        }

        $stats = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

        // ¿Este canal cobra al huésped por nosotros (Airbnb, VRBO)? Entonces sus
        // cargos son contabilidad interna —el importe es lo que la OTA nos remite,
        // no lo que el huésped pagó— y se marcan para excluirlos del estado de
        // cuenta que ve el huésped. Ver PmsCargoFinanciero::$esAutomatico.
        $canal = $info->getReserva()?->getChannel()?->getId();
        $esEspejoDeCanal = $canal !== null && in_array($canal, PmsChannel::CANAL_PAGO_TOTAL, true);

        foreach ($items as $dto) {
            if (!$dto->id) {
                continue;
            }

            $extId = (string) $dto->id;

            // Búsqueda de duplicados dentro de la cabecera (dedupe por beds24ItemId)
            $existing = null;
            foreach ($info->getCargos() as $c) {
                if ($c->getBeds24ItemId() === $extId) {
                    $existing = $c;
                    break;
                }
            }

            if ($existing) {
                // Actualizamos sólo si cambió algún campo volátil (importes / estado / enriquecimiento).
                $cambio = $this->aplicarCambiosVolatiles($existing, $dto, $monedaUsd, $tcVenta);

                // El flag se reevalúa SIEMPRE, no sólo cuando cambian importes: hay
                // que corregirlo también si la reserva cambió de canal, o si el cargo
                // se importó antes de que este campo existiera.
                if ($existing->isEsAutomatico() !== $esEspejoDeCanal) {
                    $existing->setEsAutomatico($esEspejoDeCanal);
                    $cambio = true;
                }

                $cambio ? $stats['updated']++ : $stats['skipped']++;
                continue;
            }

            // NUEVO CONCEPTO
            $cargo = new PmsCargoFinanciero($extId);
            $this->hidratar($cargo, $dto, $monedaUsd, $tcVenta);
            $cargo->setEsAutomatico($esEspejoDeCanal);
            $info->addCargo($cargo);
            $this->em->persist($cargo);

            $stats['imported']++;
        }

        // El recálculo de total_cargos/total_pagos lo hace PmsInformacionFinancieraCoherenciaListener
        // (Doctrine onFlush/postFlush) al detectar los cargos tocados arriba.
        $info->setLastSyncedAt(new DateTimeImmutable());

        $this->em->flush();

        return $stats;
    }

    /**
     * Vuelca todos los campos del DTO al cargo (usado al crear).
     */
    private function hidratar(PmsCargoFinanciero $cargo, Beds24InvoiceItemDto $dto, MaestroMoneda $moneda, ?string $tcVenta): void
    {
        $cargo->setBeds24BookingId($dto->bookingId);
        $cargo->setBeds24InvoiceId($dto->invoiceId);
        $cargo->setBeds24InvoiceeId($dto->invoiceeId);
        $cargo->setTipo($dto->type);
        $cargo->setSubTipo($dto->subType);
        $cargo->setDescripcion($dto->description);
        $cargo->setEstado($dto->status);
        $cargo->setCantidad($dto->qty);
        $cargo->setMonto($dto->amount);
        $cargo->setTotalLinea($dto->lineTotal);
        $cargo->setTasaIva($dto->vatRate);
        $cargo->setCreadoPorBeds24($dto->createdBy);
        $cargo->setFechaCreacionBeds24($dto->createTime);
        $cargo->setFechaFactura($dto->invoiceDate);

        // Enriquecimiento local (no viene de Beds24): moneda, clasificación y TC del día.
        $cargo->setMoneda($moneda);
        $cargo->setTipoCargo(PmsTipoCargo::desdeBeds24($dto->description, $dto->subType));
        $cargo->setTipoCambio($tcVenta);
    }

    /**
     * Aplica sólo los campos que Beds24 puede recalcular en una edición posterior
     * (importe, total, estado, descripción). El invoiceId/invoiceDate se rellenan si
     * antes venían vacíos (típico: primero llegó el webhook sin factura, luego el pull).
     *
     * @return bool true si hubo algún cambio real (para contarlo como 'updated').
     */
    private function aplicarCambiosVolatiles(PmsCargoFinanciero $cargo, Beds24InvoiceItemDto $dto, MaestroMoneda $moneda, ?string $tcVenta): bool
    {
        $cambio = false;

        if ($dto->amount !== null && $dto->amount !== $cargo->getMonto()) {
            $cargo->setMonto($dto->amount);
            $cambio = true;
        }
        if ($dto->lineTotal !== null && $dto->lineTotal !== $cargo->getTotalLinea()) {
            $cargo->setTotalLinea($dto->lineTotal);
            $cambio = true;
        }
        if ($dto->status !== null && $dto->status !== $cargo->getEstado()) {
            $cargo->setEstado($dto->status);
            $cambio = true;
        }
        if ($dto->description !== null && $dto->description !== $cargo->getDescripcion()) {
            $cargo->setDescripcion($dto->description);
            $cambio = true;
        }
        // Enriquecimiento: rellenar la factura si el webhook la dejó vacía y ahora sí llega.
        if ($dto->invoiceId !== null && $cargo->getBeds24InvoiceId() === null) {
            $cargo->setBeds24InvoiceId($dto->invoiceId);
            $cambio = true;
        }
        if ($dto->invoiceDate !== null && $cargo->getFechaFactura() === null) {
            $cargo->setFechaFactura($dto->invoiceDate);
            $cambio = true;
        }

        // Backfill de campos locales para cargos creados antes de esta capa (no pisamos lo existente).
        if ($cargo->getTipoCargo() === null) {
            $cargo->setTipoCargo(PmsTipoCargo::desdeBeds24($dto->description, $dto->subType));
            $cambio = true;
        }
        if ($cargo->getMoneda() === null) {
            $cargo->setMoneda($moneda);
            $cambio = true;
        }
        if ($cargo->getTipoCambio() === null && $tcVenta !== null) {
            $cargo->setTipoCambio($tcVenta);
            $cambio = true;
        }

        return $cambio;
    }
}
