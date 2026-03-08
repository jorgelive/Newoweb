<?php

declare(strict_types=1);

namespace App\Message\Repository;

use App\Exchange\Repository\AbstractExchangeRepository;
use App\Message\Entity\Beds24SendQueue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositorio para la cola de envío de mensajes a Beds24.
 */
final class Beds24SendQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Beds24SendQueue::class);
    }

    protected function getTableName(): string
    {
        // Hardcodearlo aquí es fraccionalmente más rápido que leer el MetaData de Doctrine
        return 'msg_beds24_send_queue';
    }

    /**
     * Hidratación optimizada para el Worker.
     * @param string[] $ids IDs en formato BINARIO (16 bytes)
     */
    protected function hydrateItems(array $ids): array
    {
        // 🔥 GUARDIA CRÍTICA: Doctrine falla con "Syntax Error" si $ids está vacío
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('q')
            ->addSelect('msg', 'cfg', 'ep')
            ->innerJoin('q.message', 'msg')
            ->innerJoin('q.config', 'cfg')
            ->innerJoin('q.endpoint', 'ep')
            ->andWhere('q.id IN (:ids)')
            // Le decimos explícitamente a DQL que trate el array como binarios crudos
            ->setParameter('ids', $ids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }
}