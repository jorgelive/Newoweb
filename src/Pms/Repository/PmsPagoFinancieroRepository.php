<?php

declare(strict_types=1);

namespace App\Pms\Repository;

use App\Finanzas\Dto\FinMovimientoFiltro;
use App\Pms\Entity\PmsPagoFinanciero;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PmsPagoFinanciero>
 */
class PmsPagoFinancieroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsPagoFinanciero::class);
    }

    /**
     * Pagos del PMS para la vista de caja de Finanzas.
     *
     * Los JOIN a cabecera y reserva son `innerJoin` + `addSelect`: la lista pinta el
     * localizador y la unidad de cada fila, y sin traerlos aquí Doctrine dispararía dos
     * consultas por pago (N+1). Con 500 filas eso son mil viajes a la base de datos para
     * pintar una tabla.
     *
     * El filtro de texto busca en localizador, referencia y nombre del cliente a la vez,
     * porque es lo que se tiene a mano cuando alguien reclama: o el código de su reserva,
     * o el número de operación del banco, o su nombre.
     *
     * @return PmsPagoFinanciero[]
     */
    public function buscarParaCaja(FinMovimientoFiltro $filtro): array
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.informacionFinanciera', 'info')->addSelect('info')
            ->innerJoin('info.reserva', 'r')->addSelect('r')
            ->leftJoin('p.moneda', 'm')->addSelect('m')
            ->leftJoin('p.cobrador', 'u')->addSelect('u')
            ->orderBy('p.fechaPago', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($filtro->limite);

        if ($filtro->desde !== null) {
            $qb->andWhere('p.fechaPago >= :desde')->setParameter('desde', $filtro->desde);
        }

        if ($filtro->hasta !== null) {
            $qb->andWhere('p.fechaPago <= :hasta')->setParameter('hasta', $filtro->hasta);
        }

        if ($filtro->medio !== null && $filtro->medio !== '') {
            $qb->andWhere('p.medioPago = :medio')->setParameter('medio', $filtro->medio);
        }

        if ($filtro->texto !== null && trim($filtro->texto) !== '') {
            $qb->andWhere($qb->expr()->orX(
                'r.localizador LIKE :texto',
                'p.referencia LIKE :texto',
                'r.nombreCliente LIKE :texto',
                'r.apellidoCliente LIKE :texto',
            ))->setParameter('texto', '%' . trim($filtro->texto) . '%');
        }

        return $qb->getQuery()->getResult();
    }
}
