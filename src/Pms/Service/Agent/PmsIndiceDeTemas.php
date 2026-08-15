<?php

declare(strict_types=1);

namespace App\Pms\Service\Agent;

use App\Agent\Access\ActorInterface;
use App\Message\Contract\IndiceDeTemasInterface;
use App\Pms\Entity\PmsGuia;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Enum\PmsGuiaVisibilidad;
use App\Message\Contract\TemaQueCubre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * El índice GLOBAL de temas de la guía, para que el triaje elija el tema en la misma llamada.
 *
 * ### Por qué es global y no por casita
 *
 * Los ítems de la guía se comparten entre casitas (relación N-a-N): «Wifi» o «Reglas» son UN
 * ítem con UN uuid presente en todas, y sólo «Ducha» o «Puerta» tienen variantes por casita.
 * Un índice por casita obligaría a un bloque de caché distinto por unidad — siete cachés que
 * calentar, y el de la casita poco visitada caducando antes de acertar ninguno. El índice
 * global es **idéntico para todas las conversaciones**, así que viaja pegado al bloque
 * cacheado de las reglas del triaje: un solo prefijo que todo el parque comparte y renueva.
 * Lo único por huésped es una línea volátil («su casita: …») y la lista de uuids con la que
 * se valida lo que el modelo eligió.
 *
 * ### Qué NO es
 *
 * No es contenido: sólo etiquetas y uuids. El contenido lo sigue sirviendo
 * `ConsultarGuiaSkill`, con su poda de acceso intacta — un tema_id equivocado o de otra
 * casita no enseña nada: la skill no lo encuentra en el árbol podado y devuelve el catálogo,
 * que es el comportamiento de siempre.
 *
 * ### 🚧 Umbral de crecimiento
 *
 * El bloque crece O(casitas × ítems propios): con 7 casitas son ~40 líneas (~850 tokens,
 * cacheados). Si el parque crece hasta que esto pese miles de tokens, la compresión es
 * sustituir el uuid por un ordinal (`17 — Ducha — casa 3`) y mapear ordinal→uuid aquí al
 * interpretar: el mapa se reconstruye en la misma petición con la misma consulta, así que no
 * puede desincronizarse del prompt.
 *
 * Ver docs/Mensajeria.md §13.8.
 */
