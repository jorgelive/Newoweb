<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class PmsReservaRecalculoService
{
    /**
     * Recalcula datos de la reserva a partir de TODOS sus eventos persistidos:
     * - fechaLlegada  = MIN(inicio) (DATE)
     * - fechaSalida   = MAX(fin)    (DATE)
     * - montoTotal    = SUM(monto)
     * - comisionTotal = SUM(comision)
     * - cantidadAdultos = SUM(cantidadAdultos)
     * - cantidadNinos   = SUM(cantidadNinos)
     *
     * Lee SIEMPRE desde BD (no desde UoW) y actualiza por DQL UPDATE.
     *
     * @param int[] $reservaIds
     */
    public function recalcularDesdeEventos(array $reservaIds, EntityManagerInterface $em): void
    {
        if ($reservaIds === []) {
            return;
        }

        $rows = $em->createQuery(<<<'DQL'
            SELECT
                r.id AS reservaId,
                MIN(DATE(e.inicio)) AS fechaMin,
                MAX(DATE(e.fin))    AS fechaMax,
                COALESCE(SUM(COALESCE(e.monto, 0)), 0)           AS totalMonto,
                COALESCE(SUM(COALESCE(e.comision, 0)), 0)        AS totalComision,
                COALESCE(SUM(COALESCE(e.cantidadAdultos, 0)), 0) AS totalAdultos,
                COALESCE(SUM(COALESCE(e.cantidadNinos, 0)), 0)   AS totalNinos
            FROM App\Pms\Entity\PmsEventoCalendario e
            JOIN e.reserva r
            WHERE r.id IN (:ids)
            GROUP BY r.id
        DQL)
            ->setParameter('ids', $reservaIds)
            ->getArrayResult();

        if ($rows === []) {
            return;
        }

        foreach ($rows as $row) {
            $fechaLlegada = ($row['fechaMin'] ?? null)
                ? new DateTimeImmutable((string) $row['fechaMin'])
                : null;

            $fechaSalida = ($row['fechaMax'] ?? null)
                ? new DateTimeImmutable((string) $row['fechaMax'])
                : null;

            $em->createQuery(<<<'DQL'
                UPDATE App\Pms\Entity\PmsReserva r
                SET
                    r.fechaLlegada    = :fechaLlegada,
                    r.fechaSalida     = :fechaSalida,
                    r.montoTotal      = :montoTotal,
                    r.comisionTotal   = :comisionTotal,
                    r.cantidadAdultos = :cantidadAdultos,
                    r.cantidadNinos   = :cantidadNinos
                WHERE r.id = :id
            DQL)
                ->setParameter('fechaLlegada', $fechaLlegada)
                ->setParameter('fechaSalida', $fechaSalida)
                ->setParameter('montoTotal', (string) ($row['totalMonto'] ?? '0'))
                ->setParameter('comisionTotal', (string) ($row['totalComision'] ?? '0'))
                ->setParameter('cantidadAdultos', (int) ($row['totalAdultos'] ?? 0))
                ->setParameter('cantidadNinos', (int) ($row['totalNinos'] ?? 0))
                ->setParameter('id', (int) $row['reservaId'])
                ->execute();
        }
    }
}