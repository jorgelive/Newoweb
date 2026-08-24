<?php

declare(strict_types=1);

namespace App\Agent\Provider\DeepSeek;

use App\Agent\Conversation\AgentEngineInterface;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\ConversationResponse;
use App\Agent\Skill\SkillRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Motor de conversación sobre la API de DeepSeek.
 *
 * Todo lo específico del proveedor vive aquí dentro; quien lo usa sólo conoce
 * {@see AgentEngineInterface}. Ver docs/Mensajeria.md §12.
 *
 * ## El bucle es NUESTRO
 *
 * Anthropic trae un `toolRunner` que gira solo. Aquí no: la API devuelve `tool_calls`, hay que
 * ejecutarlas, añadirlas al hilo y volver a llamar. De ahí {@see self::MAX_VUELTAS}, que es la red
 * que impide que un modelo terco encadene herramientas hasta agotar el tiempo o el saldo.
 *
 * ## 🔑 Cómo se aprovecha el caché, que es lo que hace barato este proveedor
 *
 * DeepSeek cachea **por prefijo y sin que nadie se lo pida**: si los primeros tokens coinciden con
 * los de una petición anterior, esa parte se cobra a una fracción. No hay marcas que poner, así
 * que todo el trabajo consiste en que el prefijo **no cambie**:
 *
 * ```
 * [ system estable ] [ catálogo de skills ] [ contexto de esta conversación ] [ mensajes ]
 *   └──────────── idéntico por (rol, modelo) ────────────┘   └── volátil, va detrás ──┘
 * ```
 *
 * ⚠️ **Lo volátil detrás no es una preferencia, es la única forma.** En Anthropic un prefijo mal
 * ordenado se corrige moviendo la marca; aquí no hay marca: el nombre del huésped arriba significa
 * caché cero, siempre, sin ningún aviso.
 *
 * ⚠️ Y por eso el `user` de la petición lleva {@see DeepSeekClient::firmaDeCache()} y no el
 * identificador de la persona: ver ahí el porqué entero.
 */
