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
 * cosas. Medido sobre 873 mensajes reales (mayo-agosto 2026):
 *
 * - Solo el **37 %** de las continuaciones ocurría dentro de los 20 s configurados: al que
 *   escribe despacio se le contestaba a medias igual.
 * - Y el **81 %** de los mensajes no se continúa nunca: esperaban 20 s para nada.
 *
 * La espera correcta no depende del reloj, depende de si el mensaje **se lee como
 * terminado**. Eso es lo que decide este pre-router.
 *
 * ### Por qué las reglas son estas y no otras
 *
 * Tasa de continuación (≤40 s) medida por rasgo, sobre mensajes reales:
 *
 * | Rasgo | Mensajes | Continúa |
 * |---|---|---|
 * | Saludo suelto («hola», «buenas») | 2 % | **60 %** |
 * | Termina en `?` | 20 % | 12 % |
 * | 12+ palabras | 24 % | 8 % |
 * | Resto (2-11 palabras, sin `?`) | 54 % | 24 % |
 *
 * ⚠️ **La longitud NO sirve entre 2 y 11 palabras**: la curva es plana y ruidosa ahí
 * (13-27 % sin tendencia). Un umbral en esa banda sería inventado. «Pásame la reserva del 4»
 * son cinco palabras y está terminada; «hola, mira» son dos y no. Solo el significado las
 * distingue, y por eso esa banda —la mayoría del tráfico— es la única que va al modelo.
 *
 * ### La asimetría, que es lo importante
 *
 * Los dos errores NO cuestan lo mismo. Un «espera» equivocado retrasa la respuesta unos
 * segundos. Un «ejecuta» equivocado hace que el bot conteste a un «hola» mientras el huésped
 * todavía está escribiendo su pregunta — el desastre que la espera vino a evitar, y ahora
 * con el agente encendido le pasa a clientes reales.
 *
 * Por eso el prompt está sesgado a esperar y **cualquier fallo devuelve `true`**: modelo sin
 * credenciales, JSON roto, excepción, pre-router apagado. Nunca se arriesga a contestar
 * antes de tiempo por un problema técnico.
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
        $texto = trim(strip_tags((string) $mensaje->getContentLocal()));

        if ($texto === '') {
            return true;
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
