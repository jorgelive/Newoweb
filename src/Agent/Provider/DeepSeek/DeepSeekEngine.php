<?php

declare(strict_types=1);

namespace App\Agent\Provider\DeepSeek;

use App\Agent\Conversation\AgentEngineInterface;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\MotorSondeableInterface;
use App\Agent\Conversation\ConversationResponse;
use App\Agent\Conversation\FalloDelMotor;
use App\Agent\Skill\ControlDeVueltas;
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
 * ejecutarlas, añadirlas al hilo y volver a llamar. De ahí {@see ControlDeVueltas::MAX_VUELTAS}, que es la red
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
final readonly class DeepSeekEngine implements AgentEngineInterface, MotorSondeableInterface
{
    /** Turnos de ida y vuelta que se conservan. Acota el coste de un hilo largo. */
    private const int MAX_TURNOS = 12;


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

    /**
     * Una generación mínima, para la comprobación periódica: ver {@see MotorSondeableInterface}.
     */
    public function sondear(): ?FalloDelMotor
    {
        if (!$this->deepseek->estaConfigurado()) {
            return new FalloDelMotor('DeepSeek no tiene clave configurada.');
        }

        try {
            $this->deepseek->completar([
                'model' => $this->deepseek->modelo(),
                'messages' => [['role' => 'user', 'content' => 'Responde sólo: OK']],
                'max_tokens' => 8,
            ]);

            return null;
        } catch (Throwable $e) {
            return FalloDelMotor::de($e);
        }
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

        // Las protecciones del bucle, las mismas de Google (17/10/2026): ver `ControlDeVueltas`.
        $control = new ControlDeVueltas();

        for ($i = 0; $i < ControlDeVueltas::MAX_VUELTAS; ++$i) {
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

                // 🔁 Se PROPAGA, como en Anthropic y Google (revisión del 23/10/2026). Devolvía
                // `noDisponible()` y la suplencia —que decide con la excepción
                // (`AiConversationProcessor::conSuplente()`, `PanelAssistant::preguntar()`)— no veía
                // nunca un 401/402 de DeepSeek: el motor no salía de servicio y el turno acababa en
                // acuse. Al huésped no le llega el error: quien llama lo recoge y contesta el acuse,
                // igual que con los otros dos. Lo redactado en vueltas anteriores tampoco se entrega.
                throw $e;
            }

            $leida = DeepSeekRespuesta::fromArray($respuesta);
            $aciertoCache += $leida->consumo->cacheLeido;
            $falloCache += $leida->consumo->entradaSinCache();
            $salida += $leida->consumo->salida ?? 0;

            // Los filtros de contenido cortan la respuesta. Los otros dos motores lo distinguen
            // —Anthropic con `refusal`, Google con `FIN_RECHAZADO`— y el canal de arriba decide
            // qué hacer con eso. Sin esta rama, una respuesta filtrada salía como texto normal a
            // medias: peor que no contestar, porque parece una respuesta.
            if ($leida->motivoFin === 'content_filter') {
                $this->logger->warning(sprintf(
                    'Agent (deepseek): petición filtrada por el proveedor para %s.',
                    $peticion->actor->etiqueta(),
                ));

                return ConversationResponse::rechazada();
            }

            if (!$leida->hayTurno) {
                break;
            }

            // 🔥 Sólo cuenta el texto de una vuelta SIN herramientas (16/10/2026). El que acompaña a
            // una llamada es un preámbulo —«un momento, reviso tu reserva…»—, y se guardaba: si la
            // vuelta siguiente fallaba o se agotaba el tope, salía como respuesta final, marcada
            // `ia`, por WhatsApp. Ahora esos casos acaban en `noDisponible()`/`vacia()` y el canal
            // decide (el acuse genérico), en vez de dejar al huésped esperando algo que no llega.
            if ($leida->llamadas === []) {
                $contenido = trim($leida->texto);
                $texto = $contenido !== '' ? $contenido : null;

                break;
            }

            // ⚠️ En la ÚLTIMA vuelta no se ejecuta nada, y no es cosmético: ejecutar sin poder
            // volver a llamar significa que la skill escribe en la base y su resultado se tira,
            // porque no hay quien lo lea. Se le pide la respuesta SIN herramientas, con lo que ya
            // tiene en el hilo.
            if (ControlDeVueltas::esLaUltima($i)) {
                return $this->cerrarSinHerramientas($modelo, $tools, $mensajes, $peticion, $usadas, ControlDeVueltas::motivoDelTope());
            }

            // El turno del asistente vuelve al hilo TAL CUAL lo devolvió la API: la API exige que
            // cada `tool` responda a un `tool_call_id` que esté en el mensaje anterior.
            $mensajes[] = $leida->turnoCrudo;

            foreach ($leida->llamadas as $llamada) {
                // La misma skill con los mismos argumentos no se vuelve a ejecutar: el resultado ya
                // está en el hilo, y si escribe, escribiría dos veces.
                if ($control->yaServida($llamada->nombre, $llamada->argumentos)) {
                    $this->logger->warning(sprintf(
                        'Agent (deepseek): %s repite la llamada a "%s"; no se ejecuta.',
                        $peticion->actor->etiqueta(),
                        $llamada->nombre,
                    ));
                    $contenido = json_encode(ControlDeVueltas::respuestaRepetida(), JSON_UNESCAPED_UNICODE) ?: '{}';
                } else {
                    $contenido = $this->adaptador->ejecutar($skills, $llamada->nombre, $llamada->argumentos, $peticion->actor, $usadas);
                    $control->registrarJson($contenido);
                }

                $mensajes[] = [
                    'role' => 'tool',
                    'tool_call_id' => $llamada->id,
                    'content' => $contenido,
                ];
            }

            $motivo = $control->finDeVuelta();

            if ($motivo !== null) {
                return $this->cerrarSinHerramientas($modelo, $tools, $mensajes, $peticion, $usadas, $motivo);
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

        $leida = DeepSeekRespuesta::fromArray($respuesta);
        $this->logger->info(sprintf(
            'Agent (deepseek): turno directo · %s · caché acierto %d · caché fallo %d · salida %d tokens.',
            $modelo,
            $leida->consumo->cacheLeido,
            $leida->consumo->entradaSinCache(),
            $leida->consumo->salida ?? 0,
        ));

        $motivoFin = $leida->motivoFin;

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

        $texto = trim($leida->texto);

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
    /**
     * Le pide la respuesta final PROHIBIÉNDOLE llamar a nada más: `tool_choice: none` y la orden
     * de cierre en el hilo. Es el `cerrarSinHerramientas()` de Google, en el formato de esta API.
     * Antes, el tope entregaba «lo que hubiera» —un preámbulo— y una racha de fallos no paraba.
     *
     * Las `tools` siguen DECLARADAS: el hilo ya lleva llamadas y resultados, y lo que se retira es
     * el permiso de volver a llamarlas, no su definición.
     *
     * @param list<array<string, mixed>> $tools
     * @param list<array<array-key, mixed>> $mensajes
     * @param list<string> $usadas
     */
    private function cerrarSinHerramientas(
        string $modelo,
        array $tools,
        array $mensajes,
        ConversationRequest $peticion,
        array $usadas,
        string $motivo,
    ): ConversationResponse {
        $this->logger->warning(sprintf(
            'Agent (deepseek): cierre forzado para %s (%s); se le pide la respuesta SIN herramientas.',
            $peticion->actor->etiqueta(),
            $motivo,
        ));

        $mensajes[] = ['role' => 'user', 'content' => ControlDeVueltas::ORDEN_DE_CIERRE];

        try {
            $leida = DeepSeekRespuesta::fromArray($this->deepseek->completar([
                'model' => $modelo,
                'messages' => $mensajes,
                'tools' => $tools,
                'tool_choice' => 'none',
                'max_tokens' => $peticion->maxTokens,
                'user_id' => DeepSeekClient::firmaDeCache(
                    $peticion->actor->roles(),
                    $peticion->actor->dominios(),
                    conCatalogo: true,
                    permiteEscritura: $peticion->permitirEscritura,
                    modelo: $modelo,
                ),
            ]));
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Agent (deepseek): el cierre forzado falló: %s', $e->getMessage()));

            return ConversationResponse::vacia();
        }

        $texto = trim($leida->texto);

        if ($texto === '') {
            return ConversationResponse::vacia();
        }

        return $usadas === []
            ? ConversationResponse::sinSkill($texto)
            : ConversationResponse::ok($texto, array_values(array_unique($usadas)));
    }
}
