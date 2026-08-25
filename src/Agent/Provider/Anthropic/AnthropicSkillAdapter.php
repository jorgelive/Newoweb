<?php

declare(strict_types=1);

namespace App\Agent\Provider\Anthropic;

use App\Agent\Access\GuardiaDeSkills;
use Anthropic\Lib\Tools\BetaRunnableTool;
use App\Agent\Access\ActorInterface;
use App\Agent\Skill\RastroDeSkill;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Traduce las skills del dominio al formato de herramientas de Anthropic.
 *
 * 🔑 **Es el único sitio del proyecto que sabe cómo Anthropic espera una herramienta.** Las
 * skills describen sus parámetros con {@see SkillParameter}; aquí se convierten en el
 * `inputSchema` que pide el SDK. Si mañana cambia ese formato —o se añade otro proveedor con
 * el suyo— se toca este archivo y ninguna skill.
 */
final readonly class AnthropicSkillAdapter
{
    /**
     * Cuánto vive el catálogo cacheado.
     *
     * `5m` —el defecto— no sirve aquí: este chat recibe unos pocos mensajes por hora, así que
     * casi todas las conversaciones empezarían con el caché ya caducado y pagando la escritura
     * otra vez. Con `1h` el catálogo se escribe una vez y lo aprovechan todas las consultas de
     * esa hora, vengan del huésped que vengan. Escribir a 1h cuesta un pelo más que a 5m; con
     * un catálogo de 7 826 tokens compartido por todos, sale rentable con muy poco tráfico.
     */
    private const string TTL_CACHE = '1h';

    public function __construct(
        private LoggerInterface $logger,
        private GuardiaDeSkills $guardia
    ) {}

    /**
     * @param list<SkillInterface> $skills
     * @param list<string> $usadas Se rellena por referencia con los nombres invocados, para
     *                             poder decir después qué se consultó de verdad. Sin eso la
     *                             respuesta es una caja negra.
     * @return list<BetaRunnableTool>
     */
    public function traducir(array $skills, ActorInterface $actor, array &$usadas): array
    {
        $tools = [];
        $ultima = array_key_last($skills);

        foreach ($skills as $i => $skill) {
            $definicion = [
                'name' => $skill->nombre(),
                'description' => $skill->definicion()->descripcion,
                'inputSchema' => $this->esquema($skill),
            ];

            // 🔑 LA MARCA DE CACHÉ VA EN LA ÚLTIMA HERRAMIENTA, y ahí está casi todo el ahorro.
            //
            // En el orden del prompt de Anthropic —herramientas, luego `system`, luego los
            // mensajes— una marca cachea TODO lo que hay por delante. Puesta aquí, el bloque
            // cacheado es el catálogo entero y nada más: 7 826 tokens para el actor del panel,
            // 1 852 para el huésped, **idénticos byte a byte en todas las conversaciones del
            // mismo rol**. Lo escribe el primero que pregunte en la hora y lo leen los demás.
            //
            // ⚠️ Antes la única marca estaba en el `system`, y el `system` del huésped llevaba
            // su nombre dentro: el prefijo cacheado era distinto en cada conversación, así que
            // cada una **escribía** su propio caché (que se paga a 1,25×) para leerlo como
            // mucho un par de turnos y tirarlo. En conversaciones cortas eso costaba MÁS que no
            // cachear. Ver docs/Mensajeria.md §12.
            if ($i === $ultima) {
                $definicion['cacheControl'] = ['type' => 'ephemeral', 'ttl' => self::TTL_CACHE];
            }

            $tools[] = new BetaRunnableTool(
                definition: $definicion,
                run: function (array $entrada) use ($skill, $actor, &$usadas): string {
                    $usadas[] = $skill->nombre();

                    $this->logger->info(sprintf(
                        'Agent: %s usa la skill "%s" con %s.',
                        $actor->etiqueta(),
                        $skill->nombre(),
                        RastroDeSkill::argumentos($entrada)
                    ));

                    // El cierre, antes de tocar nada. El catálogo dice qué se ofrece; esto
                    // dice qué se ejecuta. Ver GuardiaDeSkills.
                    $bloqueo = $this->guardia->motivoDeBloqueo($skill, $actor);

                    if ($bloqueo !== null) {
                        $this->logger->warning(sprintf(
                            'Agent: BLOQUEADA la skill "%s" para %s: %s',
                            $skill->nombre(),
                            $actor->etiqueta(),
                            $bloqueo
                        ));

                        return json_encode(['error' => $bloqueo], JSON_UNESCAPED_UNICODE);
                    }

                    try {
                        return $skill->ejecutar($entrada, $actor)->aJson();
                    } catch (Throwable $e) {
                        // Un fallo de infraestructura no debe romper el turno: se le cuenta al
                        // modelo en su idioma y el detalle queda en el log.
                        $this->logger->error(sprintf(
                            'Agent: la skill "%s" falló para %s: %s',
                            $skill->nombre(),
                            $actor->etiqueta(),
                            $e->getMessage()
                        ));

                        return json_encode(
                            ['error' => 'La consulta no se pudo completar.'],
                            JSON_UNESCAPED_UNICODE
                        );
                    }
                },
            );
        }

        return $tools;
    }

    /**
     * @return array<string, mixed> JSON Schema tal y como lo espera el SDK.
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
            // Sin parámetros hace falta un objeto vacío, no un array: json_encode convertiría
            // `[]` en `[]` y la API espera `{}`.
            'properties' => $propiedades === [] ? new \stdClass() : $propiedades,
        ];

        $requeridos = array_map(
            static fn (SkillParameter $p) => $p->nombre,
            $definicion->requeridos()
        );

        if ($requeridos !== []) {
            $esquema['required'] = $requeridos;
        }

        return $esquema;
    }
}
