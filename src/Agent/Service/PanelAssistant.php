<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Tool\AgentActor;
use App\Agent\Tool\AgentToolRegistry;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * El asistente del panel interno: responde en lenguaje natural a preguntas del equipo
 * («¿qué casitas tengo libres del 12 al 15 de marzo?», «¿tengo algo para 3 personas?»).
 *
 * NO es el bot del huésped. Son dos productos distintos y a propósito no comparten nada
 * salvo la fábrica del cliente:
 *
 * | | Autoresponder ({@see AiConversationProcessor}) | Este |
 * |---|---|---|
 * | Quién pregunta | Un huésped, por WhatsApp o una OTA | Un empleado autenticado |
 * | Qué hace | Genera texto y lo ENVÍA a un cliente real | Consulta y responde en pantalla |
 * | Riesgo | Alto: escribe fuera | Bajo: sólo lee |
 *
 * Por eso este no pasa por `MessageConversation` ni por el motor de reglas: forzarlo ahí
 * obligaría a inventar conversaciones falsas y contextos que no existen.
 *
 * Ver docs/Mensajeria.md §11.
 */
final readonly class PanelAssistant
{
    /** Zona del negocio: decide qué es «hoy» y, con ello, a qué año se refiere «12 de marzo». */
    private const string TZ = 'America/Lima';

    /** Techo de la pregunta. Corta pegotes accidentales antes de pagar tokens. */
    private const int MAX_CARACTERES = 500;

    public function __construct(
        private AnthropicClientFactory $anthropic,
        private AgentToolRegistry $herramientas,
        private LoggerInterface $logger,
    ) {}

    public function estaDisponible(): bool
    {
        return $this->anthropic->estaConfigurado();
    }

    /**
     * @return array{respuesta: string, herramientas: list<string>}
     */
    public function preguntar(string $pregunta, AgentActor $actor): array
    {
        $pregunta = trim($pregunta);

        if ($pregunta === '') {
            throw new InvalidArgumentException('Escribe una pregunta.');
        }

        if (mb_strlen($pregunta) > self::MAX_CARACTERES) {
            throw new InvalidArgumentException(sprintf(
                'La pregunta no puede pasar de %d caracteres.',
                self::MAX_CARACTERES
            ));
        }

        $cliente = $this->anthropic->crear();
        if ($cliente === null) {
            throw new RuntimeException('El asistente no está configurado: falta ANTHROPIC_API_KEY.');
        }

        $usadas = [];

        // El registro decide qué ve este actor: a limpieza no se le mencionan siquiera las
        // herramientas de reservas. Ver AgentToolRegistry.
        $tools = $this->herramientas->comoRunnables($actor, $usadas);

        if ($tools === []) {
            return [
                'respuesta' => 'No tienes permisos para consultar nada por aquí.',
                'herramientas' => [],
            ];
        }

        $runner = $cliente->beta->messages->toolRunner(
            maxTokens: 4096,
            messages: [['role' => 'user', 'content' => $pregunta]],
            model: $this->anthropic->modelo(),
            tools: $tools,
            system: [[
                'type' => 'text',
                'text' => $this->systemPrompt(),
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
        );

        $respuesta = '';

        // El runner itera: pregunta → llamada a la herramienta → resultado → respuesta final.
        // Nos quedamos con el texto del último turno, que es el que contesta al usuario.
        foreach ($runner as $mensaje) {
            if ($mensaje->stopReason === 'refusal') {
                $this->logger->warning('Asistente del panel: petición declinada por los clasificadores.');
                return ['respuesta' => 'No he podido procesar esa consulta.', 'herramientas' => $usadas];
            }

            foreach ($mensaje->content as $bloque) {
                if ($bloque->type === 'text' && trim($bloque->text) !== '') {
                    $respuesta = $bloque->text;
                }
            }
        }

        return [
            'respuesta' => trim($respuesta) !== '' ? $respuesta : 'No he sabido responder a eso.',
            'herramientas' => $usadas,
        ];
    }

    private function systemPrompt(): string
    {
        $hoy = new DateTimeImmutable('now', new DateTimeZone(self::TZ));

        return <<<PROMPT
        Eres el asistente interno del equipo de reservas de un alojamiento en Cusco, Perú.
        Hablas con un compañero de trabajo, no con un huésped.

        Hoy es {$hoy->format('Y-m-d')} ({$hoy->format('l')}), zona horaria America/Lima.
        Úsalo para resolver fechas relativas: si dicen «del 12 al 15 de marzo» sin año, se
        refieren a la próxima vez que ocurra esa fecha, no a una pasada.

        Reglas:
        - Para cualquier pregunta de disponibilidad, LLAMA a la herramienta. Nunca respondas
          de memoria ni estimes: si la herramienta falla, dilo.
        - Responde en español, breve y directo, como un compañero: sin preámbulos ni resúmenes
          de lo que vas a hacer.
        - Al listar casitas, di cuántas hay y nómbralas con su capacidad y tarifa base.
          Si no hay ninguna, dilo claramente en una frase.
        - La tarifa base es sólo orientativa: no la presentes como el precio final de la venta.
        - Si la pregunta es ambigua en fechas, pide la aclaración concreta que te falta.
        PROMPT;
    }
}
