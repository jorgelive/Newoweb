<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Finanzas\Contract\FinMovimientoProviderInterface;
use App\Finanzas\Dto\FinMovimientoDto;
use App\Finanzas\Dto\FinMovimientoFiltro;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Repository\PmsPagoFinancieroRepository;

/**
 * Los pagos del PMS vistos como caja: efectivo, Yape, transferencias, tarjeta…
 *
 * Traduce `PmsPagoFinanciero` al vocabulario de Finanzas. Incluye TODOS los medios, no
 * sólo los cobrados por pasarela: la vista de caja no distingue por dónde entró el dinero,
 * que es justo su gracia frente al panel de cada reserva.
 *
 * @see \App\Finanzas\Contract\FinMovimientoProviderInterface
 */
final class PmsPagoMovimientoProvider implements FinMovimientoProviderInterface
{
    public function __construct(
        private readonly PmsPagoFinancieroRepository $pagos,
    ) {}

    public function soporta(): FinOrigenCobro
    {
        return FinOrigenCobro::PMS_RESERVA;
    }

    public function buscar(FinMovimientoFiltro $filtro): array
    {
        return array_map(
            $this->aMovimiento(...),
            $this->pagos->buscarParaCaja($filtro),
        );
    }

    /**
     * Las etiquetas salen de `PmsMedioPago::label()`, que es su única fuente de verdad —
     * el mismo criterio que ya sigue `PmsEnumAjaxController` para el panel.
     */
    public function mediosDisponibles(): array
    {
        return array_map(
            static fn (PmsMedioPago $medio): array => ['value' => $medio->value, 'label' => $medio->label()],
            PmsMedioPago::cases(),
        );
    }

    private function aMovimiento(PmsPagoFinanciero $pago): FinMovimientoDto
    {
        $reserva = $pago->getInformacionFinanciera()?->getReserva();

        return new FinMovimientoDto(
            id: (string) $pago->getId(),
            fecha: $pago->getFechaPago()?->format('Y-m-d') ?? '',
            medioCodigo: $pago->getMedioPago()->value,
            medioEtiqueta: $pago->getMedioPago()->label(),
            // El NETO, no `montoTotalCobrado()`: el recargo de la tarjeta lo cobra la
            // pasarela, no entra en nuestra caja. Misma regla que en el panel financiero.
            monto: $pago->getMonto(),
            moneda: $pago->getMoneda()?->getId() ?? 'USD',
            tipoCambio: $pago->getTipoCambio(),
            referencia: $pago->getReferencia(),
            origenTipo: FinOrigenCobro::PMS_RESERVA->value,
            origenId: (string) $reserva?->getId(),
            origenReferencia: $reserva?->getLocalizador(),
            origenDescripcion: $this->describir($pago),
            cobradorNombre: $pago->getCobradorNombre(),
            esAutomatico: $pago->isEsAutomatico(),
        );
    }

    private function describir(PmsPagoFinanciero $pago): string
    {
        $reserva = $pago->getInformacionFinanciera()?->getReserva();

        if ($reserva === null) {
            return '—';
        }

        $partes = array_filter([
            $reserva->getNombreApellido(),
            $reserva->getUnidadesAggregate(),
        ]);

        return $partes === [] ? '—' : substr(implode(' · ', $partes), 0, 160);
    }
}
