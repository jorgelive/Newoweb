<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Access\ActorInterface;
use App\Agent\Conversation\AgentEngineInterface;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\ConversationResponse;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * El asistente del panel: adaptador delgado entre la interfaz del equipo y el motor.
 *
 * Sólo aporta lo que es suyo — el prompt del contexto interno y la política de qué hacer
 * cuando el motor responde sin usar ninguna skill. Ni el modelo, ni las skills, ni los
 * permisos: eso vive en el motor y el registro.
 *
 * Ver docs/Mensajeria.md §11.
 */
final readonly class PanelAssistant
{
    /** Zona del negocio: decide qué es «hoy» y a qué año se refiere «12 de marzo». */
    private const string TZ = 'America/Lima';

    /** Techo de la pregunta. Corta pegotes accidentales antes de pagar tokens. */
    private const int MAX_CARACTERES = 500;

    public function __construct(
        private AgentEngineInterface $motor
    ) {}

    public function estaDisponible(): bool
    {
        return $this->motor->estaDisponible();
    }

    /**
     * @param list<array{rol: string, texto: string}> $historial Turnos previos del hilo.
     * @return array{respuesta: string, herramientas: list<string>}
     */
    public function preguntar(string $pregunta, ActorInterface $actor, array $historial = []): array
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

        $respuesta = $this->motor->conversar(new ConversationRequest(
            actor: $actor,
            systemPrompt: $this->systemPrompt(),
            mensaje: $pregunta,
            historial: $historial,
        ));

        return [
            'respuesta' => $this->texto($respuesta),
            'herramientas' => $respuesta->skillsUsadas,
        ];
    }

    /**
     * Aquí se decide qué ve el operador en cada desenlace.
     *
     * A diferencia del chat del huésped, un `sin_skill` **sí se muestra**: quien pregunta es
     * un compañero que sabe interpretar un «esto no lo sé hacer», y saberlo es más útil que
     * un silencio.
     */
    private function texto(ConversationResponse $respuesta): string
    {
        return match ($respuesta->motivo) {
            'sin_permisos' => 'No tienes permisos para consultar nada por aquí.',
            'motor_no_disponible' => 'El asistente no está configurado en este entorno.',
            'rechazado' => 'No he podido procesar esa consulta.',
            default => $respuesta->tieneTexto()
                ? (string) $respuesta->texto
                : 'No he sabido responder a eso.',
        };
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

        Cómo habla el equipo:
        - «pasajero» y «huésped» son lo mismo. Se opera también como agencia, así que los dos
          términos se mezclan a diario y ninguno tiene un significado propio en el sistema.
          Las skills dicen «huésped»; si te preguntan por «el pasajero», es la misma persona.
        - «casita» es la unidad alojable. «La 1», «casita 1» y «Casita 1» son la misma.

        Reglas:
        - Para cualquier dato del PMS, LLAMA a la skill correspondiente. Nunca respondas de
          memoria ni estimes: si falla, dilo.
        - Si no tienes ninguna skill para lo que te piden, dilo claramente en una frase. No
          improvises una respuesta ni prometas hacerlo luego.
        - Responde en español por defecto, breve y directo, como un compañero: sin preámbulos
          ni resúmenes de lo que vas a hacer.
        - Si te piden un texto para enviárselo a un huésped —«pásame su estado de cuenta para
          copiárselo», «escríbeselo en inglés»—, redáctalo en el idioma que te pidan, o en el
          del huésped si no lo dicen: las skills devuelven `idioma_huesped` cuando lo saben.
          Trabajamos en español, inglés, portugués, francés, italiano, alemán y neerlandés.
        - Los importes se dicen con su moneda y sin convertir: si la cuenta está en dólares,
          se habla en dólares.
        - Al listar casitas, di cuántas hay y nómbralas con su capacidad y tarifa base. Si no
          hay ninguna, dilo en una frase.
        - La tarifa base es orientativa: no la presentes como el precio final de venta.
        - Si una skill devuelve varias coincidencias (dos huéspedes con el mismo nombre, por
          ejemplo), NO elijas: enséñalas con el dato que las distingue —fechas, casita,
          localizador— y pregunta cuál. Es una conversación: puedes repreguntar y continuar
          con lo que te respondan.
        PROMPT;
    }
}
