<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

use App\Message\Contract\InstruccionesDeDominioInterface;
use App\Message\Entity\MessageConversation;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Qué dominio le habla a esta conversación.
 *
 * Mismo patrón que {@see \App\Message\Service\MessageDataResolverRegistry}: se recorre la
 * lista, gana el primero que dice soportar el `context_type`, y si ninguno lo hace se cae al
 * marcado por defecto. Ese último caso no es un remiendo — es el prospecto, que por
 * definición no trae contexto y aun así necesita que alguien le venda algo.
 */
final readonly class InstruccionesDominioRegistry
{
    /**
     * @param iterable<InstruccionesDeDominioInterface> $dominios
     */
    public function __construct(
        #[TaggedIterator('app.agent.instrucciones_dominio')]
        private iterable $dominios
    ) {}

    public function para(?string $contextType, PerfilConversacion $perfil): string
    {
        return $this->resolver($contextType)?->para($perfil) ?? '';
    }

    /**
     * El contexto volátil del dominio para esta conversación.
     *
     * Se resuelve por el `context_type` de la CONVERSACIÓN y no por el del actor: son el mismo
     * valor en el chat, pero el actor de un prospecto nace sin contexto a propósito y aquí lo
     * que se pregunta es de qué negocio va el hilo, no a qué está atado quien escribe.
     */
    public function contextoVolatil(MessageConversation $conversacion): string
    {
        return $this->resolver($conversacion->getContextType())?->contextoVolatil($conversacion) ?? '';
    }

    /**
     * Gana el primero que dice soportar el tipo; si ninguno, el marcado por defecto.
     *
     * Ese último caso no es un remiendo: es el prospecto, que por definición no trae contexto
     * y aun así necesita que alguien le venda algo.
     */
    private function resolver(?string $contextType): ?InstruccionesDeDominioInterface
    {
        $porDefecto = null;

        foreach ($this->dominios as $dominio) {
            if ($dominio->supports($contextType)) {
                return $dominio;
            }

            if ($porDefecto === null && $dominio->esPorDefecto()) {
                $porDefecto = $dominio;
            }
        }

        return $porDefecto;
    }
}
