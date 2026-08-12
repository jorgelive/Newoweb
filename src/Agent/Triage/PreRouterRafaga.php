<?php

declare(strict_types=1);

namespace App\Agent\Triage;

use App\Agent\Access\AgentActorFactory;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Conversation\SelectorDePotencia;
use App\Message\Entity\Message;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Decide si un mensaje entrante está TERMINADO o el huésped sigue escribiendo.
 *
 * ### Por qué existe
 *
 * La espera de ráfaga era un número fijo, y un número fijo no puede servir para las dos
 * cosas: al que escribe despacio se le contestaba a medias igual, y el que ya había terminado
 * esperaba para nada.
 *
 * La espera correcta no depende del reloj, depende de si el mensaje **se lee como
 * terminado**. Eso es lo que decide este pre-router.
 *
 * ### Por qué las reglas son estas y no otras
 *
 * Tasa de continuación (≤40 s) medida sobre **2.087 mensajes entrantes reales** (remedido en
 * agosto de 2026; la primera versión se decidió sobre 873 y sus cifras ya no valen):
 *
 * | Rasgo | Tráfico | Continúa | Decisión |
 * |---|---|---|---|
 * | Saludo suelto («hola», «buenas») | 8 % | **43,5 %** | espera |
 * | Termina en `?` | 13,6 % | 14,1 % | ejecuta |
 * | 12+ palabras | 23,4 % | 13,7 % | ejecuta |
 * | Resto (2-11 palabras, sin `?`) | 53,2 % | 27,1 % | al modelo |
 *
 * ⚠️ **La longitud NO sirve entre 2 y 11 palabras.** Medida por bandas, excluyendo saludos y
 * preguntas cerradas:
 *
 * ```
 *   2-3 palabras   515   29,9 %
 *   4-6 palabras   341   25,8 %
 *  7-11 palabras   291   22,7 %
 * 12-17 palabras   402   14,2 %   ← el escalón está aquí
 * 18-29 palabras    86   11,6 %
 * ```
 *
 * De 2 a 11 la caída es de 29,9 a 22,7: demasiado poco para partir la banda, y por eso esa
 * mayoría del tráfico es la única que va al modelo. «Pásame la reserva del 4» son cinco
 * palabras y está terminada; «hola, mira» son dos y no. El salto de verdad está en 12, y
 * subir el umbral a 18 no compra casi nada.
 *
 * 🚫 **Probado y descartado: una regla para preguntas sin `?`.** En WhatsApp se escriben
 * constantemente («Que costo tiene»), pero son el 1,8 % del tráfico —37 mensajes— y continúan
 * el 18,9 %, indistinguible del resto con esa muestra. No merece regla; queda escrito para que
 * nadie la vuelva a proponer sin medirla.
 *
 * ### La asimetría, que es lo importante
 *
 * Los dos errores NO cuestan lo mismo. Un «espera» equivocado retrasa la respuesta unos
 * segundos. Un «ejecuta» equivocado hace que el bot conteste a un «hola» mientras el huésped
 * todavía está escribiendo su pregunta — el desastre que la espera vino a evitar, y ahora
 * con el agente encendido le pasa a clientes reales.
 *
 * Por eso el prompt está sesgado a esperar y **cualquier FALLO devuelve `true`**: modelo sin
 * credenciales, JSON roto, excepción, pre-router apagado. Nunca se arriesga a contestar antes
 * de tiempo por un problema técnico.
 *
 * La única excepción es un mensaje SIN TEXTO —un audio, una foto—, que sí pasa: ahí no hay
 * nada que clasificar y esperar sólo retrasa. Ver `debeEsperar()`.
 */
