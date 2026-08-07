<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

/**
 * Un motor con el modelo concreto que le toca a este paso.
 *
 * Existe porque «qué proveedor» y «qué modelo» son dos decisiones y viajaban sueltas: el
 * registro devolvía el motor y el modelo se pasaba aparte en cada `ConversationRequest`, con
 * el riesgo de mandarle a Anthropic un modelo de Google. Aquí van juntos y ya validados
 * ({@see SelectorDePotencia::elegir()}).
 */
final readonly class MotorElegido
{
    /**
     * @param string|null $modelo `null` significa «el de por defecto del motor», que es lo que
     *        {@see ConversationRequest::$modelo} ya entiende. No se rellena con el defecto para
     *        que el log distinga «se pidió este modelo» de «se usó el que hubiera».
     */
    public function __construct(
        public AgentEngineInterface $motor,
        public ?string $modelo,
        public PotenciaRequerida $potencia,
    ) {}

    /** Para el log: qué acabó atendiendo de verdad. */
    public function etiqueta(): string
    {
        return sprintf(
            '%s/%s (%s)',
            $this->motor->nombre(),
            $this->modelo ?? $this->motor->modeloPorDefecto(),
            $this->potencia->value
        );
    }
}
