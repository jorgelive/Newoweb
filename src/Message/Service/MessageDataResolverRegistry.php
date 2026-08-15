<?php

declare(strict_types=1);

namespace App\Message\Service;

use App\Message\Contract\MessageDataResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class MessageDataResolverRegistry
{
    /** @var iterable<MessageDataResolverInterface> */
    /** @var iterable<MessageDataResolverInterface> */
    private iterable $resolvers;

    public function __construct(
        /** @param iterable<MessageDataResolverInterface> $resolvers */
        #[TaggedIterator('app.message_data_resolver')] iterable $resolvers
    ) {
        $this->resolvers = $resolvers;
    }

    public function getResolver(string $contextType): ?MessageDataResolverInterface
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($contextType)) {
                return $resolver;
            }
        }
        return null;
    }
}