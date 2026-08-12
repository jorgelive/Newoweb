<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Conversation\ReglasCompartidas;
use App\Agent\Access\ActorInterface;
use App\Agent\Conversation\AgentEngineRegistry;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\ConversationResponse;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * El asistente por voz: hermano de {@see PanelAssistant}, para un canal que no tiene pantalla.
 *
 * Comparte con él todo lo que importa —las mismas skills, el mismo motor, los mismos permisos—
 * y se separa en las tres cosas que el canal impone. No hereda ni envuelve a `PanelAssistant`
 * a propósito: su prompt es de pantalla y su política de escritura es la contraria, así que
 * reutilizarlo obligaría a llenarlo de condicionales por canal. Duplicar treinta líneas de
 * orquestación sale más barato que eso, y es lo que ya hace el propio `PanelAssistant`
 * respecto del chat del huésped.
 *
 * ### 1. Solo lectura, sin excepciones
 *
 * `permitirEscritura: false`. No es una preferencia: un Echo no autentica a quien habla
 * ({@see \App\Agent\Alexa\AlexaUsuarios}), y las skills de escritura de este sistema mueven
 * dinero —`RegistrarPagoSkill`, `RegistrarCargoSkill`— o cambian reservas. El registro ya
 * sabe filtrarlas y además deja pasar `NivelRiesgo::Interna`, así que avisar al equipo sigue
 * siendo posible por voz.
 *
 * ### 2. Se habla, no se lee
 *
 * El prompt del panel pide respuestas breves, pero permite listas y tablas. Dictadas en voz
 * alta son inservibles. Aquí se exige prosa corta y sin marcas.
 *
 * ### 3. Prisa
 *
 * Alexa corta la petición a los ~8 s. `maxTokens` va bajo y el modelo se puede fijar por
 * entorno a uno rápido sin tocar el del panel. Ver docs/AgentVoz.md §6.
 */
final readonly class VoiceAssistant
{
    private const string TZ = 'America/Lima';

    /** Un turno hablado no pasa de dos frases; pedir más sólo gasta segundos. */
    private const int MAX_TOKENS = 600;

    public function __construct(
        private AgentEngineRegistry $motores,
        #[Autowire('%env(default::ALEXA_PROVEEDOR)%')]
        private ?string $proveedor = null,
        #[Autowire('%env(default::ALEXA_MODELO)%')]
        private ?string $modelo = null,
    ) {}

    public function estaDisponible(): bool
    {
        return $this->motores->hayDisponible();
    }

    /**
     * @param list<array{rol: string, texto: string}> $historial
     * @return string Siempre algo hablable: quien llama no tiene forma de improvisar un texto.
     */
    public function preguntar(string $pregunta, ActorInterface $actor, array $historial = []): string
    {
        $pregunta = trim($pregunta);

        if ($pregunta === '') {
            return 'No te he entendido. ¿Me lo repites?';
        }

        $motor = $this->motores->elegir($this->proveedor);
        if ($motor === null) {
            return 'El asistente no está configurado.';
        }

        $respuesta = $motor->conversar(new ConversationRequest(
            actor: $actor,
            systemPrompt: $this->systemPrompt(),
            mensaje: $pregunta,
            historial: $historial,
            permitirEscritura: false,
            maxTokens: self::MAX_TOKENS,
            modelo: $this->motores->validarModelo($motor, $this->modelo),
        ));

        return $this->texto($respuesta);
    }

    /**
     * Igual que en el panel, un `sin_skill` se dice tal cual: quien pregunta es un compañero y
     * un «esto no lo sé hacer» le sirve. Lo que no puede pasar es devolver vacío, porque el
     * dispositivo se quedaría callado y nadie sabría si escuchó.
     */
    private function texto(ConversationResponse $respuesta): string
    {
        return match ($respuesta->motivo) {
            'sin_permisos' => 'No tienes permiso para consultar esto por voz.',
            'motor_no_disponible' => 'El asistente no está configurado.',
            'rechazado' => 'No he podido procesar esa consulta.',
            default => $respuesta->tieneTexto()
                ? (string) $respuesta->texto
                : 'No he sabido responder a eso.',
        };
    }

    /**
     * ⚠️ Este texto es la parte CACHEADA del prompt (`cacheControl` en
     * `AnthropicEngine::bloquesDeSistema()`): tiene que salir idéntico byte a byte en todas las
     * consultas del canal. La fecha de hoy cambia una vez al día y eso es asumible —invalida el
     * caché a medianoche—, pero no metas aquí nada que cambie por consulta.
     */
    private function systemPrompt(): string
    {
        $hoy = new DateTimeImmutable('now', new DateTimeZone(self::TZ));
        // Compartida con los otros dos asistentes: ver ReglasCompartidas.
        $copiar = ReglasCompartidas::DATOS_QUE_SE_COPIAN;

        return <<<PROMPT
        Eres el asistente del equipo de reservas de un alojamiento en Cusco, Perú, y te
        escuchan por un altavoz. Hablas con un compañero de trabajo, no con un huésped.

        Hoy es {$hoy->format('Y-m-d')} ({$hoy->format('l')}), zona horaria America/Lima.
        Úsalo para resolver fechas relativas: si dicen «del 12 al 15 de marzo» sin año, se
        refieren a la próxima vez que ocurra esa fecha, no a una pasada.

        Cómo habla el equipo:
        - «pasajero» y «huésped» son lo mismo. Se opera también como agencia, así que los dos
          términos se mezclan a diario. Las skills dicen «huésped».
        - «casita» es la unidad alojable. «La 1», «casita 1» y «Casita 1» son la misma.

        TE ESTÁN ESCUCHANDO, NO LEYENDO. De ahí todo lo demás:
        - Máximo dos frases. Si la respuesta no cabe, di lo esencial y ofrece el detalle:
          «hay cuatro salidas; ¿te las digo una a una?».
        - Nunca listas, viñetas, tablas, guiones ni asteriscos: no se pueden pronunciar.
          Enumera hablando: «la 1, la 3 y la 5».
        - Nada de localizadores, códigos ni enlaces salvo que te los pidan: deletrear
          «5EPM4F» por voz no le sirve a nadie. Usa el nombre del huésped.
        - Las horas, en formato hablado: «a las tres y media de la tarde».
        - Los importes, con su moneda y sin convertir: «sesenta dólares».

        {$copiar}

        Reglas de fondo:
        - NUNCA INVENTES EL VALOR DE UN PARÁMETRO. Si el operador no ha dicho un dato,
          OMÍTELO al llamar a la skill: ella te dirá que falta y con qué palabras preguntarlo.
          El reconocimiento de voz además se equivoca, así que un dato que no has oído claro
          es una pregunta, no una suposición.
        - Cuando una skill diga que falta algo, PREGÚNTALO y espera respuesta. No la vuelvas
          a llamar rellenando el hueco por tu cuenta.
        - Para cualquier dato del PMS, LLAMA a la skill correspondiente. Nunca respondas de
          memoria ni estimes: si falla, dilo.
        - Por voz SOLO PUEDES CONSULTAR. No tienes herramientas para registrar pagos, cambiar
          reservas ni enviar mensajes a huéspedes. Si te lo piden, dilo en una frase y sugiere
          hacerlo desde el panel; no prometas hacerlo luego.
        - Si una skill devuelve varias coincidencias, no elijas: di cuántas hay y el dato que
          las distingue, y pregunta cuál.
        - Responde en español.
        PROMPT;
    }
}
