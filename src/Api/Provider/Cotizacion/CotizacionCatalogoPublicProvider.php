<?php

declare(strict_types=1);

namespace App\Api\Provider\Cotizacion;

use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Metadata\Operation;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCatalogo;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Cotizacion\Service\TourTarjetaResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Provider público del catálogo de tours por localizador.
 *
 * - GET .../{localizador}            → PORTADA: Catálogo + cards escalares de
 *                                      todos los tours públicos vigentes.
 * - GET .../{localizador}/{version}  → DETALLE: lo anterior + la cotización
 *                                      completa de ese tour.
 *
 * Mismo patrón de rendimiento que CotizacionFilePublicProvider: las cards
 * salen de UN query escalar y el detalle de UN findOneBy; la colección
 * $catalogo->getCotizaciones() nunca se hidrata.
 *
 * Los tours usan fechas base nominales, así que aquí no se expone fecha de
 * inicio: se expone numDias (span del itinerario) para mostrar "X días".
 */
final class CotizacionCatalogoPublicProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TourTarjetaResolver $tarjetas,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?CotizacionCatalogo
    {
        $catalogo = $this->em->getRepository(CotizacionCatalogo::class)
            ->findOneBy(['localizador' => $uriVariables['localizador'] ?? null]);

        if (!$catalogo || !$catalogo->isActivo()) {
            return null; // 404 uniforme
        }

        $estadosPublicos = array_map(
            fn(CotizacionEstadoEnum $e) => $e->value,
            array_filter(CotizacionEstadoEnum::cases(), fn(CotizacionEstadoEnum $e) => $e->esPublico())
        );

        // ── 1. Cards para la portada: un solo query escalar ──────────────────
        $filas = $this->em->createQuery(<<<'DQL'
            SELECT c.id, c.imagenPortada, c.version, c.estado, c.numPax, c.titulo, c.resumen, c.idiomaCliente,
                   c.monedaGlobal, c.precioOculto, c.totalVenta,
                   c.preciosDesde, c.orden,
                   MIN(s.fechaInicioAbsoluta) AS fechaMin, MAX(s.fechaInicioAbsoluta) AS fechaMax
            FROM App\Cotizacion\Entity\Cotizacion c
            LEFT JOIN c.cotservicios s
            WHERE c.catalogo = :catalogo
              AND c.estado IN (:publicos)
            GROUP BY c.id
            ORDER BY c.orden ASC, c.version ASC
        DQL)
            ->setParameter('catalogo', $catalogo->getId(), UuidType::NAME)
            ->setParameter('publicos', $estadosPublicos)
            ->getArrayResult();

        // Sin ningún tour público vigente, el catálogo no es visible
        if ($filas === []) {
            return null;
        }

        // Portadas automáticas: imágenes de los segmentos en orden de itinerario
        $portadas = $this->tarjetas->portadasDerivadas(array_column($filas, 'id'));

        $catalogo->setToursParaCliente(array_map(static function (array $f) use ($portadas): array {
            $oculto = (bool) $f['precioOculto'];
            $estado = $f['estado'] instanceof CotizacionEstadoEnum ? $f['estado']->value : $f['estado'];

            return [
                'version'           => $f['version'],
                'estado'            => $estado,
                'numPax'            => $f['numPax'],
                'titulo'            => $f['titulo'] ?? [],         // I18nContent[] (texto)
                'resumen'           => $f['resumen'] ?? [],        // I18nContent[] (HTML)
                'idiomaCliente'     => $f['idiomaCliente'],
                'monedaGlobal'      => $f['monedaGlobal'],
                'precioOculto'      => $oculto,
                'orden'             => $f['orden'],
                // Rangos comerciales de exhibición ("Desde X" por perfil); el financiero real no se expone
                'preciosDesde'      => $oculto ? [] : ($f['preciosDesde'] ?? []),
                // Override editorial primero; si no, la derivada del itinerario
                'imagenPortada'     => $f['imagenPortada'] ?? $portadas[TourTarjetaResolver::clave($f['id'])] ?? null,
                'numDias'           => TourTarjetaResolver::numDias($f['fechaMin'], $f['fechaMax']),
            ];
        }, $filas));

        // ── 2. Detalle: cargar SOLO el tour solicitado ────────────────────────
        if (isset($uriVariables['version'])) {
            $cotizacion = $this->em->getRepository(Cotizacion::class)->findOneBy([
                'catalogo' => $catalogo,
                'version'  => (int) $uriVariables['version'],
            ]);

            if (!$cotizacion || !$cotizacion->getEstado()->esPublico()) {
                return null; // tour inexistente o no público
            }

            $catalogo->setCotizacionParaCliente($cotizacion);
        }

        return $catalogo;
    }
}
