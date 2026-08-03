<?php

declare(strict_types=1);

namespace App\Pms\Repository;

use App\Exchange\Repository\AbstractExchangeRepository;
use App\Pms\Entity\Beds24InvoiceReceiveQueue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractExchangeRepository<Beds24InvoiceReceiveQueue>
 */
final class Beds24InvoiceReceiveQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Beds24InvoiceReceiveQueue::class);
    }

    protected function getTableName(): string
    {
        return 'pms_beds24_invoice_receive_queue';
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