final readonly class DeepSeekEngine implements AgentEngineInterface
{
    /** Turnos de ida y vuelta que se conservan. Acota el coste de un hilo largo. */
    private const int MAX_TURNOS = 12;

    /**
     * Vueltas de herramientas por turno.
     *
     * Ocho es lo que permite el runner de Anthropic, y se replica para que cambiar de proveedor no
     * cambie lo que el agente es capaz de resolver. Al llegar al tope se devuelve lo que haya:
     * quedarse sin respuesta es peor que una respuesta a medias.
     */
    private const int MAX_VUELTAS = 8;

    public function __construct(
        private DeepSeekClient $deepseek,
        private SkillRegistry $skills,
        private DeepSeekSkillAdapter $adaptador,
        private LoggerInterface $logger,
    ) {}

    public function nombre(): string
    {
        return 'deepseek';
    }

    public function etiqueta(): string
    {
        return 'DeepSeek';
    }

    public function estaDisponible(): bool
    {
        return $this->deepseek->estaConfigurado();
    }

    /** @return non-empty-list<string> */
    public function modelos(): array
    {
        return $this->deepseek->modelos();
    }

    public function modeloPorDefecto(): string
    {
        return $this->deepseek->modelo();
    }

    public function conversar(ConversationRequest $peticion): ConversationResponse
    {
        if (!$this->deepseek->estaConfigurado()) {
            return ConversationResponse::noDisponible();
        }

        $skills = $this->skills->paraActor($peticion->actor, $peticion->permitirEscritura);
        if ($skills === []) {
            return ConversationResponse::sinPermisos();
        }

        $modelo = $peticion->modelo ?? $this->deepseek->modelo();
        $tools = $this->adaptador->traducir($skills);

        $mensajes = [
            ...$this->bloquesDeSistema($peticion),
            ...$this->turnosPrevios($peticion->historial),
            ['role' => 'user', 'content' => $peticion->mensaje],
        ];

        $usadas = [];
        $texto = null;
        $vueltas = 0;
        $aciertoCache = 0;
        $falloCache = 0;
        $salida = 0;

        for ($i = 0; $i < self::MAX_VUELTAS; ++$i) {
            ++$vueltas;

            try {
                $respuesta = $this->deepseek->completar([
                    'model' => $modelo,
                    'messages' => $mensajes,
                    'tools' => $tools,
                    'max_tokens' => $peticion->maxTokens,
                    // ⚠️ `user_id`, NO `user`. `user` es de OpenAI; DeepSeek no lo tiene y un
                    // campo desconocido se ignora en silencio: el mecanismo entero quedaba
                    // inerte sin un solo error. Ver DeepSeekClient::firmaDeCache().
                    'user_id' => DeepSeekClient::firmaDeCache(
                        $peticion->actor->roles(),
                        $peticion->actor->dominios(),
                        conCatalogo: true,
                        permiteEscritura: $peticion->permitirEscritura,
                        modelo: $modelo,
                    ),
                ]);
            } catch (Throwable $e) {
                $this->logger->error(sprintf('Agent (deepseek): %s', $e->getMessage()));

                // Con algo ya redactado se entrega eso; si no, se declara no disponible y quien
                // llama decide. Nunca se propaga: al otro lado hay un huésped esperando.
                //
                // ⚠️ `sinSkill()` SÓLO si de verdad no se usó ninguna. Es el motivo que alimenta
                // la métrica de «respuestas sin datos detrás», y devolverlo con skills ya
                // ejecutadas la ensucia — además de que algún canal descarta por ese motivo un
                // texto que sí salía de los datos.
                if ($texto === null) {
                    return ConversationResponse::noDisponible();
                }

                return $usadas === []
                    ? ConversationResponse::sinSkill($texto)
                    : ConversationResponse::ok($texto, array_values(array_unique($usadas)));
            }

            $uso = $respuesta['usage'] ?? [];
            $aciertoCache += (int) ($uso['prompt_cache_hit_tokens'] ?? 0);
            $falloCache += (int) ($uso['prompt_cache_miss_tokens'] ?? 0);
            $salida += (int) ($uso['completion_tokens'] ?? 0);

            $eleccion = $respuesta['choices'][0] ?? null;
            $mensaje = $eleccion['message'] ?? null;
            $motivoFin = (string) ($eleccion['finish_reason'] ?? '');

            // Los filtros de contenido cortan la respuesta. Los otros dos motores lo distinguen
            // —Anthropic con `refusal`, Google con `FIN_RECHAZADO`— y el canal de arriba decide
            // qué hacer con eso. Sin esta rama, una respuesta filtrada salía como texto normal a
            // medias: peor que no contestar, porque parece una respuesta.
            if ($motivoFin === 'content_filter') {
                $this->logger->warning(sprintf(
                    'Agent (deepseek): petición filtrada por el proveedor para %s.',
                    $peticion->actor->etiqueta(),
                ));

                return ConversationResponse::rechazada();
            }

            if (!is_array($mensaje)) {
                break;
            }

            $contenido = trim((string) ($mensaje['content'] ?? ''));
            if ($contenido !== '') {
                $texto = $contenido;
            }

            /** @var list<array<string, mixed>> $llamadas */
            $llamadas = is_array($mensaje['tool_calls'] ?? null) ? $mensaje['tool_calls'] : [];

            if ($llamadas === []) {
                break;
            }

            // ⚠️ En la ÚLTIMA vuelta no se ejecuta nada, y no es cosmético: ejecutar sin poder
            // volver a llamar significa que la skill escribe en la base y su resultado se tira,
            // porque no hay quien lo lea. El turno acabaría entregando un «déjame consultar…» de
            // una vuelta anterior, marcado como si esas skills hubieran informado la respuesta.
            if ($i === self::MAX_VUELTAS - 1) {
                $this->logger->warning(sprintf(
                    'Agent (deepseek): tope de %d vueltas con herramientas aún pendientes para %s; '
                    .'no se ejecutan y se entrega lo que haya.',
                    self::MAX_VUELTAS,
                    $peticion->actor->etiqueta(),
                ));

                break;
            }

            // El turno del asistente vuelve al hilo TAL CUAL lo devolvió la API: la API exige que
            // cada `tool` responda a un `tool_call_id` que esté en el mensaje anterior.
            $mensajes[] = $mensaje;

            foreach ($llamadas as $llamada) {
                $nombre = (string) ($llamada['function']['name'] ?? '');
                $crudos = (string) ($llamada['function']['arguments'] ?? '{}');

                // Los argumentos llegan como CADENA JSON, no como objeto. Un modelo puede mandar
                // JSON roto; se le contesta con el error en vez de reventar el turno.
                $argumentos = json_decode($crudos, true);
                if (!is_array($argumentos)) {
                    $argumentos = [];
                }

                $mensajes[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($llamada['id'] ?? ''),
                    'content' => $this->adaptador->ejecutar($skills, $nombre, $argumentos, $peticion->actor, $usadas),
                ];
            }
        }

        // 📏 La línea que dice si el caché está funcionando. `acierto` alto y `fallo` bajo es lo
        // que se busca; `fallo` alto en cada consulta significa que el prefijo cambia entre
        // conversaciones y no se está cacheando nada.
        $this->logger->info(sprintf(
            'Agent (deepseek): %s · %d vuelta(s) · caché acierto %d · caché fallo %d · salida %d tokens.',
            $modelo,
            $vueltas,
            $aciertoCache,
            $falloCache,
            $salida,
        ));

        if ($texto === null) {
            return ConversationResponse::vacia();
        }

        // Sin skill usada, la respuesta salió del modelo y no de los datos. Se entrega marcada:
        // cada canal decide si eso vale (el panel) o no (el chat del huésped).
        return $usadas === []
            ? ConversationResponse::sinSkill($texto)
            : ConversationResponse::ok($texto, array_values(array_unique($usadas)));
    }

    public function turnoDirecto(ConversationRequest $peticion, ?array $esquema = null): ?string
    {
        if (!$this->deepseek->estaConfigurado()) {
            return null;
        }

        $modelo = $peticion->modelo ?? $this->deepseek->modelo();

        $cuerpo = [
            'model' => $modelo,
            'messages' => [
                ...$this->bloquesDeSistema($peticion),
                ...$this->turnosPrevios($peticion->historial),
                ['role' => 'user', 'content' => $peticion->mensaje],
            ],
            'max_tokens' => $peticion->maxTokens,
            // Un turno seco no lleva catálogo, así que su prefijo es otro: `conCatalogo: false`
            // es lo que lo separa del turno con herramientas del mismo actor.
            'user_id' => DeepSeekClient::firmaDeCache(
                $peticion->actor->roles(),
                $peticion->actor->dominios(),
                conCatalogo: false,
                permiteEscritura: false,
                modelo: $modelo,
            ),
        ];

        // ⚠️ DeepSeek NO tiene structured outputs con esquema: sólo `json_object`, que obliga a
        // que la salida sea JSON válido pero **no a que case con nuestra forma**. El esquema se le
        // pide en el prompt y quien llama valida, que es lo que ya hace.
        //
        // La API además exige la palabra «json» en el prompt cuando se usa este modo, y responde
        // 400 si no está: se añade aquí en vez de confiar en que todos los prompts la lleven.
        if ($esquema !== null) {
            $cuerpo['response_format'] = ['type' => 'json_object'];
            $cuerpo['messages'][] = [
                'role' => 'user',
                'content' => 'Responde SÓLO con un json que cumpla este esquema, sin texto alrededor: '
                    .(json_encode($esquema, JSON_UNESCAPED_UNICODE) ?: '{}'),
            ];
        }

        try {
            $respuesta = $this->deepseek->completar($cuerpo);
        } catch (Throwable $e) {
            // Un turno seco es SIEMPRE un paso auxiliar —clasificar, redactar cortesía—, nunca la
            // única respuesta posible. Que falle no puede tumbar el mensaje del huésped.
            $this->logger->warning(sprintf(
                'Agent (deepseek): turno directo fallido para %s: %s',
                $peticion->actor->etiqueta(),
                $e->getMessage(),
            ));

            return null;
        }

        $uso = $respuesta['usage'] ?? [];
        $this->logger->info(sprintf(
            'Agent (deepseek): turno directo · %s · caché acierto %d · caché fallo %d · salida %d tokens.',
            $modelo,
            (int) ($uso['prompt_cache_hit_tokens'] ?? 0),
            (int) ($uso['prompt_cache_miss_tokens'] ?? 0),
            (int) ($uso['completion_tokens'] ?? 0),
        ));

        $motivoFin = (string) ($respuesta['choices'][0]['finish_reason'] ?? '');

        // ⚠️ `length` con esquema: el JSON viene CORTADO A MEDIA CLAVE y parece una respuesta.
        //
        // Es el mismo fallo que ya costó caro en Google y que su motor corrige explícitamente: el
        // triaje recibía `{"tipo":"peticion","skill` y lo daba por indeterminado, sin que nadie
        // relacionara el síntoma con el presupuesto de tokens. Devolver `null` manda al camino
        // largo, que es lo correcto, y el aviso dice dónde mirar.
        if ($motivoFin === 'length' && $esquema !== null) {
            $this->logger->warning(sprintf(
                'Agent (deepseek): turno directo cortado por max_tokens (%d) con esquema; el JSON '
                .'llegaría incompleto. Se descarta.',
                $peticion->maxTokens,
            ));

            return null;
        }

        // Filtrado por el proveedor: no hay respuesta, y devolver el trozo sería peor.
        if ($motivoFin === 'content_filter') {
            return null;
        }

        $texto = trim((string) ($respuesta['choices'][0]['message']['content'] ?? ''));

        return $texto === '' ? null : $texto;
    }

    /**
     * El `system` en dos mensajes: primero lo estable, después lo de esta conversación.
     *
     * 🔑 **El orden es el que decide si el caché sirve**, y aquí más que en ningún otro proveedor:
     * DeepSeek cachea por prefijo y no hay marca con la que corregir un orden malo. Lo volátil
     * —nombre del huésped, idioma— va DESPUÉS, así que es lo único que se paga entero.
     *
     * Dos mensajes y no uno concatenado a propósito: concatenando, cualquier cambio en el contexto
     * reescribiría la cadena entera y con ella el prefijo. Separados, el primero es byte a byte el
     * mismo en todas las conversaciones del mismo rol.
     *
     * @return list<array{role: string, content: string}>
     */
    private function bloquesDeSistema(ConversationRequest $peticion): array
    {
        $bloques = [['role' => 'system', 'content' => $peticion->systemPrompt]];

        $contexto = trim((string) $peticion->contexto);
        if ($contexto !== '') {
            $bloques[] = ['role' => 'system', 'content' => $contexto];
        }

        return $bloques;
    }

    /**
     * @param list<array{rol: string, texto: string}> $historial
     * @return list<array{role: string, content: string}>
     */
    private function turnosPrevios(array $historial): array
    {
        $turnos = [];

        foreach ($historial as $turno) {
            $texto = trim($turno['texto']);
            if ($texto === '') {
                continue;
            }

            $turnos[] = [
                'role' => $turno['rol'] === 'asistente' ? 'assistant' : 'user',
                'content' => mb_substr($texto, 0, 2000),
            ];
        }

        $turnos = array_slice($turnos, -self::MAX_TURNOS);

        // El hilo tiene que empezar por `user`; recortar por el final puede dejar un `assistant`
        // al principio.
        while ($turnos !== [] && $turnos[0]['role'] !== 'user') {
            array_shift($turnos);
        }

        return $turnos;
    }
}
