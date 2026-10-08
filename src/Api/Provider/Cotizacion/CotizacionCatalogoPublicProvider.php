<?php

declare(strict_types=1);

namespace App\Api\Provider\Cotizacion;

use App\Dto\Lee;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Metadata\Operation;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCatalogo;
use App\Cotizacion\Service\TourTarjetaResolver;
use Doctrine\ORM\EntityManagerInterface;
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


        // ── 1. Cards para la portada: las arma `TourTarjetaResolver::tarjetas()` ──────
        // (un query escalar; portada y precio oculto ya resueltos allí, que es la fuente única
        // también para la web pública).
        $tarjetas = $this->tarjetas->tarjetas($catalogo, $previsualiza);

        // Sin ningún tour público vigente, el catálogo no es visible
        if ($tarjetas === []) {
            return null;
        }

        // 🔥 **El catálogo también deja pasar al operador, y no lo decía.** Previsualizar un tour
        // sin publicar es útil y deliberado; que no se distinga de uno vivo, no. Ver
        // `CotizacionCatalogo::$saltosDeOperador` y el cartel `AvisoVistaDeOperador` de `pax`.
        if ($previsualiza) {
            $hayBorradores = array_filter($tarjetas, static fn (array $t): bool => !$t['publicado']);
            $catalogo->setSaltosDeOperador($hayBorradores !== [] ? ['sin_publicar'] : []);
        }

        $catalogo->setToursParaCliente(array_map(static fn (array $t): array => [
            'propuesta'     => $t['propuesta'],
            // Cuál de ellos, no sólo que hay alguno: con varios tours en la parrilla, el
            // cartel de arriba no basta para saber cuál se puede enseñar. Nulo para el
            // cliente, que ni siquiera consulta los no publicados.
            'sinPublicar'   => $previsualiza ? !$t['publicado'] : null,
            'estado'        => $t['estado'],
            'numPax'        => $t['numPax'],
            'titulo'        => $t['titulo'],         // I18nContent[] (texto)
            'resumen'       => $t['resumen'],        // I18nContent[] (HTML)
            'idiomaCliente' => $t['idiomaCliente'],
            'monedaGlobal'  => $t['monedaGlobal'],
            'precioOculto'  => $t['precioOculto'],
            'orden'         => $t['orden'],
            // El «desde» EFECTIVO: override si lo hay, si no el calculado por pasajero
            // (`TourTarjetaResolver::preciosDesdeEfectivos()`). El financiero real no se expone.
            'preciosDesde'  => $t['preciosDesde'],
            'precioDesdeOrigen' => $t['precioDesdeOrigen'],
            // Base de pasajeros del precio, cuando depende del tamaño del grupo (null si no).
            'paxBaseGrupo'  => $t['paxBaseGrupo'],
            'imagenPortada' => $t['imagenPortada'],
            'numDias'       => $t['numDias'],
        ], $tarjetas));

        // ── 2. Detalle: cargar SOLO el tour solicitado ────────────────────────
        if (isset($uriVariables['propuesta'])) {
            // ⚠️ `publicado` en la CONSULTA, no sólo en la comprobación: un tour tiene varias
            // filas con el mismo número —sus históricos— y `findOneBy` a secas puede entregar
            // cualquiera. Mismo motivo que en el provider del expediente.
            $cotizacion = $this->em->getRepository(Cotizacion::class)->findOneBy([
                'catalogo' => $catalogo,
                'propuesta' => Lee::entero($uriVariables['propuesta']) ?? 0,
                ...($previsualiza ? [] : ['publicado' => true]),
            ]);

            if (!$cotizacion || !($previsualiza || $cotizacion->isPublicado())) {
                return null; // tour inexistente o no publicado
            }

            // En el detalle el veredicto es sobre ESTE tour, no sobre la parrilla: da igual que
            // los demás estén publicados si el que tienes abierto no lo está.
            if ($previsualiza) {
                $catalogo->setSaltosDeOperador($cotizacion->isPublicado() ? [] : ['sin_publicar']);
            }

            $catalogo->setCotizacionParaCliente($cotizacion);
        }

        return $catalogo;
    }
}
