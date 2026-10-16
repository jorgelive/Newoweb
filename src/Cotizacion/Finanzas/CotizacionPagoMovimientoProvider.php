<?php

declare(strict_types=1);

namespace App\Cotizacion\Finanzas;

use App\Cotizacion\Entity\CotizacionPago;
use App\Cotizacion\Enum\CotizacionMedioPagoEnum;
use App\Cotizacion\Repository\CotizacionPagoRepository;
use App\Finanzas\Contract\FinMovimientoProviderInterface;
use App\Finanzas\Dto\FinMovimientoDto;
use App\Finanzas\Dto\FinMovimientoFiltro;
use App\Finanzas\Enum\FinOrigenCobro;

/**
 * Los pagos de los expedientes de turismo vistos como caja, junto a los de alojamiento.
 *
 * Traduce `CotizacionPago` al vocabulario de Finanzas; Finanzas no sabe qué es un expediente.
 * Sin los anulados: la caja cuenta el dinero que entró.
 *
 * @see \App\Pms\Finanzas\PmsPagoMovimientoProvider, el modelo.
 */
final readonly class CotizacionPagoMovimientoProvider implements FinMovimientoProviderInterface
{
    public function __construct(private CotizacionPagoRepository $pagos)
    {
    }

    public function soporta(): FinOrigenCobro
    {
        return FinOrigenCobro::COTIZACION;
    }

    public function buscar(FinMovimientoFiltro $filtro): array
    {
        return array_map($this->aMovimiento(...), $this->pagos->buscarParaCaja($filtro));
    }

    public function mediosDisponibles(): array
    {
        return array_map(
            static fn (CotizacionMedioPagoEnum $medio): array => ['value' => $medio->value, 'label' => $medio->label()],
            CotizacionMedioPagoEnum::cases(),
        );
    }

    private function aMovimiento(CotizacionPago $pago): FinMovimientoDto
    {
        $file = $pago->getFile();

        $partes = array_filter([
            $file?->getNombreGrupo() ?: $file?->getPasajeroPrincipal(),
            $pago->getPasajeroNombre(),
            $pago->getPropuesta() !== null ? sprintf('propuesta %d', $pago->getPropuesta()) : null,
        ]);

        return new FinMovimientoDto(
            id: (string) $pago->getId(),
            fecha: $pago->getFechaPago()?->format('Y-m-d') ?? '',
            medioCodigo: $pago->getMedio()->value,
            medioEtiqueta: $pago->getMedio()->label(),
            monto: $pago->getMonto(),
            moneda: $pago->getMoneda(),
            tipoCambio: $pago->getTipoCambio(),
            referencia: $pago->getReferencia(),
            origenTipo: FinOrigenCobro::COTIZACION->value,
            origenId: (string) $file?->getId(),
            origenReferencia: $file?->getLocalizador(),
            origenDescripcion: $partes === [] ? '—' : mb_substr(implode(' · ', $partes), 0, 160),
            cobradorNombre: $pago->getRegistradoPorNombre(),
            esAutomatico: $pago->getEnlacePagoId() !== null,
        );
    }
}
