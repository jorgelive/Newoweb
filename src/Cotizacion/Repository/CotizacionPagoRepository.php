<?php

declare(strict_types=1);

namespace App\Cotizacion\Repository;

use App\Cotizacion\Entity\CotizacionPago;
use App\Finanzas\Dto\FinMovimientoFiltro;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CotizacionPago> */
final class CotizacionPagoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CotizacionPago::class);
    }

    /**
     * Los pagos para la caja de Finanzas, del más reciente al más antiguo. Sin los anulados: la
     * caja cuenta el dinero que entró, y un pago anulado no entró (o se devolvió).
     *
     * @return list<CotizacionPago>
     */
    public function buscarParaCaja(FinMovimientoFiltro $filtro): array
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.file', 'f')->addSelect('f')
            ->leftJoin('p.registradoPor', 'u')->addSelect('u')
            ->where('p.anulado = false')
            ->orderBy('p.fechaPago', 'DESC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->setMaxResults($filtro->limite);

        if ($filtro->desde !== null) {
            $qb->andWhere('p.fechaPago >= :desde')->setParameter('desde', $filtro->desde);
        }

        if ($filtro->hasta !== null) {
            $qb->andWhere('p.fechaPago <= :hasta')->setParameter('hasta', $filtro->hasta);
        }

        if ($filtro->medio !== null && $filtro->medio !== '') {
            $qb->andWhere('p.medio = :medio')->setParameter('medio', $filtro->medio);
        }

        if ($filtro->texto !== null && trim($filtro->texto) !== '') {
            $qb->andWhere($qb->expr()->orX(
                'f.localizador LIKE :texto',
                'f.nombreGrupo LIKE :texto',
                'f.pasajeroPrincipal LIKE :texto',
                'p.referencia LIKE :texto',
            ))->setParameter('texto', '%' . trim($filtro->texto) . '%');
        }

        /** @var list<CotizacionPago> $resultado */
        $resultado = $qb->getQuery()->getResult();

        return $resultado;
    }
}
