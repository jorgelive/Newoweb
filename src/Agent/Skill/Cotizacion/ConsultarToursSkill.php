<?php

declare(strict_types=1);

namespace App\Agent\Skill\Cotizacion;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\EntradaDeSkill;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Service\Agent\CotizacionFrentes;
use App\Cotizacion\Service\ItinerarioCompuesto;
use App\Dominio\Excepcion\DominioNoDisponible;
use App\Dto\Lee;
use App\Front\Tours\Dto\CatalogoWeb;
use App\Front\Tours\Dto\PrecioDesdeWeb;
use App\Front\Tours\Dto\TourTarjetaWeb;
use App\Front\Tours\Service\CatalogoWebLector;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Los tours que vendemos, tal como están publicados en openperu.pe.
 *
 * ── El fallo que cierra (11/10/2026) ────────────────────────────────────────
 * A «¿organizan transporte entre Cusco y Machu Picchu?» el agente contestó que no, con el tour
 * «Machu Picchu: Full day en tren turístico» publicado. No tenía ninguna herramienta que mirase
 * el catálogo de tours, y negó con lo único que encontró. Ver {@see CotizacionFrentes}.
 *
 * ── Qué puede contar lo decide el CATÁLOGO, no esta skill ───────────────────
 * Lee {@see CatalogoWebLector::paraElAgente()}: lo publicado en la web y, además, lo marcado «lo
 * ofrece el agente» (`CotizacionCatalogo::loOfreceElAgente()`); de cada uno, sólo sus tours
 * publicados, con el «desde» ya resuelto (override o calculado,
 * `TourTarjetaResolver::preciosDesdeEfectivos()`) y el precio oculto respetado. Las mismas
 * tarjetas que la web: el agente no da un precio distinto del de la ficha. Un catálogo hecho
 * para un cliente y compartido por enlace no sale: contarlo a cualquiera sería publicarlo.
 *
 * El enlace va a la ficha de openperu.pe si el catálogo está en la web, y a su página de pax si
 * sólo lo ofrece el agente (los traslados, 13/10/2026: sin fotos, en la portada eran un hueco).
 *
 * ── El detalle es el ITINERARIO DEL CLIENTE ─────────────────────────────────
 * Con `tour`, trae los días por {@see ItinerarioCompuesto} —la misma regla que pax y que el PDF
 * del itinerario, serializada con los grupos públicos— y de cada bloque sólo su hora y su título
 * en el idioma pedido. Es lo que contesta «¿incluye el tren?, ¿y la entrada?», y no da nada que
 * el cliente no vería en su propia guía.
 *
 * ── Enlaces: los decide el canal ────────────────────────────────────────────
 * En una consulta de OTA sin reserva no viaja ningún enlace propio
 * (`RestriccionCanal::variablesBloqueadas()`, la misma lista que tapa `tours_catalog_url`). Los
 * tours sí se cuentan: promocionarlos no está prohibido, sacar la conversación fuera sí.
 */
