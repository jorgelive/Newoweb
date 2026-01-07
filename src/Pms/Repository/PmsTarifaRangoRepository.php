<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsTarifaRango;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PmsTarifaRango>
 */
final class PmsTarifaRangoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsTarifaRango::class);
    }

    /**
     * Devuelve rangos que SOLAPAN el intervalo.
     *
     * Solape estándar:
     *   rango.start < to  AND  rango.end > from
     *
     * Notas:
     * - Si tu fechaFin es "end exclusivo" o "end inclusivo", lo defines aquí.
     * - Yo lo trato como end EXCLUSIVO a nivel lógico, pero a nivel DB suele ser date.
     *
     * @return list<PmsTarifaRango>
     */
    public function findOverlappingForInterval(DateTimeInterface $from, DateTimeInterface $to): array
    {
        // join unidad para que resource esté listo (evita N+1)
        return $this->createQueryBuilder('r')
            ->leftJoin('r.unidad', 'u')->addSelect('u')
            ->leftJoin('r.moneda', 'm')->addSelect('m')
            ->andWhere('r.activo = :activo')->setParameter('activo', true)
            ->andWhere('r.fechaInicio < :to')->setParameter('to', $to)
            ->andWhere('r.fechaFin > :from')->setParameter('from', $from)
            ->orderBy('u.id', 'ASC')
            ->addOrderBy('r.fechaInicio', 'ASC')
            ->getQuery()
            ->getResult();
    }
}