<?php

declare(strict_types=1);

namespace App\Agent\Provider\Google;

use App\Agent\Conversation\AgentEngineInterface;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\ConversationResponse;
use App\Agent\Skill\SkillRegistry;
use Psr\Log\LoggerInterface;

/**
 * Motor de conversación sobre Google AI Studio (Gemini).
 *
 * Hermano de {@see \App\Agent\Provider\Anthropic\AnthropicEngine}: mismas skills, mismos
 * permisos, mismo {@see ConversationResponse}. Lo que cambia vive entero aquí dentro.
 *
 * **El bucle de herramientas es manual.** Anthropic trae `toolRunner` en su SDK; en Gemini hay
 * que hacerlo a mano: se pide, se mira si vinieron `functionCall`, se ejecutan, se devuelven
 * como `functionResponse` y se vuelve a pedir hasta que el modelo conteste con texto. Dos
 * detalles que la API impone y no son negociables:
 *
 * 1. El turno del modelo (con sus `functionCall`) tiene que quedar en el hilo **antes** de los
 *    resultados. Si se saltan, la API rechaza el `functionResponse` por huérfano.
 * 2. Los resultados van con rol `user`, no con un rol propio: `Content.role` sólo admite
 *    `user` y `model`.
 *
 * Lo que NO se hereda de Anthropic: el caché de prompts. Gemini lo tiene, pero explícito y con
 * mínimo de tokens; para el tamaño de system prompt de este proyecto no compensa la
 * complejidad. Por eso el segundo turno de Gemini es proporcionalmente más caro.
 *
 * Ver docs/Mensajeria.md §12.
 */