final readonly class ConsultarToursSkill implements SkillInterface, SkillDominioInterface
{
    /** Lo que cabe de una descripción sin convertir la respuesta en un folleto. */
    private const int MAX_DESCRIPCION = 400;

    /** La variable de canal que tapa el enlace al catálogo: se reutiliza su política tal cual. */
    private const string VARIABLE_ENLACE = 'tours_catalog_url';

    public function __construct(
        private CatalogoWebLector $catalogos,
        private ItinerarioCompuesto $itinerario,
        private EntityManagerInterface $em,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
        #[Autowire('%pax_host_url%')]
        private string $hostPax = '',
    ) {
    }

    public function nombre(): string
    {
        return 'consultar_tours';
    }

    public function dominios(): array
    {
        return [CotizacionFrentes::NEGOCIO];
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            // En POSITIVO (17/10/2026): «NUNCA digas que no lo organizamos» y «no digas que no» eran
            // supresión, que el modelo incumple. Se le dice qué hacer y de dónde sale la respuesta.
            descripcion: 'Los TOURS, excursiones y viajes que vendemos (OpenPeru Travel), tal como '
                . 'están publicados en openperu.pe: cada uno con sus días, su precio «desde» por '
                . 'persona y su enlace. Cuando pregunten por un tour, una excursión, cómo ir a un '
                . 'sitio turístico (Machu Picchu, Valle Sagrado, Montaña de Colores…), un traslado '
                . 'o tren hasta allí, o «qué se puede hacer», la respuesta sale de aquí: llámala '
                . 'primero y contesta con lo que devuelva. Con «buscar» filtras por palabras; con '
                . '«tour» (el id que devuelve) traes su itinerario hora a hora, que es lo que dice '
                . 'qué incluye. Lo que no esté en el catálogo se arma a medida si es en el Perú o el '
                . 'Caribe, y cualquier otro destino lo valora el equipo: en los dos casos, anótalo '
                . 'con anotar_pedido_cotizacion. Es de tours y viajes; las casitas tienen sus '
                . 'propias herramientas.',
            parametros: [
                SkillParameter::texto('buscar', 'Palabras de lo que pide, como lo dijo: «machu '
                    . 'picchu», «montaña de colores», «tren». Vacío = el catálogo entero.', false),
                SkillParameter::texto('tour', 'El «tour» (id) de un resultado anterior, para traer qué '
                    . 'incluye y su itinerario. Sólo uno que te haya devuelto esta herramienta.', false),
                SkillParameter::texto('idioma', '«es» o «en»: el idioma de los títulos y del '
                    . 'enlace. Usa «en» si te escribe en inglés o en otro idioma que no sea español.', false),
            ],
        );
    }

    /** El propio cliente incluido: es quien pregunta. El equipo, para contestar por él. */
    public function rolesRequeridos(): array
    {
        return [Roles::HUESPED, Roles::PROSPECTO, Roles::MENSAJES_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $e = new EntradaDeSkill($entrada);
        $idioma = strtolower(trim($e->texto('idioma'))) === 'en' ? 'en' : 'es';
        $conEnlaces = !in_array(self::VARIABLE_ENLACE, $actor->restriccion()->variablesBloqueadas(), true);
        $catalogos = $this->catalogos->paraElAgente($idioma);

        if ($catalogos === []) {
            return SkillResult::ok([
                'catalogos' => [],
                'aviso' => 'Ahora mismo no hay tours publicados. NO digas que no hacemos tours: '
                    . 'pide fechas y cuántos son y anótalo con anotar_pedido_cotizacion para que '
                    . 'el equipo le prepare una propuesta.',
            ]);
        }

        $tour = trim($e->texto('tour'));

        if ($tour !== '') {
            return $this->detalle($catalogos, $tour, $idioma, $conEnlaces);
        }

        return $this->listado($catalogos, trim($e->texto('buscar')), $idioma, $conEnlaces);
    }

    /**
     * El catálogo, entero o filtrado por las palabras de la pregunta.
     *
     * Si nada coincide se devuelve TODO con un aviso, nunca una lista vacía: «no tenemos Machu
     * Picchu» por una palabra mal escrita es justo el fallo que esto viene a cerrar.
     *
     * @param list<CatalogoWeb> $catalogos
     */
    private function listado(array $catalogos, string $buscar, string $idioma, bool $conEnlaces): SkillResult
    {
        $palabras = self::palabras($buscar);
        $puntuados = [];
        $mejor = 0;

        foreach ($catalogos as $catalogo) {
            $delCatalogo = self::normalizar($catalogo->titulo . ' ' . self::plano($catalogo->descripcionHtml));

            foreach ($catalogo->tours as $t) {
                $texto = $delCatalogo . ' ' . self::normalizar($t->titulo . ' ' . ($t->resumenPlano ?? ''));
                $puntos = count(array_filter($palabras, static fn (string $p): bool => str_contains($texto, $p)));
                $puntuados[] = [$catalogo, $t, $puntos];
                $mejor = max($mejor, $puntos);
            }
        }

        $filtrado = $palabras !== [] && $mejor > 0;
        $salida = [];

        foreach ($catalogos as $catalogo) {
            $tours = [];

            foreach ($puntuados as [$c, $t, $puntos]) {
                if ($c === $catalogo && (!$filtrado || $puntos === $mejor)) {
                    $tours[] = $this->tarjeta($catalogo, $t, $idioma, $conEnlaces);
                }
            }

            if ($tours === []) {
                continue;
            }

            $salida[] = array_filter([
                'catalogo' => $catalogo->titulo,
                'marca' => $catalogo->marca->nombre,
                'descripcion' => self::recortar(self::plano($catalogo->descripcionHtml)),
                'enlace' => $conEnlaces ? $this->enlaceDelCatalogo($catalogo, $idioma) : null,
                'tours' => $tours,
            ], static fn (mixed $v): bool => $v !== null && $v !== '');
        }

        $avisos = ['Precios «desde», por persona y referenciales: fecha, disponibilidad y precio '
            . 'final los confirma el equipo. Si quiere reservar o da fechas, anótalo con '
            . 'anotar_pedido_cotizacion.'];

        if ($palabras !== [] && !$filtrado) {
            array_unshift($avisos, sprintf('Ningún tour publicado coincide con «%s»: este es el '
                . 'catálogo entero. Si lo que pide no está, NO digas que no lo hacemos: en el Perú '
                . 'o el Caribe se arma a medida; otro destino, sin prometerlo, lo valora el equipo. '
                . 'En los dos casos, anótalo con anotar_pedido_cotizacion.', $buscar));
        }

        if (!$conEnlaces) {
            $avisos[] = 'Por este canal no se pueden pasar enlaces: cuéntalos con palabras.';
        }

        return SkillResult::ok(['catalogos' => $salida, 'aviso' => implode(' ', $avisos)]);
    }

    /**
     * Un tour con su itinerario.
     *
     * El id se valida contra lo PUBLICADO antes de tocar la base: lo escribe el modelo, y un id
     * inventado —o el de un tour de expediente, con el nombre de un cliente dentro— no puede
     * acabar leído por la puerta pública.
     *
     * @param list<CatalogoWeb> $catalogos
     */
    private function detalle(array $catalogos, string $tourId, string $idioma, bool $conEnlaces): SkillResult
    {
        foreach ($catalogos as $catalogo) {
            foreach ($catalogo->tours as $t) {
                if (!self::esEsteTour($catalogo, $t, $tourId)) {
                    continue;
                }

                $datos = $this->tarjeta($catalogo, $t, $idioma, $conEnlaces) + ['catalogo' => $catalogo->titulo];
                $cotizacion = Uuid::isValid($t->id) ? $this->em->find(Cotizacion::class, Uuid::fromString($t->id)) : null;
                $itinerario = $cotizacion instanceof Cotizacion ? $this->itinerarioDe($cotizacion, $idioma) : null;
                $datos += $cotizacion instanceof Cotizacion ? self::inclusionesDe($cotizacion, $idioma) : [];

                if ($itinerario === null) {
                    $datos['aviso'] = 'El itinerario no se pudo leer ahora mismo. Cuenta lo que '
                        . 'tienes y, si pregunta qué incluye, dile que se lo confirma el equipo.';
                } else {
                    $datos['itinerario'] = $itinerario;
                    $datos['aviso'] = 'Lo que incluye es lo de «incluye» y el itinerario: lo que no '
                        . 'aparece, no lo prometas (pregúntalo al equipo). «opcional» se paga aparte. '
                        . 'Las horas son de referencia.'
                        . ($conEnlaces ? ' La ficha completa con fotos está en «enlace».' : '');
                }

                return SkillResult::ok($datos);
            }
        }

        return SkillResult::error('Ese «tour» no está entre los publicados. Llama otra vez sin '
            . '«tour» (o con «buscar») y usa uno de los ids que devuelva.');
    }

    /**
     * ¿Se refiere a este tour? Por su id, o por su enlace: el slug, el final de la URL
     * («1-machu-picchu-full-day…») o la URL entera.
     *
     * Lo segundo no es cortesía: en la primera simulación (11/10/2026) el modelo pidió el detalle
     * con el trozo final de la URL que acababa de recibir (y en la segunda, con el slug sin el
     * número), falló y tuvo que dar otra vuelta. El
     * enlace identifica el tour igual que el id —catálogo y propuesta—, y se sigue comparando
     * contra lo publicado, así que no abre nada que la web no enseñe.
     */
    private static function esEsteTour(CatalogoWeb $catalogo, TourTarjetaWeb $t, string $pedido): bool
    {
        if ($t->id === $pedido) {
            return true;
        }

        $tramo = $t->propuesta . '-' . $t->slug;
        $pedido = rtrim($pedido, '/');

        // El slug a secas también lo probó, en la segunda: dos tours de un catálogo con el mismo
        // título ya se pisarían en la web, así que no distingue menos que ella.
        return $pedido === $tramo
            || $pedido === $t->slug
            || ($catalogo->slug !== '' && str_ends_with($pedido, '/' . $catalogo->slug . '/' . $tramo))
            || str_ends_with($pedido, '/catalogo/' . $catalogo->localizador . '/p/' . $t->propuesta);
    }

    /**
     * Lo que se cuenta de un tour en una lista.
     *
     * @return array<string, mixed>
     */
    private function tarjeta(CatalogoWeb $catalogo, TourTarjetaWeb $t, string $idioma, bool $conEnlaces): array
    {
        $desde = array_map(
            static fn (PrecioDesdeWeb $p): string => ($p->etiqueta !== null && $p->etiqueta !== '' ? $p->etiqueta . ': ' : '') . $p->formateado(),
            $t->precios,
        );

        // Totales a la vista (`TourTarjetaResolver::totalDelGrupo()`): lo que se anuncia es lo que
        // paga el grupo. Mismo criterio que la tarjeta de la web, para no dar dos cifras distintas.
        $precioEs = null;
        if ($t->totalGrupo !== null && $t->paxTotal !== null && $desde !== []) {
            $desde = [sprintf('%s en total para %d personas', $t->totalGrupo->formateado(), $t->paxTotal)];
            $precioEs = sprintf('total del grupo de %d (sale a %s por persona); con otro número de personas '
                . 'el precio cambia: pregúntale cuántos son', $t->paxTotal, $t->precioPrincipal()?->formateado() ?? '');
        }

        return array_filter([
            'tour' => $t->id,
            'titulo' => $t->titulo,
            'dias' => $t->numDias,
            'desde' => $desde !== [] ? $desde : 'precio a consultar',
            // Sin la base, «US$ 1 599 por persona» de un viaje de promoción calculado para 60 se
            // lee como el precio para una pareja. Es la misma nota que lleva la ficha web.
            'precio_es' => $desde === [] ? null : $precioEs ?? ($t->paxBaseGrupo !== null
                ? sprintf(
                    $t->precioPersonalizado
                        ? 'por persona, para un grupo de %d: con otro tamaño varía'
                        : 'por persona, calculado para un grupo de %d: con otro tamaño varía',
                    $t->paxBaseGrupo,
                )
                : 'por persona'),
            'resumen' => self::recortar($t->resumenPlano ?? ''),
            'enlace' => $conEnlaces ? $this->enlaceDelTour($catalogo, $t, $idioma) : null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * Qué incluye, qué no, qué es opcional y qué va de cortesía: sólo los NOMBRES.
     *
     * Sale de `clasificacionFinancieraCliente.inclusiones`, el mismo bloque que pinta «¿Qué
     * incluye?» en pax (`paxCotizacionStore.inclusiones`). Es el resumen del CLIENTE —el editor lo
     * guarda ya sin costes—, pero aun así no se copian sus tarifas: el agente cuenta el «desde» de
     * la ficha y nada más, para no dar dos precios distintos del mismo tour.
     *
     * @return array<string, list<string>>
     */
    private static function inclusionesDe(Cotizacion $cotizacion, string $idioma): array
    {
        $secciones = ['incluidos' => 'incluye', 'noIncluidos' => 'no_incluye', 'opcionales' => 'opcional', 'cortesias' => 'cortesia'];
        $salida = [];

        foreach (Lee::listaDeMapas(Lee::mapa($cotizacion->getClasificacionFinancieraCliente())['inclusiones'] ?? null) as $servicio) {
            foreach ($secciones as $clave => $nombre) {
                foreach (Lee::listaDeMapas($servicio[$clave] ?? null) as $linea) {
                    $texto = self::tituloEn($linea['nombre'] ?? null, $idioma);

                    if ($texto !== null) {
                        $salida[$nombre][$texto] = true;
                    }
                }
            }
        }

        return array_map(static fn (array $nombres): array => array_keys($nombres), $salida);
    }

    /**
     * Los días del tour como líneas «hora · título».
     *
     * `null` si el cálculo compartido no está disponible: el tour se cuenta igual, sin itinerario.
     *
     * @return list<array{dia: int, actividades: list<string>}>|null
     */
    private function itinerarioDe(Cotizacion $cotizacion, string $idioma): ?array
    {
        try {
            $dias = $this->itinerario->dias($cotizacion);
        } catch (DominioNoDisponible $e) {
            $this->logger->warning('consultar_tours: itinerario no disponible para {tour}: {error}', [
                'tour' => (string) $cotizacion->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $salida = [];

        foreach ($dias as $dia) {
            $actividades = [];

            foreach ($dia['bloques'] as $bloque) {
                // Una noche de hotel repetida en cada día no dice nada nuevo: basta la primera.
                if (($bloque['esRepeticion'] ?? false) === true) {
                    continue;
                }

                $titulo = self::tituloEn(Lee::mapa($bloque['segmento'] ?? null)['tituloSnapshot'] ?? null, $idioma);

                if ($titulo === null) {
                    continue;
                }

                // La hora del servicio completo manda sobre la del bloque, como en el PDF.
                $hora = Lee::texto($bloque['horaServicioInicio'] ?? null) ?? Lee::texto($bloque['horaInicio'] ?? null);
                $actividades[] = $hora !== null ? $hora . ' · ' . $titulo : $titulo;
            }

            if ($actividades !== []) {
                $salida[] = ['dia' => $dia['numeroDia'], 'actividades' => $actividades];
            }
        }

        return $salida;
    }

    /** El título i18n en el idioma pedido; si no está, el primero que haya. */
    private static function tituloEn(mixed $titulos, string $idioma): ?string
    {
        $primero = null;

        foreach (Lee::listaDeMapas($titulos) as $fila) {
            $contenido = trim(Lee::texto($fila['content'] ?? null) ?? '');

            if ($contenido === '') {
                continue;
            }

            if (Lee::texto($fila['language'] ?? null) === $idioma) {
                return $contenido;
            }

            $primero ??= $contenido;
        }

        return $primero;
    }

    /** La sección del catálogo en la web o, si sólo lo ofrece el agente, su escaparate en pax. */
    private function enlaceDelCatalogo(CatalogoWeb $catalogo, string $idioma): string
    {
        return $catalogo->enLaWeb
            ? $this->urls->generate('front_catalogo', ['_locale' => $idioma, 'catalogo' => $catalogo->slug], UrlGeneratorInterface::ABSOLUTE_URL)
            : rtrim($this->hostPax, '/') . '/catalogo/' . $catalogo->localizador;
    }

    /**
     * La ficha del tour en la web o su página en pax. Las rutas de pax son las de su router
     * (`/catalogo/:localizador/p/:propuesta`): no hay generador de URLs para la otra app.
     */
    private function enlaceDelTour(CatalogoWeb $catalogo, TourTarjetaWeb $t, string $idioma): string
    {
        return $catalogo->enLaWeb
            ? $this->urls->generate('front_tour', [
                '_locale' => $idioma,
                'catalogo' => $catalogo->slug,
                'tour' => $t->propuesta . '-' . $t->slug,
            ], UrlGeneratorInterface::ABSOLUTE_URL)
            : rtrim($this->hostPax, '/') . '/catalogo/' . $catalogo->localizador . '/p/' . $t->propuesta;
    }

    /**
     * Las palabras con las que se busca: sin tildes, en minúsculas y sin las que no distinguen
     * nada («de», «tour», «hacen»). Una palabra vacía casaría con todo.
     *
     * @return list<string>
     */
    private static function palabras(string $texto): array
    {
        $vacias = ['para', 'como', 'desde', 'hasta', 'entre', 'tour', 'tours', 'excursion', 'excursiones',
            'hacen', 'tienen', 'organizan', 'quisiera', 'saber', 'servicio', 'servicios', 'the', 'and', 'trip'];

        $palabras = preg_split('/[^a-z0-9]+/', self::normalizar($texto)) ?: [];

        return array_values(array_unique(array_filter(
            $palabras,
            static fn (string $p): bool => mb_strlen($p) >= 3 && !in_array($p, $vacias, true),
        )));
    }

    private static function normalizar(string $texto): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $texto);

        return is_string($ascii) ? $ascii : mb_strtolower($texto);
    }

    private static function plano(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    private static function recortar(string $texto): string
    {
        return mb_strlen($texto) > self::MAX_DESCRIPCION
            ? rtrim(mb_substr($texto, 0, self::MAX_DESCRIPCION - 1)) . '…'
            : $texto;
    }
}
