<?php

declare(strict_types=1);

/**
 * ¿Qué estancias cambian de estado de pago al decidir por moneda en vez de por el escalar viejo?
 *
 * Es la pregunta que decide si la Fase 2 se puede desplegar. EN TRANSACCIÓN CON ROLLBACK:
 *
 *   1. Se recalculan las 317 fichas (rollup por moneda + escalares viejos).
 *   2. Se guarda el estado de pago actual de cada estancia.
 *   3. Se corre `PmsEstadoPagoEventosService::sincronizar()` con la lógica NUEVA.
 *   4. Se listan una a una las estancias que cambiaron, con el porqué.
 *
 * **Si cambian más que las fichas mixtas conocidas, algo está mal.**
 *
 * Y se comprueba el punto que motivó el umbral: `XTHRMQ` deja +0.10 de diferencia cambiaria y
 * **tiene que quedar como pagada**, no arrastrar un «parcial» eterno por diez céntimos.
 *
 * Uso: php var/probar-estado-pago-por-moneda.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Service\Finance\PmsEstadoPagoEventosService;
use App\Pms\Service\Finance\PmsInformacionFinancieraRecalculoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conn = $em->getConnection();

$recalculo = new PmsInformacionFinancieraRecalculoService();
$estados = new PmsEstadoPagoEventosService(new SyncContext(), new NullLogger());

$conn->beginTransaction();

try {
    $ids = array_map(
        static fn (array $f): string => (string) Uuid::fromBinary((string) $f['id']),
        $conn->fetchAllAssociative('SELECT id FROM pms_informacion_financiera'),
    );

    $recalculo->recalcular($ids, $em);

    /** Estado de pago de cada estancia, indexado por id. */
    $foto = static fn (): array => array_column(
        $conn->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, estado_pago_id FROM pms_evento_calendario'),
        'estado_pago_id',
        'id',
    );

    $antes = $foto();
    $estados->sincronizar($ids, $em);
    $despues = $foto();

    $cambios = [];
    foreach ($despues as $id => $estado) {
        if (($antes[$id] ?? null) !== $estado) {
            $cambios[$id] = [$antes[$id] ?? '(nuevo)', $estado];
        }
    }

    printf("Estancias que cambian de estado de pago: %d\n\n", count($cambios));

    if ($cambios !== []) {
        $detalle = $conn->fetchAllAssociative(sprintf(
            <<<'SQL'
                SELECT LOWER(HEX(e.id)) AS id, r.localizador, u.nombre AS casita,
                       i.moneda_id AS base, i.tipo_cambio,
                       GROUP_CONCAT(CONCAT(t.moneda_id, ' ', t.total_cargos, '/', t.total_pagos)
                                    ORDER BY t.moneda_id SEPARATOR ' · ') AS totales,
                       COUNT(DISTINCT t.moneda_id) AS n_monedas
                FROM pms_evento_calendario e
                JOIN pms_reserva r ON r.id = e.reserva_id
                LEFT JOIN pms_unidad u ON u.id = e.pms_unidad_id
                JOIN pms_informacion_financiera i ON i.reserva_id = e.reserva_id
                LEFT JOIN pms_finanzas_total_moneda t ON t.informacion_id = i.id
                WHERE LOWER(HEX(e.id)) IN (%s)
                GROUP BY e.id
                SQL,
            implode(',', array_map(static fn (string $id): string => "'" . $id . "'", array_keys($cambios))),
        ));

        foreach ($detalle as $d) {
            [$de, $a] = $cambios[$d['id']];
            printf(
                "  %-8s %-10s  %s → %s\n     %s   (base %s, TC %s, %d moneda%s)\n",
                $d['localizador'], $d['casita'] ?? '—', $de, $a,
                $d['totales'], $d['base'], $d['tipo_cambio'] ?? '—',
                (int) $d['n_monedas'], (int) $d['n_monedas'] === 1 ? '' : 's',
            );
        }
    }

    // ── El caso que motivó el umbral ────────────────────────────────────────
    echo "\n── XTHRMQ: +0.10 de diferencia cambiaria ──\n";

    $xthrmq = $conn->fetchAllAssociative(<<<'SQL'
        SELECT e.estado_pago_id, u.nombre AS casita
        FROM pms_evento_calendario e
        JOIN pms_reserva r ON r.id = e.reserva_id
        LEFT JOIN pms_unidad u ON u.id = e.pms_unidad_id
        -- Mismos filtros que los dos UPDATE, o la sonda mide otra cosa: una estancia CANCELADA
        -- se excluye a propósito y quedarse en `no-pagado` es lo correcto, no un fallo.
        WHERE r.localizador = 'XTHRMQ'
          AND e.evento_origen_id IS NULL
          AND e.estado_id <> 'cancelada'
        SQL);

    foreach ($xthrmq as $e) {
        printf(
            "  %-12s %-16s %s\n",
            $e['casita'] ?? '—', $e['estado_pago_id'],
            $e['estado_pago_id'] === 'pago-total' ? '✔ el umbral hace su trabajo' : '✘ arrastra un parcial por 10 céntimos',
        );
    }
} catch (Throwable $e) {
    printf("\n💥 %s\n%s\n", $e->getMessage(), $e->getTraceAsString());
} finally {
    $conn->rollBack();
    echo "\n↩︎  rollback: no se ha escrito nada.\n";
}
