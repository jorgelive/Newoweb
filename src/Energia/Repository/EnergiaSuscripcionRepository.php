<?php

declare(strict_types=1);

namespace App\Energia\Repository;

use App\Energia\Entity\EnergiaDispositivo;
use App\Energia\Entity\EnergiaSuscripcion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<EnergiaSuscripcion>
 */
class EnergiaSuscripcionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnergiaSuscripcion::class);
    }

    /**
     * Las cuentas vivas en este momento: lo que recorre el cron cada hora.
     *
     * @return list<EnergiaSuscripcion>
     */
    public function vigentes(?\DateTimeImmutable $momento = null): array
    {
        $momento ??= new \DateTimeImmutable();

        return $this->createQueryBuilder('s')
            ->addSelect('d')
            ->join('s.dispositivo', 'd')
            ->andWhere('s.activa = true')
            ->andWhere('d.activo = true')
            ->andWhere('s.iniciaEn <= :ahora')
            ->andWhere('s.terminaEn >= :ahora')
            ->setParameter('ahora', $momento)
            ->getQuery()
            ->getResult();
    }

    /**
     * La cuenta abierta de un aparato, si la hay.
     *
     * Sirve para el caso más frecuente del panel —«¿de quién es lo que está gastando ahora?»— y
     * para no abrir una segunda cuenta encima de una viva.
     */
    public function abiertaDe(EnergiaDispositivo $dispositivo): ?EnergiaSuscripcion
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.dispositivo = :d')
            ->setParameter('d', $dispositivo)
            ->andWhere('s.activa = true')
            ->orderBy('s.iniciaEn', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Todo lo enchufado a una estancia. Es lo que lee la app del huésped.
     *
     * ⚠️ El evento se pasa por su UUID y con el tipo `'uuid'` declarado: la columna es BINARY(16) y
     * pasar la entidad a pelo devuelve CERO FILAS SIN ERROR. Ya costó una tarde en `EscaleraDeTemas`.
     *
     * @return list<EnergiaSuscripcion>
     */
    public function delEvento(Uuid $eventoId): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('d')
            ->join('s.dispositivo', 'd')
            ->andWhere('IDENTITY(s.evento) = :evento')
            ->setParameter('evento', $eventoId, 'uuid')
            ->orderBy('d.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
