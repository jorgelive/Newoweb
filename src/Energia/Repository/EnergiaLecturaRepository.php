<?php

declare(strict_types=1);

namespace App\Energia\Repository;

use App\Energia\Entity\EnergiaDispositivo;
use App\Energia\Entity\EnergiaLectura;
use App\Energia\Entity\EnergiaSuscripcion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EnergiaLectura>
 */
class EnergiaLecturaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnergiaLectura::class);
    }

    /**
     * El historial de una estancia, en orden de lectura.
     *
     * Es lo que se le enseña al huésped, así que va ASCENDENTE: se lee como un extracto, desde que
     * entró hasta ahora.
     *
     * @return list<EnergiaLectura>
     */
    public function historialDe(EnergiaSuscripcion $suscripcion): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.suscripcion = :s')
            ->setParameter('s', $suscripcion)
            ->orderBy('l.leidaEn', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * La última fila escrita para un aparato.
     *
     * De aquí sale el acumulado sobre el que suma el muestreo siguiente: el servicio toma el
     * último registro y le añade la diferencia de contadores. Por eso el desempate por `id` no es
     * cosmético —`leida_en` tiene precisión de segundo y dos filas de la misma hora ordenarían al
     * azar, que en una suma acumulada significa perder o duplicar un tramo.
     */
    public function ultimaDe(EnergiaDispositivo $dispositivo): ?EnergiaLectura
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.dispositivo = :d')
            ->setParameter('d', $dispositivo)
            ->orderBy('l.leidaEn', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * ¿Ya está escrito este cubo horario?
     *
     * La restricción única de la tabla lo impediría igual, pero comprobarlo antes evita gastar una
     * excepción —y una llamada a Tuya— en cada reintento del cron.
     */
    public function tieneCubo(EnergiaDispositivo $dispositivo, string $cuboHorario): bool
    {
        return $this->count(['dispositivo' => $dispositivo, 'cuboHorario' => $cuboHorario]) > 0;
    }
}
