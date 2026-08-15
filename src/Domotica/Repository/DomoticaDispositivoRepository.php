<?php

declare(strict_types=1);

namespace App\Domotica\Repository;

use App\Domotica\Entity\DomoticaDispositivo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DomoticaDispositivo>
 */
class DomoticaDispositivoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DomoticaDispositivo::class);
    }

    public function porTuyaId(string $tuyaDeviceId): ?DomoticaDispositivo
    {
        return $this->findOneBy(['tuyaDeviceId' => $tuyaDeviceId]);
    }

    /**
     * Los que el cron tiene que muestrear.
     *
     * @return list<DomoticaDispositivo>
     */
    public function activos(bool $soloConContometro = false): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.activo = true');

        if ($soloConContometro) {
            $qb->andWhere('d.mideConsumo = true');
        }

        return $qb
            ->orderBy('d.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Los que llevan demasiado sin dar señales.
     *
     * Es la consulta de la alerta: un aparato mudo no se nota por sí solo —la cuenta simplemente
     * deja de subir, que es exactamente lo que el huésped esperaría ver— y el descubrimiento
     * llegaría el día de cobrar.
     *
     * @return list<DomoticaDispositivo>
     */
    public function mudos(int $horas = 2): array
    {
        $limite = new \DateTimeImmutable(sprintf('-%d hours', $horas));

        return $this->createQueryBuilder('d')
            ->andWhere('d.activo = true')
            // Los que no miden nunca están mudos: avisar de ellos cada tres horas quemaría el canal.
            ->andWhere('d.mideConsumo = true')
            ->andWhere('d.lecturaTomadaEn IS NULL OR d.lecturaTomadaEn < :limite')
            ->setParameter('limite', $limite)
            ->orderBy('d.lecturaTomadaEn', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
