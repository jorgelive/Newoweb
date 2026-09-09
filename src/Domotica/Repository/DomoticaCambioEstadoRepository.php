<?php

declare(strict_types=1);

namespace App\Domotica\Repository;

use App\Domotica\Entity\DomoticaCambioEstado;
use App\Domotica\Entity\DomoticaDispositivo;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DomoticaCambioEstado>
 */
class DomoticaCambioEstadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DomoticaCambioEstado::class);
    }

    /**
     * Los cambios de un aparato en una ventana, en orden.
     *
     * @return list<DomoticaCambioEstado>
     */
    public function historial(DomoticaDispositivo $dispositivo, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        /** @var list<DomoticaCambioEstado> $filas */
        $filas = $this->createQueryBuilder('c')
            ->andWhere('c.dispositivo = :dispositivo')
            ->andWhere('c.ocurridoEn >= :desde')
            ->andWhere('c.ocurridoEn <= :hasta')
            ->setParameter('dispositivo', $dispositivo)
            ->setParameter('desde', $desde)
            ->setParameter('hasta', $hasta)
            ->orderBy('c.ocurridoEn', 'ASC')
            ->getQuery()
            ->getResult();

        return $filas;
    }

    /** El último cambio conocido, para saber en qué estado venía. */
    public function ultimo(DomoticaDispositivo $dispositivo): ?DomoticaCambioEstado
    {
        return $this->findOneBy(['dispositivo' => $dispositivo], ['ocurridoEn' => 'DESC']);
    }

    /**
     * Cuántos minutos estuvo encendido dentro de la ventana.
     *
     * ⚠️ **Esto NO es una base de facturación.** Multiplicarlo por unos vatios nominales da una
     * cifra creíble que no cuadra con ningún contador — la trampa de §12.1. Sirve para contarle al
     * huésped cuánto lleva encendida su estufa, para ver que quedó ardiendo en una casa vacía, y
     * para decidir en qué casitas compensa comprar un contómetro.
     *
     * El tramo abierto se cierra en `$hasta`: si sigue encendida ahora, cuenta hasta ahora.
     *
     * ⚠️ Un aparato encendido ANTES de la ventana no tiene fila dentro de ella, así que hay que
     * mirar el estado con el que entró. Sin eso, una estufa que lleva encendida desde ayer contaría
     * cero — y sería el caso que más importa ver.
     */
    public function minutosEncendido(DomoticaDispositivo $dispositivo, DateTimeImmutable $desde, DateTimeImmutable $hasta): int
    {
        $previo = $this->createQueryBuilder('c')
            ->andWhere('c.dispositivo = :dispositivo')
            ->andWhere('c.ocurridoEn < :desde')
            ->setParameter('dispositivo', $dispositivo)
            ->setParameter('desde', $desde)
            ->orderBy('c.ocurridoEn', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $encendido = $previo instanceof DomoticaCambioEstado && $previo->isEncendido();
        $desdeCuando = $desde;
        $minutos = 0;

        foreach ($this->historial($dispositivo, $desde, $hasta) as $cambio) {
            if ($encendido && !$cambio->isEncendido()) {
                $minutos += (int) round(($cambio->getOcurridoEn()->getTimestamp() - $desdeCuando->getTimestamp()) / 60);
            }

            if (!$encendido && $cambio->isEncendido()) {
                $desdeCuando = $cambio->getOcurridoEn();
            }

            $encendido = $cambio->isEncendido();
        }

        // Sigue encendido al final de la ventana: el tramo abierto cuenta hasta el borde.
        if ($encendido) {
            $minutos += (int) round(($hasta->getTimestamp() - $desdeCuando->getTimestamp()) / 60);
        }

        return max(0, $minutos);
    }
}
