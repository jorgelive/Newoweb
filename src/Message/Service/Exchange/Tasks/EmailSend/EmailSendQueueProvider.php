<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\EmailSend;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Message\Entity\EmailSendQueue;
use App\Message\Repository\EmailSendQueueRepository;
use DateTimeImmutable;
use RuntimeException;

/**
 * Saca de la cola los correos que toca mandar.
 *
 * ⚠️ **El endpoint es un marcador de posición.** `HomogeneousBatch` exige uno porque los demás
 * canales hablan con una API y necesitan una ruta; el destino de un correo es un buzón. Se usa
 * el endpoint `email_send`, que existe sólo para satisfacer ese contrato, y el cliente lo
 * ignora. Se prefiere eso a hacer opcional el endpoint en el motor: cambiar el núcleo para un
 * caso obliga a revisar los cinco que ya funcionan.
 */
final readonly class EmailSendQueueProvider implements ExchangeQueueProviderInterface
{
    public function __construct(private EmailSendQueueRepository $repository)
    {
    }

    public function claimBatch(int $limit, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        return $this->empaquetar($this->repository->claimRunnable($limit, $workerId, $now, 60));
    }

    public function claimSpecificBatch(array $ids, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        return $this->empaquetar($this->repository->claimSpecificItems($ids, $workerId, $now));
    }

    /** @param list<EmailSendQueue> $items */
    private function empaquetar(array $items): ?HomogeneousBatch
    {
        if ($items === []) {
            return null;
        }

        $primero = $items[0];
        $config = $primero->getConfig();
        $endpoint = $primero->getEndpoint();

        if ($config === null) {
            throw new RuntimeException(sprintf('Cola de correo #%s sin configuración.', $primero->getId()));
        }

        return new HomogeneousBatch($config, $endpoint, $items);
    }

    /**
     * @param list<string> $ids
     * @return array<string, mixed>
     */
    public function getGroupingMetadata(array $ids): array
    {
        return [];
    }
}