final readonly class GoogleAIEngine implements AgentEngineInterface
{
    /** Turnos de ida y vuelta que se conservan. Acota el coste de un hilo largo. */
    private const int MAX_TURNOS = 12;

    /**
     * Vueltas de herramientas antes de rendirse.
     *
     * Es el tope que en Anthropic pone el SDK. Sin él, un modelo que insista en llamar a la
     * misma skill deja el turno girando y quemando dinero hasta el timeout de PHP.
     */
    private const int MAX_VUELTAS = 8;

    /** Desenlaces en los que Gemini corta por sus propios filtros, no por falta de respuesta. */
    private const array FIN_RECHAZADO = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'];

    public function __construct(
        private GoogleAIClient $google,
        private SkillRegistry $skills,
        private GoogleAISkillAdapter $adaptador,
        private LoggerInterface $logger,
    ) {}

    public function nombre(): string
    {
        return 'google';
    }

    public function etiqueta(): string
    {
        return 'Google AI Studio';
    }

    public function estaDisponible(): bool
    {
        return $this->google->estaConfigurado();
    }

    /** @return non-empty-list<string> */
    public function modelos(): array
    {
        return $this->google->modelos();
    }

    public function modeloPorDefecto(): string
    {
        return $this->google->modelo();
    }

    public function conversar(ConversationRequest $peticion): ConversationResponse
    {
        if (!$this->google->estaConfigurado()) {
            return ConversationResponse::noDisponible();
        }

        $skills = $this->skills->paraActor($peticion->actor, $peticion->permitirEscritura);
        if ($skills === []) {
            return ConversationResponse::sinPermisos();
        }

        $usadas = [];
        $modelo = $peticion->modelo ?? $this->google->modelo();

        $contenidos = [
            ...$this->turnosPrevios($peticion->historial),
            ['role' => 'user', 'parts' => [['text' => $peticion->mensaje]]],
        ];

        $generacion = ['maxOutputTokens' => $peticion->maxTokens];

        // Ver GoogleAIClient::razonamiento(): sin acotarlo, cada vuelta del bucle paga tokens
        // de pensamiento y el turno se va a decenas de segundos.
        if (($nivel = $this->google->razonamiento()) !== null) {
            $generacion['thinkingConfig'] = ['thinkingLevel' => $nivel];
        }

        // Las dos partes del prompt van en `parts` separadas, respetando el orden estable →
        // volátil. Aquí no cambia el coste —Gemini cachea de forma implícita y no se le marcan
        // los cortes—, pero mantiene un solo formato de prompt para los dos proveedores: si
        // algún día se pide caché explícito a Google, el corte ya está donde tiene que estar.
        $partes = [['text' => $peticion->systemPrompt]];
        if (trim((string) $peticion->contexto) !== '') {
            $partes[] = ['text' => trim((string) $peticion->contexto)];
        }

        $base = [
            'systemInstruction' => ['parts' => $partes],
            'tools' => [['functionDeclarations' => $this->adaptador->declaraciones($skills)]],
            'generationConfig' => $generacion,
        ];

        for ($vuelta = 0; $vuelta < self::MAX_VUELTAS; $vuelta++) {
            $cronometro = microtime(true);
            $datos = $this->google->generarContenido($modelo, [...$base, 'contents' => $contenidos]);

            // Coste y latencia por vuelta. Sin esto, un turno lento es una caja negra: no se
            // sabe si tarda la API, la generación de salida o una skill.
            $uso = is_array($datos['usageMetadata'] ?? null) ? $datos['usageMetadata'] : [];
            $this->logger->info(sprintf(
                'Agent (google): vuelta %d · %.1f s · entrada %d · pensamiento %d · salida %d tokens.',
                $vuelta + 1,
                microtime(true) - $cronometro,
                (int) ($uso['promptTokenCount'] ?? 0),
                (int) ($uso['thoughtsTokenCount'] ?? 0),
                (int) ($uso['candidatesTokenCount'] ?? 0)
            ));

            // Sin candidatos hay dos casos distintos: los filtros tumbaron la PREGUNTA
            // (`promptFeedback.blockReason`) o simplemente no vino nada.
            $candidato = $datos['candidates'][0] ?? null;
            if (!is_array($candidato)) {
                if (isset($datos['promptFeedback']['blockReason'])) {
                    $this->logger->warning(sprintf(
                        'Agent (google): petición declinada por los filtros para %s (%s).',
                        $peticion->actor->etiqueta(),
                        (string) $datos['promptFeedback']['blockReason']
                    ));

                    return ConversationResponse::rechazada();
                }

                return ConversationResponse::vacia();
            }

            // Y aquí, que tumbaron la RESPUESTA a medio generar.
            if (in_array($candidato['finishReason'] ?? '', self::FIN_RECHAZADO, true)) {
                $this->logger->warning(sprintf(
                    'Agent (google): respuesta cortada por los filtros para %s (%s).',
                    $peticion->actor->etiqueta(),
                    (string) $candidato['finishReason']
                ));

                return ConversationResponse::rechazada();
            }

            $partes = is_array($candidato['content']['parts'] ?? null) ? $candidato['content']['parts'] : [];

            $llamadas = [];
            $texto = '';
            foreach ($partes as $parte) {
                if (is_array($parte['functionCall'] ?? null)) {
                    $llamadas[] = $parte['functionCall'];
                } elseif (is_string($parte['text'] ?? null)) {
                    $texto .= $parte['text'];
                }
            }

            if ($llamadas === []) {
                return $this->desenlace($texto, $usadas);
            }

            // (1) El turno del modelo. Casi tal cual vino: ver `devolverParte()`.
            $contenidos[] = ['role' => 'model', 'parts' => array_map($this->devolverParte(...), $partes)];

            // (2) Los resultados, en el mismo orden. Gemini puede pedir varias skills a la vez.
            $resultados = [];
            foreach ($llamadas as $llamada) {
                $nombre = (string) ($llamada['name'] ?? '');
                $argumentos = is_array($llamada['args'] ?? null) ? $llamada['args'] : [];

                $resultados[] = ['functionResponse' => [
                    'name' => $nombre,
                    'response' => $this->adaptador->ejecutar(
                        $skills,
                        $nombre,
                        $argumentos,
                        $peticion->actor,
                        $usadas
                    ),
                ]];
            }

            $contenidos[] = ['role' => 'user', 'parts' => $resultados];
        }

        $this->logger->warning(sprintf(
            'Agent (google): %d vueltas de herramientas sin respuesta final para %s.',
            self::MAX_VUELTAS,
            $peticion->actor->etiqueta()
        ));

        return ConversationResponse::vacia();
    }

    /**
     * Deja una parte del modelo lista para volver a mandarla en `contents`.
     *
     * ⚠️ **Gotcha caro.** Gemini devuelve `"args": {}` cuando llama a una skill sin
     * argumentos. `json_decode(..., true)` lo convierte en `[]`, y `json_encode` re-serializa
     * ese `[]` como LISTA vacía. Pero `FunctionCall.args` es un `Struct`, no un campo
     * repetido, así que la API tumba **el turno entero** con:
     *
     *     Unknown name "args" at 'contents[1].parts[0].function_call':
     *     Proto field is not repeating, cannot start list.
     *
     * Es el mismo problema de fondo que el `properties` vacío del adaptador —PHP no distingue
     * objeto vacío de lista vacía— y aparece sólo en la SEGUNDA vuelta, cuando ya se ejecutó
     * la skill: por eso no salta hasta que el modelo usa una herramienta de verdad.
     */
    private function devolverParte(mixed $parte): mixed
    {
        if (!is_array($parte) || !is_array($parte['functionCall'] ?? null)) {
            return $parte;
        }

        if (($parte['functionCall']['args'] ?? null) === []) {
            $parte['functionCall']['args'] = new \stdClass();
        }

        return $parte;
    }

    /**
     * @param list<string> $usadas
     */
    private function desenlace(string $texto, array $usadas): ConversationResponse
    {
        if (trim($texto) === '') {
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
     * @return list<array{role: string, parts: list<array{text: string}>}>
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
                // Gemini llama `model` a lo que Anthropic llama `assistant`.
                'role' => ($turno['rol'] ?? '') === 'asistente' ? 'model' : 'user',
                'parts' => [['text' => mb_substr($texto, 0, 2000)]],
            ];
        }

        $turnos = array_slice($turnos, -self::MAX_TURNOS);

        // La API exige que el hilo empiece por `user`; recortar por el final puede dejar un
        // `model` al principio.
        while ($turnos !== [] && $turnos[0]['role'] !== 'user') {
            array_shift($turnos);
        }

        return $turnos;
    }
}
