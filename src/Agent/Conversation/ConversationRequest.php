<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

use App\Agent\Access\ActorInterface;

/**
 * Todo lo que un motor necesita para responder un turno, en términos neutrales.
 */
final readonly class ConversationRequest
{
    /**
     * @param list<array{rol: string, texto: string}> $historial Turnos previos, en orden. Es
     *        lo que sostiene el bucle de decisiones: sin ellos, un «el de la casita 3» tras
     *        un «¿cuál de los dos Carlos?» llegaría sin contexto.
     * @param bool $permitirEscritura Falso en los canales donde no hay a quién pedir
     *        confirmación, como el chat del huésped.
     * @param string|null $modelo Modelo concreto dentro del proveedor. `null` = el de por
     *        defecto del motor. Viaja en la petición —y no en la configuración del servicio—
     *        porque el panel deja comparar dos modelos en caliente sobre la misma pregunta.
     */
    public function __construct(
        public ActorInterface $actor,
        public string $systemPrompt,
        public string $mensaje,
        public array $historial = [],
        public bool $permitirEscritura = true,
        public int $maxTokens = 4096,
        public ?string $modelo = null,
    ) {}
}
