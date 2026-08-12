<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Exchange\Entity\Beds24Config;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<PmsUnidadBeds24Map>
 */
final class PmsUnidadBeds24MapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsUnidadBeds24Map::class);
    }

    public function findPreferidoPorUnidad(PmsUnidad $unidad): ?PmsUnidadBeds24Map
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.pmsUnidad = :u')
            // ⚠️ El id con tipo `uuid`, no la entidad: ver la nota de `findRoomIdsForPull()`.
            ->setParameter('u', $unidad->getId(), UuidType::NAME)
            ->addOrderBy('m.activo', 'DESC')
            ->addOrderBy('m.esPrincipal', 'DESC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return PmsUnidadBeds24Map[]
     */
    public function findAllOrdenadosPorUnidad(PmsUnidad $unidad): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.pmsUnidad = :u')
            // ⚠️ El id con tipo `uuid`, no la entidad: ver la nota de `findRoomIdsForPull()`.
            ->setParameter('u', $unidad->getId(), UuidType::NAME)
            ->addOrderBy('m.activo', 'DESC')
            ->addOrderBy('m.esPrincipal', 'DESC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * RoomIds Beds24 para filtrar el Pull.
     *
     * Si $unidades es null o vacío => usa TODAS las unidades activas del config.
     *
     * ⚠️ TODOS los parámetros de UUID van con el tipo `uuid` explícito, y las listas como
     * binario crudo con `ArrayParameterType::BINARY`. Ligar la entidad —o la lista de
     * entidades— NO falla: DQL la serializa como cadena RFC contra una columna `BINARY(16)`
     * y la consulta devuelve CERO filas. Aquí eso significaba pull sin roomIds, y el filtro
     * por unidades sencillamente no filtraba nada. Misma trampa que costó los envíos
     * duplicados de `Beds24SendEnqueuer::isAlreadyEnqueued()`.
     *
     * @param PmsUnidad[]|null $unidades
     * @return int[] roomIds únicos ordenados
     */
    public function findRoomIdsForPull(Beds24Config $config, ?array $unidades = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('DISTINCT m.beds24RoomId AS roomId')
            ->andWhere('m.config = :config')
            ->andWhere('m.activo = :activo')
            ->andWhere('m.beds24RoomId IS NOT NULL')
            ->setParameter('config', $config->getId(), UuidType::NAME)
            ->setParameter('activo', true)
            ->addOrderBy('m.beds24RoomId', 'ASC');

        if (is_array($unidades) && count($unidades) > 0) {
            $qb->andWhere('m.pmsUnidad IN (:unidades)')
                ->setParameter(
                    'unidades',
                    array_map(static fn (PmsUnidad $u) => $u->getId()->toBinary(), $unidades),
                    ArrayParameterType::BINARY
                );
        }

        $rows = $qb->getQuery()->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            if (isset($row['roomId'])) {
                $ids[] = (int) $row['roomId'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * PropertyIds Beds24 (si aún los quieres usar en algunos pulls).
     *
     * @param PmsUnidad[]|null $unidades
     * @return int[] propertyIds únicos ordenados
     */
    public function findPropertyIdsForPull(Beds24Config $config, ?array $unidades = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('DISTINCT m.beds24PropertyId AS propertyId')
            ->andWhere('m.config = :config')
            ->andWhere('m.activo = :activo')
            ->andWhere('m.beds24PropertyId IS NOT NULL')
            ->setParameter('config', $config->getId(), UuidType::NAME)
            ->setParameter('activo', true)
            ->addOrderBy('m.beds24PropertyId', 'ASC');

        if (is_array($unidades) && count($unidades) > 0) {
            $qb->andWhere('m.pmsUnidad IN (:unidades)')
                ->setParameter(
                    'unidades',
                    array_map(static fn (PmsUnidad $u) => $u->getId()->toBinary(), $unidades),
                    ArrayParameterType::BINARY
                );
        }

        $rows = $qb->getQuery()->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            if (isset($row['propertyId'])) {
                $ids[] = (int) $row['propertyId'];
            }
        }

        return array_values(array_unique($ids));
    }
}