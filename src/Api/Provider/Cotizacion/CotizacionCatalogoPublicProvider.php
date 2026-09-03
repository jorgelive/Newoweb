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
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Provider público del catálogo de tours por localizador.
 *
 * - GET .../{localizador}            → PORTADA: Catálogo + cards escalares de
 *                                      todos los tours públicos vigentes.
 * - GET .../{localizador}/{propuesta}  → DETALLE: lo anterior + la cotización
 *                                      completa de ese tour.
 *
 * Mismo patrón de rendimiento que CotizacionFilePublicProvider: las cards
 * salen de UN query escalar y el detalle de UN findOneBy; la colección
 * $catalogo->getCotizaciones() nunca se hidrata.
 *
 * Los tours usan fechas base nominales, así que aquí no se expone fecha de
 * inicio: se expone numDias (span del itinerario) para mostrar "X días".
 *
 * @implements ProviderInterface<CotizacionCatalogo>
 */
final class CotizacionCatalogoPublicProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TourTarjetaResolver $tarjetas,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?CotizacionCatalogo
    {
        $catalogo = $this->em->getRepository(CotizacionCatalogo::class)
            ->findOneBy(['localizador' => $uriVariables['localizador'] ?? null]);

        if (!$catalogo || !$catalogo->isActivo()) {
            return null; // 404 uniforme
        }

        /**
         * ⚠️ **El operador ve lo no publicado; el cliente no.**
         *
         * Permite previsualizar un tour antes de publicarlo, sin tocar su estado. No hace falta
         * enlace especial: `util` y `pax` comparten dominio de cookie y el host de la API está
         * bajo el firewall `main`, que es stateful.
         */
        $previsualiza = $this->security->isGranted('ROLE_USER');


        // ── 1. Cards para la portada: un solo query escalar ──────────────────
        $filas = $this->em->createQuery(<<<'DQL'
            SELECT c.id, c.imagenPortada, c.propuesta, c.estado, c.numPax, c.titulo, c.resumen, c.idiomaCliente,
                   c.monedaGlobal, c.precioOculto, c.totalVenta,
                   c.preciosDesde, c.orden,
                   MIN(s.fechaInicioAbsoluta) AS fechaMin, MAX(s.fechaInicioAbsoluta) AS fechaMax
            FROM App\Cotizacion\Entity\Cotizacion c
            LEFT JOIN c.cotservicios s
            WHERE c.catalogo = :catalogo
              AND (c.publicado = true OR :previsualiza = true)
            GROUP BY c.id
            ORDER BY c.orden ASC, c.propuesta ASC
        DQL)
            ->setParameter('catalogo', $catalogo->getId(), UuidType::NAME)
            ->setParameter('previsualiza', $previsualiza)
            ->getArrayResult();

        // Sin ningún tour público vigente, el catálogo no es visible
        if ($filas === []) {
            return null;
        }

        // Portadas automáticas: imágenes de los segmentos en orden de itinerario
        $portadas = $this->tarjetas->portadasDerivadas(array_column($filas, 'id'));

        $catalogo->setToursParaCliente(array_values(array_map(static function (array $f) use ($portadas): array {
            $oculto = (bool) $f['precioOculto'];
            $estado = $f['estado'] instanceof CotizacionEstadoEnum ? $f['estado']->value : $f['estado'];

            return [
                'propuesta'           => $f['propuesta'],
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
        }, $filas)));

        // ── 2. Detalle: cargar SOLO el tour solicitado ────────────────────────
        if (isset($uriVariables['propuesta'])) {
            // ⚠️ `publicado` en la CONSULTA, no sólo en la comprobación: un tour tiene varias
            // filas con el mismo número —sus históricos— y `findOneBy` a secas puede entregar
            // cualquiera. Mismo motivo que en el provider del expediente.
            $cotizacion = $this->em->getRepository(Cotizacion::class)->findOneBy([
                'catalogo' => $catalogo,
                'propuesta' => (int) $uriVariables['propuesta'],
                ...($previsualiza ? [] : ['publicado' => true]),
            ]);

            if (!$cotizacion || !($previsualiza || $cotizacion->isPublicado())) {
                return null; // tour inexistente o no publicado
            }

            $catalogo->setCotizacionParaCliente($cotizacion);
        }

        return $catalogo;
    }
}
