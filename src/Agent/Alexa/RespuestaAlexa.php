<?php

declare(strict_types=1);

namespace App\Agent\Alexa;

/**
 * El sobre que espera Alexa de vuelta.
 *
 * Siempre se responde **200 con un cuerpo hablable**, incluso cuando algo va mal por nuestra
 * parte: un 500 hace que el dispositivo diga «hubo un problema con la skill solicitada» y el
 * operador se queda sin saber si falló la red, el permiso o el dato. Un texto nuestro se
 * entiende. La excepción es la firma inválida, que sí devuelve 400 sin cuerpo: ahí no hay
 * ningún usuario a quien explicarle nada, y contestar 200 es invitar a que insistan.
 *
 * Ver docs/AgentVoz.md §4.
 */
final readonly class RespuestaAlexa
{
    /**
     * @param array<string, mixed> $atributos
     */
    private function __construct(
        private string $texto,
        private bool $cerrarSesion,
        private array $atributos = [],
        private ?string $reintento = null,
    ) {}

    /**
     * Habla y **deja el micrófono abierto**: sin esto, cada pregunta obligaría a repetir
     * «Alexa, pregúntale a recepción…» desde el principio, que es justo lo que hace inútil un
     * asistente conversacional. El `reintento` es obligatorio cuando la sesión sigue abierta:
     * Amazon lo pide para certificar.
     *
     * @param list<array{rol: string, texto: string}> $historial
     */
    public static function sigueEscuchando(string $texto, array $historial, string $reintento): self
    {
        return new self($texto, false, ['historial' => $historial], $reintento);
    }

    public static function despedida(string $texto): self
    {
        return new self($texto, true);
    }

    /** Para el `SessionEndedRequest`: Alexa no admite que se hable en ese turno. */
    public static function silencio(): self
    {
        return new self('', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(): array
    {
        $respuesta = [
            'shouldEndSession' => $this->cerrarSesion,
        ];

        if ($this->texto !== '') {
            $respuesta['outputSpeech'] = ['type' => 'PlainText', 'text' => $this->texto];
        }

        if ($this->reintento !== null) {
            $respuesta['reprompt'] = [
                'outputSpeech' => ['type' => 'PlainText', 'text' => $this->reintento],
            ];
        }

        return [
            'propuesta' => '1.0',
            // Un array vacío de PHP se serializa `[]`, y Alexa espera un OBJETO aquí. El cast
            // lo fuerza a `{}`. Es el clásico que sólo se ve cuando la sesión no lleva
            // atributos —justo los turnos de bienvenida y de cierre—, así que pasa las pruebas
            // manuales y falla en producción.
            'sessionAttributes' => $this->atributos === [] ? new \stdClass() : $this->atributos,
            'response' => $respuesta,
        ];
    }
}
