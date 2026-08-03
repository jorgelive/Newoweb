<?php

declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsInformacionFinanciera;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * @extends ServiceEntityRepository<PmsInformacionFinanciera>
 */
class PmsInformacionFinancieraRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsInformacionFinanciera::class);
    }

    /**
     * Cabecera financiera de una reserva, buscada por el UUID de ésta.
     *
     * 🔴 EL TIPO `UuidType::NAME` EN setParameter() NO ES OPCIONAL. `reserva_id` es
     * `BINARY(16)`: sin declarar el tipo, Doctrine manda el UUID como texto, MySQL compara
     * texto contra binario y **no casa nunca** — devuelve 0 filas sin error ninguno.
     * Comprobado: string → 0, objeto Uuid sin tipo → 0, objeto Uuid CON tipo → 1.
     *
     * Es también la razón por la que este método existe en vez de un `SearchFilter` sobre
     * la relación: ese filtro no declara el tipo, así que sufría exactamente el mismo fallo
     * silencioso (ver §12.6 del doc).
     */
    public function findOneByReservaId(string $reservaId): ?PmsInformacionFinanciera
    {
        try {
            $uuid = Uuid::fromString($reservaId);
        } catch (Throwable) {
            return null; // Id mal formado: no es un 500, simplemente no hay resultado.
        }

        return $this->createQueryBuilder('i')
            ->andWhere('i.reserva = :reserva')
            ->setParameter('reserva', $uuid, UuidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
