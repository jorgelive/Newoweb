<?php

declare(strict_types=1);

namespace App\Agent\Access;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;

/**
 * Los asuntos entre los que puede elegir el agente en un hilo, con su clave (R1 = el de por defecto).
 *
 * Una sola pieza para el chat (`AiConversationProcessor`) y la consola (`app:agent:skill
 * --conversacion`): si la consola armara la lista por su cuenta, la simulación probaría otra regla
 * que la de producción. Vacío con un solo asunto —casi siempre—: entonces no hay nada que elegir.
 *
 * Ver `docs/Mensajeria.md` §11, «Varias reservas en un hilo».
 */
final readonly class AsuntosDelHiloParaElAgente
{
    public function __construct(private EnlacesDeConversacion $enlaces)
    {
    }

    /** @return list<AsuntoDelHilo> */
    public function de(MessageConversation $hilo): array
    {
        $vivos = $this->enlaces->asuntosVivos($hilo);

        if (count($vivos) < 2) {
            return [];
        }

        $asuntos = [];

        foreach ($vivos as $i => $enlace) {
            $asuntos[] = new AsuntoDelHilo(
                'R' . ($i + 1),
                $enlace->getContextType(),
                $enlace->getContextId(),
                $enlace->getEtiqueta(),
                $i === 0,
            );
        }

        return $asuntos;
    }
}
