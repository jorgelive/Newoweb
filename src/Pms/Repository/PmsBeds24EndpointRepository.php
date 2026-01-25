<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsBeds24Endpoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PmsBeds24Endpoint>
 *
 * Repositorio para la gestión de puntos de conexión de Beds24 dentro del módulo PMS.
 */
final class PmsBeds24EndpointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsBeds24Endpoint::class);
    }

    /**
     * Busca un endpoint específico por su acción lógica (ej: 'GET_BOOKINGS').
     * * Este método es fundamental para el PmsBookingsPullQueueFactory, ya que
     * asegura que la entidad creada esté vinculada a un endpoint válido y activo.
     *
     * @param string $accion La clave de acción lógica (CALENDAR_POST, GET_BOOKINGS, etc.)
     * @return PmsBeds24Endpoint|null El endpoint encontrado o null si no existe o está inactivo.
     */
    public function findActiveByAccion(string $accion): ?PmsBeds24Endpoint
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.accion = :accion')
            ->andWhere('e.activo = :activo')
            ->setParameter('accion', $accion)
            ->setParameter('activo', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Lista todos los endpoints habilitados para un método HTTP concreto.
     * * @param string $metodo GET, POST, DELETE, etc.
     * @return PmsBeds24Endpoint[]
     */
    public function findByMetodo(string $metodo): array
    {
        return $this->findBy([
            'metodo' => strtoupper($metodo),
            'activo' => true
        ]);
    }
}