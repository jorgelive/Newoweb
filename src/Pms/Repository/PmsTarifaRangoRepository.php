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
     * Devuelve rangos que solapan el intervalo.
     * ✅ FIX: Filtramos por ID de unidad (String) para evitar errores de comparación de objetos en Doctrine.
     */
    public function findOverlappingForUnidadAndInterval(PmsUnidad $unidad, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r', 'm') // Traemos el Rango (r) y la Moneda (m) en una sola query
            ->innerJoin('r.unidad', 'u') // Join obligatorio con unidad
            ->leftJoin('r.moneda', 'm')  // Join opcional con moneda

            ->andWhere('r.activo = :activo')
            ->setParameter('activo', true)

            // 🔥 AQUÍ ESTABA EL ERROR: Usamos el ID explícito
            ->andWhere('u.id = :unidadId')
            ->setParameter('unidadId', $unidad->getId(), 'uuid')

            // Lógica de Solape: (Start < EndQuery) AND (End > StartQuery)
            ->andWhere('r.fechaInicio < :to')
            ->andWhere('r.fechaFin > :from')
            ->setParameter('to', $to)
            ->setParameter('from', $from)

            ->orderBy('r.prioridad', 'DESC')
            ->addOrderBy('r.fechaInicio', 'ASC');

        // DEBUG SQL
 //dump($qb->getQuery()->getSQL());
 //dump($qb->getQuery()->getParameters());
 //die();
           return $qb->getQuery()->getResult();
    }
}