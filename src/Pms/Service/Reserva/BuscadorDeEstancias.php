<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Estancias por texto libre o «de estos días».
 *
 * Lo comparten el buscador del calendario (`PmsReservaBuscarController`) y los candidatos que el
 * chat ofrece para enlazar un hilo (`PmsCandidatosDeAsunto`): la consulta es la misma y tenerla
 * dos veces es tener dos criterios de «qué reserva es ésta».
 */
final readonly class BuscadorDeEstancias
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Cada palabra debe aparecer en algún campo (AND entre términos): «juan perez» encuentra al
     * huésped aunque nombre y apellido estén en columnas distintas, y «perez casita» no devuelve a
     * todos los Pérez. Ordenadas por cercanía a hoy: lo que uno busca es la reserva de estos días,
     * no la de hace dos años.
     *
     * @return list<PmsEventoCalendario>
     */
    public function porTexto(string $q, int $limite): array
    {
        $qb = $this->base()
            ->orderBy('ABS(DATE_DIFF(e.inicio, CURRENT_DATE()))', 'ASC')
            ->setMaxResults($limite);

        foreach (preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $termino) {
            $qb->andWhere($qb->expr()->orX(
                "LOWER(CONCAT(COALESCE(r.nombreCliente, ''), ' ', COALESCE(r.apellidoCliente, ''))) LIKE :t$i",
                "LOWER(COALESCE(r.localizador, '')) LIKE :t$i",
                "LOWER(COALESCE(e.referenciaCanal, '')) LIKE :t$i",
                "LOWER(COALESCE(u.nombre, '')) LIKE :t$i",
            ))->setParameter("t$i", '%' . mb_strtolower($termino) . '%');
        }

        /** @var list<PmsEventoCalendario> $estancias */
        $estancias = $qb->getQuery()->getResult();

        return $estancias;
    }

    /**
     * Alojadas ahora o llegando en ±2 días, sin canceladas ni bloqueos.
     *
     * @return list<PmsEventoCalendario>
     */
    public function deEstosDias(DateTimeImmutable $hoy): array
    {
        /** @var list<PmsEventoCalendario> $estancias */
        $estancias = $this->base()
            ->where('e.inicio <= :hasta')
            ->andWhere('e.fin >= :desde')
            ->andWhere('es.id NOT IN (:fuera)')
            ->setParameter('hasta', $hoy->modify('+3 days'))
            ->setParameter('desde', $hoy->modify('-1 day'))
            ->setParameter('fuera', [PmsEventoEstado::CODIGO_CANCELADA, PmsEventoEstado::CODIGO_BLOQUEO])
            ->orderBy('e.inicio', 'ASC')
            ->getQuery()
            ->getResult();

        return $estancias;
    }

    private function base(): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('e, u, r, es, ep, c')
            ->from(PmsEventoCalendario::class, 'e')
            ->innerJoin('e.reserva', 'r')
            ->leftJoin('e.pmsUnidad', 'u')
            ->leftJoin('e.estado', 'es')
            ->leftJoin('e.estadoPago', 'ep')
            ->leftJoin('e.channel', 'c');
    }
}
