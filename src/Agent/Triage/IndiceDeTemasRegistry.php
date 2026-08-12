<?php

declare(strict_types=1);

namespace App\Agent\Triage;

use App\Agent\Access\ActorInterface;
use App\Message\Contract\IndiceDeTemasInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Qué índice de temas le toca a esta conversación.
 *
 * Mismo patrón que {@see \App\Agent\Conversation\InstruccionesDominioRegistry} y que
 * {@see \App\Message\Service\MessageDataResolverRegistry}: gana el primero que dice soportar
 * el `context_type`.
 *
 * ⚠️ Aquí NO hay «por defecto», al contrario que en las instrucciones de dominio. Un prospecto
 * sin contexto tiene que poder recibir el prompt de venta de algún negocio —de ahí aquel
 * default—, pero no tiene temas que consultar: sin índice, el triaje simplemente no ofrece
 * elegir tema, que es el comportamiento correcto y no una degradación.
 */
final readonly class IndiceDeTemasRegistry
{
    /**
     * @param iterable<IndiceDeTemasInterface> $indices
     */
    public function __construct(
        #[TaggedIterator('app.agent.indice_temas')]
        private iterable $indices
    ) {}

    /**
     * El índice de este contexto, o `null` si el dominio no tiene o la skill que lo habilita
     * no está entre las que puede usar quien pregunta.
     *
     * @param list<string> $skillsDisponibles nombres de las skills del actor
     */
    public function para(?string $contextType, array $skillsDisponibles): ?IndiceDeTemasInterface
    {
        foreach ($this->indices as $indice) {
            if (!$indice->supports($contextType)) {
                continue;
            }

            return in_array($indice->skillQueLoHabilita(), $skillsDisponibles, true)
                ? $indice
                : null;
        }

        return null;
    }
}
