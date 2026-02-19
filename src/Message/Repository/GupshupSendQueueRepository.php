<?php

declare(strict_types=1);

namespace App\Message\Repository;

use App\Exchange\Repository\AbstractExchangeRepository;
use App\Message\Entity\GupshupSendQueue;
use Doctrine\DBAL\ArrayParameterType; // <--- AGREGAR ESTO
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositorio para la cola de envío de WhatsApp (Gupshup).
 */
final class GupshupSendQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GupshupSendQueue::class);
    }

    protected function getTableName(): string
    {
        return 'msg_gupshup_queue';
    }

    /**
     * @param string[] $ids IDs en formato BINARIO (16 bytes)
     */
    protected function hydrateItems(array $ids): array
    {
        return $this->createQueryBuilder('q')
            ->addSelect('msg', 'cfg', 'ep')
            ->innerJoin('q.message', 'msg')
            ->innerJoin('q.config', 'cfg')
            ->innerJoin('q.endpoint', 'ep')
            ->andWhere('q.id IN (:ids)')
            // CAMBIO CRÍTICO: Definir tipo explícito para los bytes
            ->setParameter('ids', $ids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }
}