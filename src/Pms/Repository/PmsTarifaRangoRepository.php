<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
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
     * Devuelve rangos que SOLAPAN el intervalo para UNA unidad.
     *
     * Solape estándar:
     *   rango.start < to  AND  rango.end > from
     *
     * @return list<PmsTarifaRango>
     */
    public function findOverlappingForUnitInterval(PmsUnidad $unidad, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.unidad', 'u')->addSelect('u')
            ->leftJoin('r.moneda', 'm')->addSelect('m')
            ->andWhere('r.activo = :activo')->setParameter('activo', true)
            ->andWhere('r.unidad = :unidad')->setParameter('unidad', $unidad)
            ->andWhere('r.fechaInicio < :to')->setParameter('to', $to)
            ->andWhere('r.fechaFin > :from')->setParameter('from', $from)
            ->orderBy('r.fechaInicio', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Devuelve rangos que SOLAPAN el intervalo para VARIAS unidades.
     * Útil si recalculas en batch.
     *
     * @param list<PmsUnidad> $unidades
     * @return list<PmsTarifaRango>
     */
    public function findOverlappingForUnitsInterval(array $unidades, DateTimeInterface $from, DateTimeInterface $to): array
    {
        if ($unidades === []) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->leftJoin('r.unidad', 'u')->addSelect('u')
            ->leftJoin('r.moneda', 'm')->addSelect('m')
            ->andWhere('r.activo = :activo')->setParameter('activo', true)
            ->andWhere('r.unidad IN (:unidades)')->setParameter('unidades', $unidades)
            ->andWhere('r.fechaInicio < :to')->setParameter('to', $to)
            ->andWhere('r.fechaFin > :from')->setParameter('from', $from)
            ->orderBy('u.id', 'ASC')
            ->addOrderBy('r.fechaInicio', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Devuelve rangos que SOLAPAN el intervalo (GLOBAL, sin filtrar por unidad).
     *
     * Úsalo solo para reportes/admin; para motor de precios por unidad, usa
     * findOverlappingForUnitInterval().
     *
     * @return list<PmsTarifaRango>
     */
    public function findOverlappingForInterval(DateTimeInterface $from, DateTimeInterface $to): array
    {
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