<?php

declare(strict_types=1);

namespace App\Agent\Action;

use App\Agent\Service\AiConversationProcessor;
use App\Message\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La acción «que conteste el agente» de una regla de botón.
 *
 * Un botón pulsado es una intención DETERMINISTA, y el `IntentRouter` sólo la atiende si hay una
 * regla para su payload: sin regla, el mensaje se cierra como `sin_regla` y no le contesta nadie.
 * Hay botones que no tienen una respuesta fija —«Saldré antes», «Necesito más tiempo»— y lo que
 * piden es una conversación: preguntar la hora, explicar, apuntar. Esta acción se la pasa al
 * agente con el texto del botón como si lo hubiera escrito el huésped.
 *
 * Desde el 01/10/2026 (los botones del check-out). Ver docs/Mensajeria.md §9.
 */
final readonly class PasarAlAgenteActionHandler implements BotActionHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private AiConversationProcessor $agente,
    ) {}

    public function getActionKey(): string
    {
        return 'pasar_al_agente';
    }

    public function getActionLabel(): string
    {
        return 'Que conteste el agente (con el texto del botón)';
    }

    public function execute(string $mensajeEntranteId, ParametrosDeAccion $parametros): void
    {
        $mensaje = $this->em->getRepository(Message::class)->find($mensajeEntranteId);

        if ($mensaje instanceof Message) {
            $this->agente->process($mensaje);
        }
    }
}
