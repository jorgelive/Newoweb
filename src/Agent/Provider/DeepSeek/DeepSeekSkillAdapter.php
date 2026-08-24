<?php

declare(strict_types=1);

namespace App\Agent\Provider\DeepSeek;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\GuardiaDeSkills;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Traduce las skills del dominio al formato de herramientas de DeepSeek (compatible OpenAI).
 *
 * 🔑 **Es el único sitio del proyecto que sabe cómo DeepSeek espera una herramienta.** Las skills
 * describen sus parámetros con {@see SkillParameter}; aquí se convierten en el `function.parameters`
 * que pide la API.
 *
 * ## Diferencia con el adaptador de Anthropic
 *
 * Aquel devuelve objetos ejecutables (`BetaRunnableTool`) y el SDK gira el bucle solo. Aquí no hay
 * SDK: la API devuelve `tool_calls` y **el motor tiene que ejecutarlas y volver a llamar**. Por
 * eso este adaptador está partido en dos — {@see self::traducir()} describe, {@see self::ejecutar()}
 * corre— en vez de en un cierre.
 *
 * ⚠️ **Y aquí NO hay marca de caché**, a diferencia de Anthropic. DeepSeek cachea por prefijo sin
 * que nadie se lo pida, así que lo que decide el acierto es que este catálogo salga **en el mismo
 * orden y con el mismo texto** en todas las llamadas del mismo rol. `SkillRegistry::paraActor()` ya
 * lo devuelve ordenado; no reordenar aquí es parte del contrato.
 */
final readonly class DeepSeekSkillAdapter
{
    public function __construct(
        private LoggerInterface $logger,
        private GuardiaDeSkills $guardia,
    ) {}

    /**
     * @param list<SkillInterface> $skills
     * @return list<array<string, mixed>>
     */
    public function traducir(array $skills): array
    {
        $tools = [];

        foreach ($skills as $skill) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $skill->nombre(),
                    'description' => $skill->definicion()->descripcion,
                    'parameters' => $this->esquema($skill),
                ],
            ];
        }

        return $tools;
    }

    /**
     * Ejecuta una herramienta pedida por el modelo y devuelve lo que hay que contarle.
     *
     * Nunca lanza: un fallo se le cuenta al modelo en su idioma —«la consulta no se pudo
     * completar»— y el detalle queda en el log. Si lanzara, un error de infraestructura en la
     * tercera vuelta tumbaría el turno entero y el huésped se quedaría sin respuesta.
     *
     * @param list<SkillInterface> $skills Las que el actor tiene permitidas, ya filtradas.
     * @param array<string, mixed> $argumentos
     * @param list<string> $usadas Se rellena por referencia con los nombres invocados.
     */
    public function ejecutar(
        array $skills,
        string $nombre,
        array $argumentos,
        ActorInterface $actor,
        array &$usadas,
    ): string {
        $skill = null;
        foreach ($skills as $candidata) {
            if ($candidata->nombre() === $nombre) {
                $skill = $candidata;
                break;
            }
        }

        // ⚠️ El modelo puede pedir una herramienta que no le pasamos —se las inventa, y con
        // aplomo—. Se le contesta que no existe en vez de dejar que el turno muera: con el
        // mensaje puede corregir y pedir la buena.
        if ($skill === null) {
            $this->logger->warning(sprintf(
                'Agent (deepseek): %s pidió la herramienta inexistente "%s".',
                $actor->etiqueta(),
                $nombre,
            ));

            return json_encode(
                ['error' => sprintf('No existe ninguna herramienta llamada "%s".', $nombre)],
                JSON_UNESCAPED_UNICODE,
            ) ?: '{}';
        }

        $usadas[] = $skill->nombre();

        $this->logger->info(sprintf('Agent: %s usa la skill "%s".', $actor->etiqueta(), $skill->nombre()));

        // El cierre, antes de tocar nada. El catálogo dice qué se ofrece; esto dice qué se
        // ejecuta. Ver GuardiaDeSkills.
        $bloqueo = $this->guardia->motivoDeBloqueo($skill, $actor);

        if ($bloqueo !== null) {
            $this->logger->warning(sprintf(
                'Agent: BLOQUEADA la skill "%s" para %s: %s',
                $skill->nombre(),
                $actor->etiqueta(),
                $bloqueo,
            ));

            return json_encode(['error' => $bloqueo], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        try {
            return $skill->ejecutar($argumentos, $actor)->aJson();
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Agent: la skill "%s" falló para %s: %s',
                $skill->nombre(),
                $actor->etiqueta(),
                $e->getMessage(),
            ));

            return json_encode(
                ['error' => 'La consulta no se pudo completar.'],
                JSON_UNESCAPED_UNICODE,
            ) ?: '{}';
        }
    }

    /**
     * @return array<string, mixed> JSON Schema tal y como lo espera la API.
     */
    private function esquema(SkillInterface $skill): array
    {
        $definicion = $skill->definicion();

        $propiedades = [];
        foreach ($definicion->parametros as $parametro) {
            $propiedades[$parametro->nombre] = [
                'type' => $parametro->tipo,
                'description' => $parametro->descripcion,
            ];
        }

        $esquema = [
            'type' => 'object',
            // Sin parámetros hace falta un objeto vacío, no un array: `json_encode` convertiría
            // `[]` en `[]` y la API espera `{}`.
            'properties' => $propiedades === [] ? new \stdClass() : $propiedades,
        ];

        $requeridos = array_map(
            static fn (SkillParameter $p): string => $p->nombre,
            $definicion->requeridos(),
        );

        if ($requeridos !== []) {
            $esquema['required'] = $requeridos;
        }

        return $esquema;
    }
}
