<?php

declare(strict_types=1);

namespace App\Agent\Provider\Anthropic;

use Anthropic\Lib\Tools\BetaRunnableTool;
use App\Agent\Access\ActorInterface;
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
    public function __construct(
        private LoggerInterface $logger
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

        foreach ($skills as $skill) {
            $tools[] = new BetaRunnableTool(
                definition: [
                    'name' => $skill->nombre(),
                    'description' => $skill->definicion()->descripcion,
                    'inputSchema' => $this->esquema($skill),
                ],
                run: function (array $entrada) use ($skill, $actor, &$usadas): string {
                    $usadas[] = $skill->nombre();

                    $this->logger->info(sprintf(
                        'Agent: %s usa la skill "%s".',
                        $actor->etiqueta(),
                        $skill->nombre()
                    ));

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
