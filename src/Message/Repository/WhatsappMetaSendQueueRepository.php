<?php

declare(strict_types=1);

namespace App\Message\Repository;

use App\Exchange\Repository\AbstractExchangeRepository;
use App\Message\Entity\WhatsappMetaSendQueue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositorio para la cola de envío de WhatsApp (Meta).
 */
final class WhatsappMetaSendQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhatsappMetaSendQueue::class);
    }

    protected function getTableName(): string
    {
        // Nombre de la tabla física según tu mapeo
        return 'msg_whatsapp_meta_send_queue';
    }

    /**
     * @param string[] $ids IDs en formato BINARIO (16 bytes)
     */
    protected function hydrateItems(array $ids): array
    {
        // 🔥 GUARDIA: Previene error "IN ()" si el array viene vacío
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('q')
            ->addSelect('msg', 'cfg', 'ep') // Eager loading para evitar N+1
            ->innerJoin('q.message', 'msg')
            ->innerJoin('q.config', 'cfg')
            ->innerJoin('q.endpoint', 'ep')
            ->andWhere('q.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }
}