<?php
declare(strict_types=1);

namespace App\Exchange\Service\Common;

use App\Exchange\Service\Contract\ExchangeTaskInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use RuntimeException;

final class ExchangeTaskLocator
{
    /**
     * @param ContainerInterface $tasks Inyectado automáticamente por el Atributo
     */
    public function __construct(
        #[TaggedLocator('app_exchange_task', defaultIndexMethod: 'getTaskName')]
        private readonly ContainerInterface $tasks
    ) {}

    public function get(string $taskName): ExchangeTaskInterface
    {
        if (!$this->tasks->has($taskName)) {
            throw new RuntimeException(sprintf('La tarea "%s" no está registrada en el localizador.', $taskName));
        }

        /** @var ExchangeTaskInterface */
        return $this->tasks->get($taskName);
    }
}