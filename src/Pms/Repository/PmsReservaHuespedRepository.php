<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsReservaHuesped;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Repositorio para la gestión de la entidad PmsReservaHuesped.
 *
 * @extends ServiceEntityRepository<PmsReservaHuesped>
 *
 * @method PmsReservaHuesped|null find($id, $lockMode = null, $lockVersion = null)
 * @method PmsReservaHuesped|null findOneBy(array $criteria, array $orderBy = null)
 * @method PmsReservaHuesped[]    findAll()
 * @method PmsReservaHuesped[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
/** @extends ServiceEntityRepository<PmsReservaHuesped> */
class PmsReservaHuespedRepository extends ServiceEntityRepository
{
    /**
     * Inicializa el repositorio con el registro de Doctrine.
     *
     * @param ManagerRegistry $registry El registro de gestores de entidades.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsReservaHuesped::class);
    }

    /**
     * Persiste y guarda un registro de Huésped en la base de datos.
     *
     * Este método encapsula la llamada al EntityManager para mantener el código
     * cliente desacoplado de la infraestructura de persistencia.
     *
     * @param PmsReservaHuesped $entity La entidad a guardar.
     * @param bool $flush Si es true, ejecuta la transacción inmediatamente.
     */
    public function save(PmsReservaHuesped $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Elimina un registro de Huésped de la base de datos.
     *
     * @param PmsReservaHuesped $entity La entidad a eliminar.
     * @param bool $flush Si es true, ejecuta la transacción inmediatamente.
     */
    public function remove(PmsReservaHuesped $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    // =========================================================================
    // MÉTODOS DE BÚSQUEDA PERSONALIZADOS (Ejemplos útiles)
    // =========================================================================

    /**
     * Busca todos los huéspedes asociados a una reserva específica.
     *
     * ⚠️ El id va con el tipo `uuid` explícito. La firma decía `int` —herencia de otra época—
     * y el parámetro se ligaba sin tipo: contra una columna `BINARY(16)` eso no falla, devuelve
     * CERO filas. Misma trampa que en `Beds24SendEnqueuer::isAlreadyEnqueued()`.
     *
     * @param string|Uuid $reservaId El ID de la reserva padre.
     * @return PmsReservaHuesped[] Lista de huéspedes ordenados por si son principales primero.
     */
    public function findByReservaId(string|Uuid $reservaId): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.reserva = :reservaId')
            ->setParameter('reservaId', $reservaId, UuidType::NAME)
            ->orderBy('h.esPrincipal', 'DESC') // Titulares primero
            ->addOrderBy('h.apellido', 'ASC')  // Luego alfabéticamente
            ->getQuery()
            ->getResult();
    }
}