<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

use App\Message\Contract\InstruccionesDeDominioInterface;
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
        $porDefecto = null;

        foreach ($this->dominios as $dominio) {
            if ($dominio->supports($contextType)) {
                return $dominio->para($perfil);
            }

            if ($porDefecto === null && $dominio->esPorDefecto()) {
                $porDefecto = $dominio;
            }
        }

        return $porDefecto?->para($perfil) ?? '';
    }
}
