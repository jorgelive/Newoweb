<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsReserva;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositorio para la gestión de la entidad PmsReserva.
 */
class PmsReservaRepository extends ServiceEntityRepository
{
    /**
     * Inicializa el repositorio con el registro de Doctrine.
     *
     * @param ManagerRegistry $registry El registro de gestores de entidades.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsReserva::class);
    }

    /**
     * Busca una reserva coincidiendo con el Master ID o con cualquiera
     * de los Book IDs de sus eventos (habitaciones) vinculados.
     */
    public function findByAnyBeds24Id(string $beds24Id): ?PmsReserva
    {
        $qb = $this->createQueryBuilder('r');

        return $qb
            ->leftJoin('r.eventosCalendario', 'e')
            ->leftJoin('e.beds24Links', 'l')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('r.beds24MasterId', ':bookId'),
                    $qb->expr()->eq('l.beds24BookId', ':bookId')
                )
            )
            ->setParameter('bookId', $beds24Id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

}