final readonly class PmsIndiceDeTemas implements IndiceDeTemasInterface
{
    private const string CONTEXTO_RESERVA = 'pms_reserva';

    public function supports(?string $contextType): bool
    {
        return self::CONTEXTO_RESERVA === $contextType;
    }

    /**
     * Sin `consultar_guia` el índice sobra: sería ofrecerle elegir un tema que luego no puede
     * consultar. Antes esta comprobación estaba en `Triaje` con el nombre escrito a mano.
     */
    public function skillQueLoHabilita(): string
    {
        return 'consultar_guia';
    }

    public function bloqueParaElPrompt(): string
    {
        return $this->construir()['bloque'];
    }

    /**
     * Los temas de las casitas del huésped. El cruce con `porUnidad` vivía en `Triaje`, que
     * para hacerlo tenía que conocer la forma del mapa y hablar de unidades.
     *
     * @return list<string>
     */
    public function temasPermitidos(ActorInterface $actor): array
    {
        $guia = $this->construir();
        $permitidos = [];

        foreach (array_keys($this->unidadesDelActor($actor)) as $unidadId) {
            $permitidos = [...$permitidos, ...($guia['porUnidad'][$unidadId] ?? [])];
        }

        return array_values(array_unique($permitidos));
    }

    /** La casita, que es lo único del índice que cambia por conversación. */
    public function lineaVolatil(ActorInterface $actor): string
    {
        $unidades = $this->unidadesDelActor($actor);

        return $unidades === [] ? '' : 'Su casita: ' . implode(' y ', $unidades) . '.';
    }

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Los temas de la guía que ya responden a estas palabras.
     *
     * La comparación es **tosca a propósito**: palabras de cuatro letras o más, sin acentos,
     * contra los términos y el título del tema. Aquí no se busca acertar siempre, sino avisar de
     * lo evidente antes de crear un duplicado — el mismo criterio y el mismo motivo que
     * {@see \App\Agent\Service\ConocimientoGenerico::candidatosPara()}.
     *
     * Se mira en `agenteTerminos` y en el título, no en el cuerpo: el cuerpo menciona de pasada
     * cosas que el tema no responde —«lavandería» aparece dentro de «Reglas»— y eso daría avisos
     * de más que acabarían ignorándose.
     *
     * ⚠️ Se agrupan por nombre visible: los siete ítems de ducha son **un** tema para quien está
     * decidiendo si crear una entrada, no siete avisos iguales.
     *
     * @return list<TemaQueCubre>
     */
    public function temasQueCubren(string $texto): array
    {
        $palabras = $this->palabrasDe($texto);

        if ($palabras === []) {
            return [];
        }

        $encontrados = [];

        /** @var list<PmsGuiaItem> $items */
        $items = $this->em->getRepository(PmsGuiaItem::class)->findAll();

        foreach ($items as $item) {
            $suyas = $this->palabrasDe(
                (string) $item->getAgenteTerminos() . ' ' . $this->titulos($item)
            );

            // ⚠️ DOS COINCIDENCIAS, NO UNA. Con una sola, «estacionamiento, cochera, dónde dejo
            // el auto» sacaba también «Llaves» y «Ubicación» —comparten «donde», «dejo»—, y un
            // aviso que salta siempre se acaba ignorando, que es peor que no tenerlo. Es la
            // misma lección que MAX_PALABRAS_BUSQUEDA en ConsultarGuiaSkill: cuantas más
            // palabras se cruzan, más fácil es que alguna case por casualidad.
            if (count(array_intersect($palabras, $suyas)) < self::MINIMO_COINCIDENCIAS) {
                continue;
            }

            // «Ducha (casa 3)» → «Ducha»: siete fichas del mismo tema son un aviso, no siete.
            $nombre = trim(preg_replace('/\s*\((?:casa|general)[^)]*\)\s*$/i', '', $item->getNombreInterno()) ?? '');

            // Basta con que UNA de las fichas del tema sea específica para que el tema lo sea:
            // «Ducha» tiene siete, y aunque alguna se pareciese a la genérica, el huésped tiene
            // que recibir la de SU casita.
            $porQue = $this->porQueEsMejor($item);

            if (!isset($encontrados[$nombre]) || ($porQue !== '' && $encontrados[$nombre] === '')) {
                $encontrados[$nombre] = $porQue;
            }
        }

        $temas = [];

        foreach ($encontrados as $nombre => $porQue) {
            $temas[] = new TemaQueCubre((string) $nombre, $porQue !== '', $porQue);
        }

        return $temas;
    }

    /**
     * Qué hace que la ficha de la guía sea MEJOR que una genérica, o cadena vacía si no lo es.
     *
     * Son las tres cosas que la guía puede hacer y el conocimiento no. Si no se da ninguna, el
     * tema es general y de texto plano: entonces las dos versiones dicen lo mismo y excluir al
     * huésped no le protege de nada —sólo le quita la red si la guía no llega a servirle—.
     */
    private function porQueEsMejor(PmsGuiaItem $item): string
    {
        // ⚠️ Es un CONSEJO, no un veredicto. Mira tres señales objetivas y se le escapa una: un
        // ítem que recibe datos de la estancia por la respuesta de la skill —«usa hora_check_in,
        // que viene en esta misma respuesta»— y no por un `{{ placeholder }}`. «Early check in»
        // es ese caso: como ficha es general, pero la parte de horarios sí es específica.
        //
        // No se afina más a propósito: el ítem cubre varios asuntos a la vez —horarios Y
        // equipaje— y un veredicto por TEMA no puede decir que uno es específico y el otro no.
        // Quien decide sigue siendo quien escribe la ficha; esto sólo le pone delante en qué
        // fijarse.
        // Una ficha por casita: el grifo de la ducha no es el mismo en todas.
        if (preg_match('/\(casa\s/i', $item->getNombreInterno()) === 1) {
            return 'tiene una ficha distinta por casita';
        }

        // Placeholders: la guía los resuelve con los datos de ESA estancia; aquí no se resuelven.
        if (str_contains($this->titulos($item) . ' ' . $this->cuerpoCrudo($item), '{{')) {
            return 'resuelve datos de la estancia (horas, códigos)';
        }

        // Contenido con candado o ventana: la guía sabe cuándo puede darlo y el conocimiento no.
        //
        // ⚠️ SÓLO estas dos. La primera versión miraba «distinto de público», y como 32 de los 51
        // ítems son `cliente` —que sólo quiere decir «se le enseña al cliente», no que tenga
        // ventana—, el veredicto salía «es mejor» en casi todo. Un aviso que siempre dice lo
        // mismo no ayuda a decidir: es el ruido que ya nos comimos con las coincidencias de una
        // sola palabra.
        if (in_array(
            $item->getVisibilidad(),
            [PmsGuiaVisibilidad::SoloVentana, PmsGuiaVisibilidad::ClienteConfirmado],
            true
        )) {
            return 'sólo se puede dar en cierto momento, y la guía sabe cuándo';
        }

        return '';
    }

    /** El cuerpo publicado sin traducir, sólo para mirar si lleva placeholders. */
    private function cuerpoCrudo(PmsGuiaItem $item): string
    {
        $textos = [];

        foreach ($item->getContenidoParaCliente() as $traduccion) {
            if (is_array($traduccion) && isset($traduccion['content'])) {
                $textos[] = (string) $traduccion['content'];
            }
        }

        return implode(' ', $textos);
    }

    /** Los títulos del tema en todos los idiomas: la gente pregunta en el suyo. */
    private function titulos(PmsGuiaItem $item): string
    {
        $textos = [];

        foreach ($item->getTituloParaCliente() as $traduccion) {
            if (is_array($traduccion) && isset($traduccion['content'])) {
                $textos[] = (string) $traduccion['content'];
            }
        }

        return implode(' ', $textos);
    }

    /**
     * Cuántas palabras tienen que coincidir para dar por solapado un tema.
     *
     * Dos es el mínimo que distingue «hablan de lo mismo» de «comparten una palabra corriente».
     */
    private const int MINIMO_COINCIDENCIAS = 2;

    /**
     * Palabras que casan con cualquier cosa y no dicen de qué se habla.
     *
     * Van fuera porque son las que provocan los falsos positivos: aparecen en los términos de
     * medio catálogo porque así es como pregunta la gente —«dónde dejo», «puedo tener»—.
     */
    private const array VACIAS = [
        'donde', 'dejo', 'dejar', 'puedo', 'tengo', 'tiene', 'hacer', 'para', 'como', 'cual',
        'esta', 'este', 'mismo', 'cuando', 'hasta', 'desde', 'sobre', 'algun', 'alguna', 'hola',
        'favor', 'gracias', 'consulta', 'pregunta', 'quisiera', 'saber', 'necesito',
    ];

    /**
     * Palabras de cuatro letras o más, en minúsculas y sin acentos.
     *
     * Cuatro y no tres porque «con», «que» o «por» casan con cualquier cosa y convertirían el
     * aviso en ruido que se ignora.
     *
     * @return list<string>
     */
    private function palabrasDe(string $texto): array
    {
        $limpio = strtr(
            mb_strtolower($texto),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']
        );

        $palabras = preg_split('/[^a-z0-9]+/', $limpio, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $palabras,
            static fn (string $p): bool => mb_strlen($p) >= 4 && !in_array($p, self::VACIAS, true)
        )));
    }

    /**
     * El índice entero, montado determinista para que el prefijo cacheado no baile.
     *
     * @return array{bloque: string, porUnidad: array<string, list<string>>}
     *         `bloque` es el texto que va al prompt (vacío si no hay guías);
     *         `porUnidad` mapea uuid de unidad → uuids de los temas que esa unidad tiene.
     */
    public function construir(): array
    {
        $guias = $this->em->getRepository(PmsGuia::class)->findBy(['activo' => true]);

        // Por uuid de ítem: etiqueta + en qué unidades está. Un ítem compartido sale UNA vez.
        /** @var array<string, array{tema: string, unidades: array<string, string>}> $temas */
        $temas = [];
        /** @var array<string, list<string>> $porUnidad */
        $porUnidad = [];
        $unidades = [];

        foreach ($guias as $guia) {
            $unidad = $guia->getUnidad();
            if ($unidad === null) {
                continue;
            }

            $unidadId = (string) $unidad->getId();
            $nombreUnidad = trim((string) $unidad->getNombre());
            $unidades[$unidadId] = $nombreUnidad;

            foreach ($guia->getGuiaHasSecciones() as $relacion) {
                if (!$relacion->isActivo() || $relacion->getSeccion() === null) {
                    continue;
                }

                foreach ($relacion->getSeccion()->getItemsApi() as $item) {
                    // ⚠️ Del ítem CRUDO se lee `getTitulo()`, no `getTituloParaCliente()`: los
                    // campos «ParaCliente» son transitorios y los rellena el filtro al podar —
                    // en la entidad recién cargada están vacíos, y con ellos el índice sale
                    // vacío en silencio. Sin título no hay tema que ofrecer y no se indexa.
                    $tema = $this->enEspanol($item->getTitulo());
                    if ($tema === '') {
                        continue;
                    }

                    $temaId = (string) $item->getId();
                    $temas[$temaId] ??= ['tema' => $tema, 'unidades' => []];
                    $temas[$temaId]['unidades'][$unidadId] = $nombreUnidad;
                    $porUnidad[$unidadId][] = $temaId;
                }
            }
        }

        if ($temas === []) {
            return ['bloque' => '', 'porUnidad' => []];
        }

        $lineas = [];
        foreach ($temas as $temaId => $dato) {
            $enTodas = count($dato['unidades']) === count($unidades);
            $nombres = array_values($dato['unidades']);
            sort($nombres);

            $lineas[$dato['tema'] . '·' . $temaId] = sprintf(
                '- %s — %s — %s',
                $temaId,
                $dato['tema'],
                $enTodas ? 'todas' : implode(', ', $nombres)
            );
        }

        // Orden estable por etiqueta y uuid: el bloque tiene que ser idéntico byte a byte
        // entre peticiones o el caché de Anthropic no acierta nunca.
        ksort($lineas);

        foreach ($porUnidad as $unidadId => $ids) {
            sort($ids);
            $porUnidad[$unidadId] = array_values(array_unique($ids));
        }

        return [
            'bloque' => "TEMAS DE LA GUÍA (tema_id — tema — casitas donde aplica):\n"
                . implode("\n", $lineas),
            'porUnidad' => $porUnidad,
        ];
    }

    /**
     * Las casitas del huésped que pregunta: uuid de unidad → nombre.
     *
     * Sale de las mismas estancias que usa `ConsultarGuiaSkill` (activas y con guía
     * habilitada). Puede devolver varias —una reserva con dos casitas— y entonces el triaje
     * las nombra todas: los temas generales comparten uuid igual, y en los propios el modelo
     * ya no puede estar seguro, que es exactamente cuando debe dejar el tema vacío.
     *
     * @return array<string, string>
     */
    public function unidadesDelActor(ActorInterface $actor): array
    {
        $reservaId = $actor->contextoId();
        if ($reservaId === null || !Uuid::isValid($reservaId)) {
            return [];
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);
        if ($reserva === null) {
            return [];
        }

        $unidades = [];
        foreach ($reserva->getEventosActivosGuia() as $evento) {
            $unidad = $evento->getPmsUnidad();
            if ($unidad !== null) {
                $unidades[(string) $unidad->getId()] = trim((string) $unidad->getNombre());
            }
        }

        return $unidades;
    }

    /**
     * El título en español, con el primer idioma no vacío como red.
     *
     * Mismo criterio de resolución que `ConsultarGuiaSkill::enIdioma()`, fijado a «es»: el
     * índice es interno del clasificador, que entiende el tema en español escriba el huésped
     * en el idioma que escriba.
     *
     * @param list<array{language?: string, content?: string}> $i18n
     */
    private function enEspanol(array $i18n): string
    {
        $fallback = '';

        foreach ($i18n as $bloque) {
            $contenido = trim((string) ($bloque['content'] ?? ''));
            if ($contenido === '') {
                continue;
            }

            if (($bloque['language'] ?? '') === 'es') {
                return $contenido;
            }

            if ($fallback === '') {
                $fallback = $contenido;
            }
        }

        return $fallback;
    }
}
