<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Access\ActorInterface;
use App\Agent\Conversation\PerfilConversacion;
use App\Agent\Entity\AgentConocimiento;
use App\Agent\Entity\AgentConocimientoCategoria;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Las dos fases de la consulta de conocimiento, y el filtro de quién puede ver qué.
 *
 * ── Por qué dos fases y no una búsqueda ─────────────────────────────────────
 * Se podría buscar por texto y devolver lo que más se parezca, y sería peor: una búsqueda por
 * palabras acierta cuando el huésped usa las nuestras y falla cuando usa las suyas —«cochera»,
 * «dónde meto el auto», «hay dónde estacionar»—. Quien sabe resolver eso es el modelo, que para
 * eso entiende la pregunta. Lo que hace falta es enseñarle lo justo para que elija:
 *
 * ```
 *   fase 1   las CATEGORÍAS, unas líneas, en el contexto del turno
 *   fase 2   las ETIQUETAS de la categoría elegida, sin contenido
 *   → el CONTENIDO sólo cuando ya eligió un ítem
 * ```
 *
 * Mandarlo todo junto sería mandar la tabla entera, y está pensada para crecer mucho.
 *
 * ── El filtro va aquí, no en el prompt ──────────────────────────────────────
 * ⚠️ Un ítem que no le toca a este interlocutor **no llega a verse**: se descarta al construir
 * la lista. No se le enseña al modelo pidiéndole que lo ignore, porque eso ya falló —esta misma
 * semana, con los teléfonos del personal de limpieza: una instrucción en mayúsculas se ignoró
 * tres veces—. Lo que no debe salir, no se manda.
 */
final readonly class ConocimientoGenerico
{
    /**
     * Tope de ítems que se enseñan en la segunda fase.
     *
     * Una categoría con cincuenta ítems es una categoría mal partida, no un problema de tope:
     * si se llega aquí, lo que hay que hacer es dividirla en el panel. El recorte evita que
     * mientras tanto un turno se vaya de precio.
     */
    private const int MAX_ETIQUETAS = 25;

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * FASE 1 — el bloque de categorías que se le enseña al modelo.
     *
     * Cadena vacía cuando no hay ninguna: entonces esto no existe para el turno y el agente se
     * comporta como antes de que hubiera conocimiento. Es el estado del día del despliegue.
     */
    public function bloqueDeCategorias(): string
    {
        $categorias = $this->categorias();

        if ($categorias === []) {
            return '';
        }

        return "CONOCIMIENTO (temas sobre los que hay respuesta escrita):\n"
            . implode("\n", array_map(
                static fn (AgentConocimientoCategoria $c): string => $c->comoLinea(),
                $categorias
            ));
    }

    /**
     * FASE 2 — las etiquetas de una categoría, ya filtradas para este actor.
     *
     * @return list<AgentConocimiento>
     */
    public function itemsDe(string $categoriaId, ActorInterface $actor): array
    {
        $categoria = $this->em->getRepository(AgentConocimientoCategoria::class)->find($categoriaId);

        if ($categoria === null || !$categoria->isActiva()) {
            return [];
        }

        $perfil = PerfilConversacion::deActor($actor);
        $dominios = $actor->dominios();

        $visibles = [];

        foreach ($categoria->getItems() as $item) {
            if ($item->visiblePara($dominios, $perfil)) {
                $visibles[] = $item;
            }
        }

        return array_slice($visibles, 0, self::MAX_ETIQUETAS);
    }

    /**
     * El ítem elegido, o `null` si no existe o no le toca a este actor.
     *
     * ⚠️ Se revalida la visibilidad aunque el id venga de la lista que acabamos de dar: entre
     * las dos llamadas el modelo pudo inventárselo, y un id inventado que resolviera a contenido
     * de otro perfil sería la fuga que este filtro existe para evitar.
     */
    public function item(string $id, ActorInterface $actor): ?AgentConocimiento
    {
        $item = $this->em->getRepository(AgentConocimiento::class)->find($id);

        if ($item === null) {
            return null;
        }

        return $item->visiblePara($actor->dominios(), PerfilConversacion::deActor($actor))
            ? $item
            : null;
    }

    /**
     * ¿Hay algo escrito que pueda responder a esto, sin preguntarle al modelo?
     *
     * Es la red del escalado: antes de molestar a una persona se mira si alguna etiqueta casa
     * con lo que se preguntó. La comparación es tosca a propósito —palabras de cuatro letras o
     * más, sin acentos— porque aquí no se busca acertar siempre, sino **no escalar lo obvio**.
     * Lo fino lo hace el modelo por el otro camino.
     *
     * @return list<AgentConocimiento>
     */
    public function candidatosPara(string $texto, ActorInterface $actor): array
    {
        $palabras = $this->palabrasDe($texto);

        if ($palabras === []) {
            return [];
        }

        $perfil = PerfilConversacion::deActor($actor);
        $dominios = $actor->dominios();
        $encontrados = [];

        foreach ($this->em->getRepository(AgentConocimiento::class)->findBy(['activo' => true]) as $item) {
            if (!$item->visiblePara($dominios, $perfil)) {
                continue;
            }

            $etiquetas = $this->palabrasDe($item->getEtiquetas());

            if (array_intersect($palabras, $etiquetas) !== []) {
                $encontrados[] = $item;
            }
        }

        return $encontrados;
    }

    /** @return list<AgentConocimientoCategoria> */
    public function categorias(): array
    {
        return $this->em->getRepository(AgentConocimientoCategoria::class)
            ->findBy(['activa' => true], ['orden' => 'ASC', 'nombre' => 'ASC']);
    }

    /**
     * Las palabras que cuentan para comparar: de cuatro letras o más, en minúsculas y sin
     * acentos.
     *
     * Cuatro y no tres porque «con», «que» o «por» casan con cualquier cosa y convertirían la
     * red en un colador que responde lo que sea a lo que sea.
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
            static fn (string $p): bool => mb_strlen($p) >= 4
        )));
    }
}
