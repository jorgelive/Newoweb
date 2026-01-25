<?php
declare(strict_types=1);

namespace App\Exchange\Service\Common;

use App\Exchange\Service\Contract\ExchangeTaskInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class ExchangeTaskLocator
{
    /**
     * @param ContainerInterface $tasks Inyectado automáticamente gracias al !tagged_locator
     */
    public function __construct(
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