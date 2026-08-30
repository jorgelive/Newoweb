<?php

declare(strict_types=1);

namespace App\Api\Provider\Cotizacion;

use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Cotizacion\Entity\CotizacionCatalogo;
use App\Cotizacion\Service\TourTarjetaResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decora el Get interno del catálogo (panel `CatalogoDashboard.vue`) para
 * añadir a cada tour los mismos datos de tarjeta que ve el cliente: portada
 * derivada del itinerario y duración en días.
 *
 * Sin esto el panel sólo tendría `imagenPortada`, que es null salvo que alguien
 * haya fijado la portada a mano — el dashboard mostraría marcadores grises para
 * tours que en la vista pública sí lucen foto. La regla no se reimplementa:
 * ambos lados llaman a TourTarjetaResolver.
 *
 * A diferencia del provider público, aquí NO se filtra por estado: el panel
 * lista también borradores y archivados, y todos merecen su portada.
 *
 * @implements ProviderInterface<CotizacionCatalogo>
 */
final class CotizacionCatalogoAdminProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private readonly ItemProvider $itemProvider,
        private readonly TourTarjetaResolver $tarjetas,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?CotizacionCatalogo
    {
        $catalogo = $this->itemProvider->provide($operation, $uriVariables, $context);

        if (!$catalogo instanceof CotizacionCatalogo) {
            return $catalogo;
        }

        $tours = $catalogo->getCotizaciones();
        if ($tours->isEmpty()) {
            return $catalogo;
        }

        $ids       = array_map(static fn($t) => $t->getId(), $tours->toArray());
        $portadas  = $this->tarjetas->portadasDerivadas($ids);
        $dias      = $this->tarjetas->diasPorTour($ids);

        foreach ($tours as $tour) {
            $id = TourTarjetaResolver::clave($tour->getId());
            // El override editorial manda; si no hay, la derivada del itinerario.
            $tour->setImagenTarjeta($tour->getImagenPortada() ?? $portadas[$id] ?? null);
            $tour->setNumDias($dias[$id] ?? null);
        }

        return $catalogo;
    }
}
