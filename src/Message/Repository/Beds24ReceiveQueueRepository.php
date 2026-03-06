<?php

declare(strict_types=1);

namespace App\Message\Repository;

use App\Exchange\Repository\AbstractExchangeRepository;
use App\Message\Entity\Beds24ReceiveQueue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

final class Beds24ReceiveQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Beds24ReceiveQueue::class);
    }

    protected function getTableName(): string
    {
        return 'msg_beds24_receive_queue';
    }

    /**
     * @param string[] $ids IDs en formato BINARIO (16 bytes)
     */
    protected function hydrateItems(array $ids): array
    {
        return $this->createQueryBuilder('j')
            ->addSelect('cfg', 'ep')
            ->innerJoin('j.config', 'cfg')
            ->innerJoin('j.endpoint', 'ep')
            ->andWhere('j.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }
}