final readonly class PreRouterRafaga
{
    /** Un saludo suelto es el caso más peligroso: continúa el 60 % de las veces. */
    private const string SALUDO_SUELTO = '/^\s*(hola|buenas|buenos d[ií]as|buenas tardes|buenas noches|hi|hello|hey|ola)\s*[[:punct:]]*\s*$/iu';

    /**
     * A partir de aquí la longitud sí separa: 12+ palabras continúa solo el 8 %.
     * Por debajo la curva es plana y la decisión se le pasa al modelo.
     */
    private const int PALABRAS_COMPLETO = 12;

    private const string SYSTEM_PROMPT = <<<'TXT'
        Decides si un huésped ha TERMINADO de escribir o va a seguir en otro mensaje.

        Responde solo "ejecuta" o "espera".

        - "ejecuta": el mensaje expresa una petición, pregunta, dato o cierre completos.
          Ejemplos: "pásame la reserva del 4", "llegamos 9pm", "ya está, gracias",
          "no funciona la cocina".
        - "espera": es un saludo, una apertura, una frase cortada o algo que claramente
          pide continuación. Ejemplos: "hola", "una consulta", "mira", "buenas, quería".

        ANTE LA DUDA, RESPONDE "espera". Contestar a medias a alguien que sigue escribiendo
        es mucho peor que tardar unos segundos más.
        TXT;

    public function __construct(
        private SelectorDePotencia $potencias,
        private AgentActorFactory $actores,
        private LoggerInterface $logger,
        #[Autowire('%env(bool:AGENT_IA_PREROUTER)%')]
        private bool $habilitado,
        #[Autowire('%env(AGENT_IA_PREROUTER_POTENCIA)%')]
        private string $potencia,
    ) {}

    /**
     * ¿Hay que seguir esperando antes de contestar a este mensaje?
     *
     * Se llama desde el WORKER, nunca desde el listener de Doctrine: hace una petición de
     * red y el listener corre dentro de un flush.
     */
    public function debeEsperar(Message $mensaje): bool
    {
        // Una sola forma de leer el mensaje en todo el agente: ver `getTextoEntrante()`.
        // Leerlo de una columna concreta hacía que este pre-router pudiera quedarse con la
        // cadena vacía y esperar siempre, en silencio.
        $texto = trim(strip_tags($mensaje->getTextoEntrante()));

        // Sin texto no hay nada que clasificar: un audio, una foto, una ubicación. Esperar la
        // ventana entera sólo retrasa lo que igualmente se va a procesar tal cual, porque en
        // el reintento `yaEsperado` salta este pre-router. Se deja pasar.
        //
        // El riesgo asumido —que llegue un audio y detrás el texto explicándolo— lo sigue
        // cubriendo el guardia de ráfaga de `AiConversationProcessor`, que es la segunda red y
        // sí mira si el huésped escribió mientras se generaba la respuesta.
        if ($texto === '') {
            return false;
        }

        // Regla 1 — saludo suelto. Es el rasgo con la señal más fuerte de todos y no
        // necesita modelo: 60 % de continuación frente al 19 % general.
        if (preg_match(self::SALUDO_SUELTO, $texto) === 1) {
            return true;
        }

        // Regla 2 — una pregunta cerrada está terminada (12 % de continuación).
        if (str_ends_with($texto, '?') || str_ends_with($texto, '？')) {
            return false;
        }

        // Regla 3 — mensaje largo (8 % de continuación). Nadie escribe doce palabras y
        // sigue en otro mensaje.
        if (self::palabras($texto) >= self::PALABRAS_COMPLETO) {
            return false;
        }

        // Zona ambigua: la mayoría del tráfico. Aquí sí decide el modelo.
        return $this->preguntarAlModelo($texto, $mensaje);
    }

    private function preguntarAlModelo(string $texto, Message $mensaje): bool
    {
        if (!$this->habilitado) {
            return true;
        }

        $elegido = $this->potencias->elegir(PotenciaRequerida::desde($this->potencia));

        if ($elegido === null) {
            $this->logger->warning('[PreRouter] Sin motor disponible; se espera por defecto.');

            return true;
        }

        try {
            $conversacion = $mensaje->getConversation();

            $salida = $elegido->motor->turnoDirecto(new ConversationRequest(
                actor: $this->actores->huesped(
                    (string) ($mensaje->getChannel()?->getId() ?? 'chat'),
                    $conversacion?->getContextType(),
                    $conversacion?->getContextId(),
                ),
                systemPrompt: self::SYSTEM_PROMPT,
                mensaje: $texto,
                permitirEscritura: false,
                // Holgado a propósito: los modelos que razonan gastan parte del presupuesto
                // pensando y con un tope corto devuelven vacío. La salida es una palabra;
                // esto es una red de seguridad, no un control de longitud.
                maxTokens: 512,
                modelo: $elegido->modelo,
            ));
        } catch (Throwable $e) {
            $this->logger->warning('[PreRouter] Falló la consulta; se espera por defecto: ' . $e->getMessage());

            return true;
        }

        // Se busca «ejecuta» explícitamente en vez de descartar «espera»: si el modelo
        // devuelve null, vacío o cualquier otra cosa, el resultado es esperar.
        $decision = str_contains(mb_strtolower(trim((string) $salida)), 'ejecuta');

        $this->logger->info(sprintf(
            '[PreRouter] «%s» → %s (%s)',
            mb_strimwidth($texto, 0, 40, '…'),
            $decision ? 'ejecuta' : 'espera',
            $elegido->etiqueta()
        ));

        return !$decision;
    }

    private static function palabras(string $texto): int
    {
        return count(preg_split('/\s+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
