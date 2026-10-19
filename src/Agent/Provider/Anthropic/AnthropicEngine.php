<?php

declare(strict_types=1);

namespace App\Agent\Provider\Anthropic;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use App\Agent\Conversation\AgentEngineInterface;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\ConversationResponse;
use App\Agent\Conversation\MotorSondeableInterface;
use App\Agent\Skill\SkillRegistry;
use Anthropic\Lib\Tools\BetaToolRunner;
use App\Agent\Skill\ControlDeVueltas;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Motor de conversación sobre la API de Anthropic.
 *
 * Todo lo específico del proveedor —el SDK, el tool runner, el caché de prompts, el control
 * de rechazos— vive aquí dentro. Quien lo usa sólo conoce {@see AgentEngineInterface}.
 *
 * Ver docs/Mensajeria.md §11.
 */
final readonly class AnthropicEngine implements AgentEngineInterface, MotorSondeableInterface
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
        // Las protecciones del bucle, las mismas de Google y DeepSeek (17/10/2026). El `toolRunner`
        // del SDK se queda: la llamada repetida se corta dentro de cada herramienta (ver el
        // adaptador) y la racha y el tope se cortan aquí, dejando de pedirle mensajes al runner.
        $control = new ControlDeVueltas();
        $tools = $this->adaptador->traducir($skills, $peticion->actor, $usadas, $control);

        $mensajes = [
            ...$this->turnosPrevios($peticion->historial),
            ['role' => 'user', 'content' => $peticion->mensaje],
        ];

        $texto = null;
        $vueltas = 0;
        $entrada = 0;
        $cacheLeido = 0;
        $cacheEscrito = 0;
        $salida = 0;

        // ⚠️ `system` va en `extraParams`, NO como argumento con nombre. `toolRunner()` sólo
        // declara maxTokens, messages, model, tools, maxIterations y extraParams; pasarle
        // `system:` suelto es un `Error: Unknown named parameter`, y revienta el turno entero
        // antes de llegar a la API. Lo tuvo un tiempo y no saltó porque el proveedor por
        // defecto era Google y esta rama no se ejecutaba nunca.
        $runner = $cliente->beta->messages->toolRunner(
            maxTokens: $peticion->maxTokens,
            messages: $mensajes,
            // La petición manda cuando trae modelo (el desplegable del panel, o el tramo de
            // potencia); el registro ya comprobó que pertenece a este proveedor.
            model: $peticion->modelo ?? $this->anthropic->modelo(),
            tools: $tools,
            // 🔒 Un tope, como los otros dos motores (16/10/2026). Sin él `BetaToolRunner` gira
            // SIN LÍMITE: un modelo en bucle llamando a la misma skill no paraba nunca.
            maxIterations: ControlDeVueltas::MAX_VUELTAS,
            extraParams: ['system' => $this->bloquesDeSistema($peticion)],
        );

        $cerrando = false;

        foreach ($runner as $mensaje) {
            $vueltas++;
            $uso = $mensaje->usage ?? null;
            if ($uso !== null) {
                $entrada += $uso->inputTokens;
                $cacheLeido += $uso->cacheReadInputTokens ?? 0;
                $cacheEscrito += $uso->cacheCreationInputTokens ?? 0;
                $salida += $uso->outputTokens;
            }

            // Los clasificadores pueden declinar: llega un 200 con `content` vacío. Leer
            // content[0] sin comprobarlo revienta.
            if ($mensaje->stopReason === 'refusal') {
                $this->logger->warning(sprintf(
                    'Agent: petición declinada por los clasificadores para %s.',
                    $peticion->actor->etiqueta()
                ));

                return ConversationResponse::rechazada();
            }

            // Las herramientas de la vuelta anterior ya se ejecutaron: el runner lo hace al pedirle
            // este mensaje. Se cierra esa vuelta para llevar la racha de fallos. (El mensaje del
            // cierre forzado no viene de ninguna vuelta: no cuenta.)
            $motivo = $vueltas > 1 && !$cerrando ? $control->finDeVuelta() : null;

            // 🔥 Sólo cuenta el texto de un mensaje que NO pide herramientas (16/10/2026): el que
            // acompaña a un `tool_use` es un preámbulo —«un momento, reviso tu reserva…»—, y si
            // la vuelta siguiente no traía texto o se agotaba el tope, salía como respuesta final.
            if ($mensaje->stopReason === 'tool_use') {
                // Racha de fallos, o la última vuelta: se le pide la respuesta SIN herramientas, y
                // las que pide este mensaje no se ejecutan. Ver `forzarCierre()`. Con la racha se
                // tira esta petición ya hecha: es el precio de no sustituir el runner, y sólo se
                // paga cuando el turno ya iba mal.
                if (!$cerrando && ($motivo !== null || ControlDeVueltas::esLaUltima($vueltas - 1))) {
                    $this->forzarCierre($runner, $mensaje, $peticion, $motivo ?? ControlDeVueltas::motivoDelTope());
                    $cerrando = true;
                }

                continue;
            }

            foreach ($mensaje->content as $bloque) {
                // `instanceof` y no `->type === 'text'`: el SDK devuelve una unión de clases de
                // bloque y sólo `BetaTextBlock` tiene `->text`. Comparar la propiedad `type`
                // acierta en ejecución pero no estrecha el tipo, así que el acceso de la línea
                // siguiente iba sobre una unión donde ese campo no está declarado.
                if ($bloque instanceof BetaTextBlock && trim($bloque->text) !== '') {
                    $texto = $bloque->text;
                }
            }
        }

        // 📏 La línea que dice si el caché está funcionando. `caché leído` alto y `escrito` a 0
        // es lo que se busca; `escrito` alto en cada consulta significa que el prefijo cambia
        // entre conversaciones y se está pagando 1,25× por un caché que no lee nadie.
        $this->logger->info(sprintf(
            // Con el MODELO, como la de Google y la de DeepSeek: con varios en el mismo proveedor
            // (Sonnet y Haiku), un coste sin modelo no se puede atribuir.
            'Agent (anthropic): %s · %d vuelta(s) · entrada %d · caché leído %d · caché escrito %d · salida %d tokens.',
            $peticion->modelo ?? $this->anthropic->modelo(),
            $vueltas,
            $entrada,
            $cacheLeido,
            $cacheEscrito,
            $salida
        ));

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
     * Pide al runner la respuesta final PROHIBIÉNDOLE llamar a nada más: `toolChoice: none` y la
     * orden de cierre en el hilo. El `cerrarSinHerramientas()` de Google, sin salir del runner.
     *
     * 🔑 `setMessagesParams()` durante la iteración es la palanca que da el SDK: el runner entiende
     * que llevamos el historial a mano, así que NO añade el mensaje en curso ni ejecuta sus
     * herramientas, y la siguiente vuelta es una petición con lo que le damos. Así no hace falta
     * sustituir el `toolRunner` por un bucle propio.
     *
     * ⚠️ **Sólo AÑADE, nunca edita un turno ya enviado** (18/10/2026). La primera versión metía la
     * orden en el último turno de usuario, que ya había viajado en la petición anterior: Sonnet 5.5
     * y Haiku 5.5 tratan eso como edición del historial, invalidan el razonamiento y, en cuentas
     * creadas desde el 31/08/2026, responden 400. Ahora se añade el mensaje en curso tal cual llegó
     * (con sus bloques de razonamiento, sin tocar), un `tool_result` de error por cada herramienta
     * que pidió —«no se ejecutó»— y la orden detrás, en el mismo turno de usuario.
     *
     * Las herramientas siguen declaradas: el hilo lleva `tool_use`, y lo que se retira es el
     * permiso, no la definición. `maxIterations: null` para que el tope del runner no se coma la
     * petición del cierre.
     */
    private function forzarCierre(BetaToolRunner $runner, BetaMessage $enCurso, ConversationRequest $peticion, string $motivo): void
    {
        $this->logger->warning(sprintf(
            'Agent (anthropic): cierre forzado para %s (%s); se le pide la respuesta SIN herramientas.',
            $peticion->actor->etiqueta(),
            $motivo,
        ));

        $runner->setMessagesParams(static function (array $params) use ($enCurso): array {
            $mensajes = is_array($params['messages'] ?? null) ? array_values($params['messages']) : [];
            $mensajes[] = ['role' => 'assistant', 'content' => $enCurso->content];

            $respuesta = [];
            foreach ($enCurso->content as $bloque) {
                if ($bloque instanceof BetaToolUseBlock) {
                    $respuesta[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $bloque->id,
                        'content' => 'No se ejecutó: ya no puedes usar más herramientas.',
                        'is_error' => true,
                    ];
                }
            }
            $respuesta[] = ['type' => 'text', 'text' => ControlDeVueltas::ORDEN_DE_CIERRE];
            $mensajes[] = ['role' => 'user', 'content' => $respuesta];

            return [
                'messages' => $mensajes,
                'toolChoice' => ['type' => 'none'],
                'maxIterations' => null,
            ];
        });
    }

    /**
     * Una generación mínima, para la comprobación periódica: ver {@see MotorSondeableInterface}.
     */
    public function sondear(): ?string
    {
        $cliente = $this->anthropic->crear();

        if ($cliente === null) {
            return 'Anthropic no tiene clave configurada.';
        }

        try {
            $cliente->beta->messages->create(
                maxTokens: 8,
                messages: [['role' => 'user', 'content' => 'Responde sólo: OK']],
                model: $this->anthropic->modelo(),
            );

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    public function turnoDirecto(ConversationRequest $peticion, ?array $esquema = null): ?string
    {
        $cliente = $this->anthropic->crear();
        if ($cliente === null) {
            return null;
        }

        $parametros = [
            'maxTokens' => $peticion->maxTokens,
            'messages' => [
                ...$this->turnosPrevios($peticion->historial),
                ['role' => 'user', 'content' => $peticion->mensaje],
            ],
            'model' => $peticion->modelo ?? $this->anthropic->modelo(),
            'system' => $this->bloquesDeSistema($peticion),
        ];

        // Structured outputs: el proveedor obliga a que la salida case con el esquema, así que
        // no hay que defenderse de un ```json ni de un «Claro, aquí tienes:» delante. Va por
        // `outputConfig.format`; `outputFormat` a secas está deprecado en el SDK.
        if ($esquema !== null) {
            $parametros['outputConfig'] = ['format' => ['type' => 'json_schema', 'schema' => $esquema]];
        }

        try {
            // Todo va desplegado como argumentos CON NOMBRE. `create()` tiene una veintena de
            // parámetros opcionales en medio, así que por posición es imposible de mantener.
            $mensaje = $cliente->beta->messages->create(...$parametros);
        } catch (Throwable $e) {
            // Un turno seco es SIEMPRE un paso auxiliar —clasificar, redactar cortesía—, nunca
            // la única respuesta posible. Que falle no puede tumbar el mensaje del huésped: se
            // devuelve `null` y quien llama se cae al camino largo, que es el de siempre.
            $this->logger->warning(sprintf(
                'Agent (anthropic): turno directo fallido para %s: %s',
                $peticion->actor->etiqueta(),
                $e->getMessage()
            ));

            return null;
        }

        $uso = $mensaje->usage ?? null;
        $this->logger->info(sprintf(
            'Agent (anthropic): turno directo · %s · entrada %d · caché leído %d · caché escrito %d · salida %d tokens.',
            $peticion->modelo ?? $this->anthropic->modelo(),
            $uso->inputTokens ?? 0,
            $uso->cacheReadInputTokens ?? 0,
            $uso->cacheCreationInputTokens ?? 0,
            $uso->outputTokens ?? 0
        ));

        if ($mensaje->stopReason === 'refusal') {
            return null;
        }

        $texto = '';
        foreach ($mensaje->content as $bloque) {
            if ($bloque instanceof BetaTextBlock) {
                $texto .= $bloque->text;
            }
        }

        return trim($texto) === '' ? null : $texto;
    }

    /**
     * El `system` en dos bloques: primero lo estable, después lo de esta conversación.
     *
     * 🔑 **El orden es el que decide si el caché sirve.** La marca de la última herramienta ya
     * cachea el catálogo; esta segunda marca extiende el prefijo cacheado a las reglas, que
     * también son idénticas para todos. Lo volátil —nombre del huésped, idioma— va DESPUÉS y
     * sin marca, así que es lo único que se paga entero en cada consulta: unas decenas de
     * tokens frente a los miles del catálogo.
     *
     * Meter el nombre arriba, como estaba, hace que el prefijo sea distinto en cada
     * conversación y el caché no acierte jamás.
     *
     * ⚠️ La forma es la que pide el SDK, con los tipos LITERALES: `type` es exactamente
     * `'text'`, no un `string` cualquiera. Declararlo laxo hacía que la llamada a `create()`
     * no se comprobara contra nada — y el día que el SDK cambie esa unión, el aviso saldría
     * aquí en vez de en una petición fallida a la API.
     *
     * @return list<array{type: 'text', text: string, cacheControl?: array{type: 'ephemeral', ttl: '1h'}}>
     */
    private function bloquesDeSistema(ConversationRequest $peticion): array
    {
        $bloques = [[
            'type' => 'text',
            'text' => $peticion->systemPrompt,
            'cacheControl' => ['type' => 'ephemeral', 'ttl' => '1h'],
        ]];

        $contexto = trim((string) $peticion->contexto);
        if ($contexto !== '') {
            $bloques[] = ['type' => 'text', 'text' => $contexto];
        }

        return $bloques;
    }

    /**
     * @param list<array{rol: string, texto: string}> $historial
     * @return list<array{role: 'user'|'assistant', content: string}> `role` es una unión
     *         cerrada en el SDK: con `string` a secas la llamada no se comprobaba.
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
