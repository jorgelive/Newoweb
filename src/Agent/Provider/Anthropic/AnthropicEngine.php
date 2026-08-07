<?php

declare(strict_types=1);

namespace App\Agent\Provider\Anthropic;

use App\Agent\Conversation\AgentEngineInterface;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\ConversationResponse;
use App\Agent\Skill\SkillRegistry;
use Psr\Log\LoggerInterface;

/**
 * Motor de conversación sobre la API de Anthropic.
 *
 * Todo lo específico del proveedor —el SDK, el tool runner, el caché de prompts, el control
 * de rechazos— vive aquí dentro. Quien lo usa sólo conoce {@see AgentEngineInterface}.
 *
 * Ver docs/Mensajeria.md §11.
 */
final readonly class AnthropicEngine implements AgentEngineInterface
{
    /** Turnos de ida y vuelta que se conservan. Acota el coste de un hilo largo. */
    private const int MAX_TURNOS = 12;

    public function __construct(
        private AnthropicClientFactory $anthropic,
        private SkillRegistry $skills,
        private AnthropicSkillAdapter $adaptador,
        private LoggerInterface $logger,
    ) {}

    public function nombre(): string
    {
        return 'anthropic';
    }

    public function etiqueta(): string
    {
        return 'Anthropic';
    }

    public function estaDisponible(): bool
    {
        return $this->anthropic->estaConfigurado();
    }

    /** @return non-empty-list<string> */
    public function modelos(): array
    {
        return $this->anthropic->modelos();
    }

    public function modeloPorDefecto(): string
    {
        return $this->anthropic->modelo();
    }

    public function conversar(ConversationRequest $peticion): ConversationResponse
    {
        $cliente = $this->anthropic->crear();
        if ($cliente === null) {
            return ConversationResponse::noDisponible();
        }

        $skills = $this->skills->paraActor($peticion->actor, $peticion->permitirEscritura);
        if ($skills === []) {
            return ConversationResponse::sinPermisos();
        }

        $usadas = [];
        $tools = $this->adaptador->traducir($skills, $peticion->actor, $usadas);

        $mensajes = [
            ...$this->turnosPrevios($peticion->historial),
            ['role' => 'user', 'content' => $peticion->mensaje],
        ];

        $comunes = [
            // La petición manda cuando trae modelo (el desplegable del panel); el registro ya
            // comprobó que pertenece a este proveedor.
            'model' => $peticion->modelo ?? $this->anthropic->modelo(),
            'maxTokens' => $peticion->maxTokens,
            'system' => [[
                'type' => 'text',
                'text' => $peticion->systemPrompt,
                // Idéntico durante toda la conversación: cachearlo ahorra la mayor parte del
                // coste de entrada a partir del segundo turno.
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            'messages' => $mensajes,
        ];

        $texto = null;

        foreach ($cliente->beta->messages->toolRunner(...$comunes, tools: $tools) as $mensaje) {
            // Los clasificadores pueden declinar: llega un 200 con `content` vacío. Leer
            // content[0] sin comprobarlo revienta.
            if ($mensaje->stopReason === 'refusal') {
                $this->logger->warning(sprintf(
                    'Agent: petición declinada por los clasificadores para %s.',
                    $peticion->actor->etiqueta()
                ));

                return ConversationResponse::rechazada();
            }

            foreach ($mensaje->content as $bloque) {
                if ($bloque->type === 'text' && trim($bloque->text) !== '') {
                    $texto = $bloque->text;
                }
            }
        }

        if ($texto === null) {
            return ConversationResponse::vacia();
        }

        // Sin skill usada, la respuesta salió del modelo y no de los datos. Se entrega
        // marcada: cada canal decide si eso vale (el panel) o no (el chat del huésped).
        return $usadas === []
            ? ConversationResponse::sinSkill($texto)
            : ConversationResponse::ok($texto, array_values(array_unique($usadas)));
    }

    /**
     * @param list<array{rol: string, texto: string}> $historial
     * @return list<array{role: string, content: string}>
     */
    private function turnosPrevios(array $historial): array
    {
        $turnos = [];

        foreach ($historial as $turno) {
            $texto = trim((string) ($turno['texto'] ?? ''));
            if ($texto === '') {
                continue;
            }

            $turnos[] = [
                'role' => ($turno['rol'] ?? '') === 'asistente' ? 'assistant' : 'user',
                'content' => mb_substr($texto, 0, 2000),
            ];
        }

        $turnos = array_slice($turnos, -self::MAX_TURNOS);

        // La API exige que el hilo empiece por `user`; recortar por el final puede dejar un
        // `assistant` al principio.
        while ($turnos !== [] && $turnos[0]['role'] !== 'user') {
            array_shift($turnos);
        }

        return $turnos;
    }
}
