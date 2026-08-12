<?php

declare(strict_types=1);

namespace App\Pms\Service\Agent;

use App\Agent\Access\ActorInterface;
use App\Message\Contract\IndiceDeTemasInterface;
use App\Pms\Entity\PmsGuia;
use App\Pms\Entity\PmsReserva;
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
