<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use Anthropic\Lib\Tools\BetaRunnableTool;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Reúne las herramientas etiquetadas y decide cuáles ve cada actor.
 *
 * 🔑 Lo que un actor no puede usar **ni se le menciona al modelo**. No es que las pida y se
 * le denieguen: es que no existen en su contexto. Un modelo no puede llamar a algo que no
 * sabe que hay — y de paso se ahorran los tokens de su esquema.
 *
 * Ver docs/Mensajeria.md §12.
 */
final readonly class AgentToolRegistry
{
    /**
     * @param iterable<AgentToolInterface> $herramientas
     */
    public function __construct(
        #[AutowireIterator('app.agent_tool')]
        private iterable $herramientas,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<AgentToolInterface>
     */
    public function paraActor(AgentActor $actor, bool $incluirEscritura = true): array
    {
        $permitidas = [];

        foreach ($this->herramientas as $herramienta) {
            if (!$actor->tieneAlguno($herramienta->rolesRequeridos())) {
                continue;
            }

            if (!$incluirEscritura && $herramienta->nivelRiesgo() !== NivelRiesgo::Lectura) {
                continue;
            }

            $permitidas[] = $herramienta;
        }

        return $permitidas;
    }

    /**
     * Traduce las herramientas permitidas al formato del SDK.
     *
     * @param list<string> $usadas Se rellena por referencia con los nombres invocados, para
     *                             poder decir en la interfaz qué se consultó de verdad. Sin
     *                             eso la respuesta es una caja negra.
     * @return list<BetaRunnableTool>
     */
    public function comoRunnables(AgentActor $actor, array &$usadas, bool $incluirEscritura = true): array
    {
        $runnables = [];

        foreach ($this->paraActor($actor, $incluirEscritura) as $herramienta) {
            $definicion = $herramienta->definicion();

            $runnables[] = new BetaRunnableTool(
                definition: [
                    'name' => $herramienta->nombre(),
                    'description' => $definicion['description'],
                    'inputSchema' => $definicion['inputSchema'],
                ],
                run: function (array $entrada) use ($herramienta, $actor, &$usadas): string {
                    $usadas[] = $herramienta->nombre();

                    $this->logger->info(sprintf(
                        'Agent: %s usa la herramienta "%s".',
                        $actor->etiqueta(),
                        $herramienta->nombre()
                    ));

                    try {
                        return $herramienta->ejecutar($entrada, $actor);
                    } catch (Throwable $e) {
                        // Un fallo de infraestructura no debe romper el turno: se le cuenta al
                        // modelo en su idioma para que avise, y el detalle queda en el log.
                        $this->logger->error(sprintf(
                            'Agent: la herramienta "%s" falló para %s: %s',
                            $herramienta->nombre(),
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

        return $runnables;
    }
}
