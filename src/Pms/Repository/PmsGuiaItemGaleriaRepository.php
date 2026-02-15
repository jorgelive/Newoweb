<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsGuiaItemGaleria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

final class PmsGuiaItemGaleriaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsGuiaItemGaleria::class);
    }

    public function moveUp(PmsGuiaItemGaleria $entity, int $steps = 1): void
    {
        for ($i = 0; $i < max(1, $steps); $i++) {
            if ($entity->getOrden() <= 0) {
                break;
            }
            if (!$this->swapWithNeighbor($entity, 'up')) {
                break;
            }
        }
    }

    public function moveDown(PmsGuiaItemGaleria $entity, int $steps = 1): void
    {
        for ($i = 0; $i < max(1, $steps); $i++) {
            if (!$this->swapWithNeighbor($entity, 'down')) {
                break;
            }
        }
    }

    private function swapWithNeighbor(PmsGuiaItemGaleria $entity, string $dir): bool
    {
        $item = $entity->getItem();
        if (null === $item) {
            return false;
        }

        $current = $entity->getOrden();

        $itemId = $item->getId();
        $itemUuid = $itemId instanceof Uuid ? $itemId : Uuid::fromString((string) $itemId);

        $qb = $this->createQueryBuilder('g')
            ->andWhere('IDENTITY(g.item) = :itemId')
            ->setParameter('itemId', $itemUuid, 'uuid');

        if ($dir === 'up') {
            $qb->andWhere('g.orden < :pos')
                ->setParameter('pos', $current)
                ->orderBy('g.orden', 'DESC');
        } else {
            $qb->andWhere('g.orden > :pos')
                ->setParameter('pos', $current)
                ->orderBy('g.orden', 'ASC');
        }

        $neighbor = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if (!$neighbor instanceof PmsGuiaItemGaleria) {
            return false;
        }

        $tmp = $neighbor->getOrden();
        $neighbor->setOrden($current);
        $entity->setOrden($tmp);

        $em = $this->getEntityManager();
        $em->persist($neighbor);
        $em->persist($entity);

        return true;
    }

    /**
     * Útil para “arreglar” un item si quedó con duplicados/huecos.
     * Reasigna orden 0..N dentro del item.
     */
    public function normalizeByItemId(Uuid $itemId): void
    {
        $rows = $this->createQueryBuilder('g')
            ->andWhere('IDENTITY(g.item) = :itemId')
            ->setParameter('itemId', $itemId, 'uuid')
            ->orderBy('g.orden', 'ASC')
            ->addOrderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();

        $em = $this->getEntityManager();
        $i = 0;

        foreach ($rows as $r) {
            if ($r instanceof PmsGuiaItemGaleria) {
                $r->setOrden($i++);
                $em->persist($r);
            }
        }
    }
}