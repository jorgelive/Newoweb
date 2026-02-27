<?php
declare(strict_types=1);

namespace App\Exchange\Repository;

use App\Exchange\Entity\ExchangeEndpoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExchangeEndpoint>
 *
 * Repositorio para la gestión de puntos de conexión de Beds24 dentro del módulo PMS.
 */
final class ExchangeEndpointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeEndpoint::class);
    }

}