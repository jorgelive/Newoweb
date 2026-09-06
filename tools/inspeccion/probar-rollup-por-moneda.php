<?php

declare(strict_types=1);

/**
 * ¿El rollup por moneda dice lo mismo que el viejo donde debe, y lo distinto donde debe?
 *
 * Ejecuta el recalculador REAL —los dos modelos a la vez, que es lo que hace en producción—
 * sobre las 317 fichas, EN TRANSACCIÓN CON ROLLBACK, y comprueba:
 *
 *   1. Cada ficha con movimiento tiene sus filas por moneda, y ninguna fila fantasma.
 *   2. En las fichas de UNA sola moneda, la fila nueva y el escalar viejo coinciden al céntimo.
 *      Si no, el modelo nuevo estaría cambiando algo que no debía tocar.
 *   3. En las mixtas, la diferencia es la esperada y se puede explicar.
 *   4. La imputación funciona: al marcar `moneda_saldada` en el cobro de GASUNN, su deuda en
 *      dólares se va a cero y la fila en soles desaparece.
 *   5. Borrar una RESERVA con filas hijas no revienta, y no deja filas huérfanas.
 *
 * ⚠️ Sobre la 5: no vale hacer `DELETE` directo de la ficha. `pms_cargo_financiero` y
 * `pms_pago_financiero` apuntan a ella con `NO ACTION` —los cascadea el ORM, no la base—, así que
 * un `DELETE` crudo da 1451 con o sin la tabla nueva y no probaría nada. El camino real es
 * `$em->remove($reserva)`: el ORM baja hasta la ficha, y **la tabla de totales no está en ninguna
 * cascada del ORM**, así que ahí es donde tiene que actuar el `ON DELETE CASCADE` de la base.
 *
 * Uso: php var/probar-rollup-por-moneda.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Service\Finance\PmsInformacionFinancieraRecalculoService;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conn = $em->getConnection();

// Sin estado: se instancia a mano porque en `dev` es privado (ver probar-costo-teorico.php).
$servicio = new PmsInformacionFinancieraRecalculoService();

$conn->beginTransaction();

try {
    // `fromBinary()`: `fromString()` no acepta 32 hex seguidos, exige los guiones.
    $ids = array_map(
        static fn (array $f): string => (string) \Symfony\Component\Uid\Uuid::fromBinary((string) $f['id']),
        $conn->fetchAllAssociative('SELECT id FROM pms_informacion_financiera'),
    );

    $inicio = microtime(true);
    $servicio->recalcular($ids, $em);
    printf("Recalculadas %d fichas en %.0f ms\n\n", count($ids), (microtime(true) - $inicio) * 1000);

    // ── 1 y 2. Fila nueva contra escalar viejo, ficha a ficha ────────────────
    $filas = $conn->fetchAllAssociative(<<<'SQL'
        SELECT r.localizador,
               i.moneda_id AS base,
               i.total_cargos AS viejo_cargos,
               i.total_pagos  AS viejo_pagos,
               COUNT(t.moneda_id) AS n_monedas,
               GROUP_CONCAT(CONCAT(t.moneda_id, ':', t.total_cargos, '/', t.total_pagos) ORDER BY t.moneda_id) AS detalle,
               SUM(t.total_cargos) AS suma_cargos,
               SUM(t.total_pagos)  AS suma_pagos
        FROM pms_informacion_financiera i
        LEFT JOIN pms_reserva r ON r.id = i.reserva_id
        LEFT JOIN pms_finanzas_total_moneda t ON t.informacion_id = i.id
        GROUP BY i.id
        SQL);

    $unaMoneda = 0;
    $descuadres = [];
    $mixtas = [];
    $sinFilas = 0;

    foreach ($filas as $f) {
        $n = (int) $f['n_monedas'];

        if ($n === 0) {
            // Sin cargos ni cobros: correcto que no tenga filas.
            if ((float) $f['viejo_cargos'] != 0.0 || (float) $f['viejo_pagos'] != 0.0) {
                $descuadres[] = [$f, 'tiene totales viejos pero NINGUNA fila por moneda'];
            }
            ++$sinFilas;
            continue;
        }

        if ($n > 1) {
            $mixtas[] = $f;
            continue;
        }

        ++$unaMoneda;

        // Con una sola moneda no hay nada que convertir: los dos modelos tienen que coincidir.
        $difC = abs((float) $f['suma_cargos'] - (float) $f['viejo_cargos']);
        $difP = abs((float) $f['suma_pagos'] - (float) $f['viejo_pagos']);

        if ($difC > 0.005 || $difP > 0.005) {
            $descuadres[] = [$f, sprintf('difiere del viejo: cargos %+.2f, pagos %+.2f', $difC, $difP)];
        }
    }

    printf("Fichas sin movimiento (0 filas, correcto): %d\n", $sinFilas);
    printf("Fichas de UNA moneda: %d   → %s\n", $unaMoneda, $descuadres === [] ? '✔ todas coinciden con el modelo viejo' : '✘ HAY DESCUADRES');
    printf("Fichas MIXTAS: %d\n\n", count($mixtas));

    foreach ($descuadres as [$f, $motivo]) {
        printf("  ✘ %-10s %s\n", $f['localizador'] ?? '(sin loc)', $motivo);
    }

    echo "── 3. Las mixtas, y por qué difieren ──\n";
    foreach ($mixtas as $f) {
        printf(
            "  %-10s base %s · nuevo [%s] · viejo %s/%s\n",
            $f['localizador'] ?? '(sin loc)', $f['base'], $f['detalle'], $f['viejo_cargos'], $f['viejo_pagos'],
        );
    }

    // ── 4. La imputación ────────────────────────────────────────────────────
    echo "\n── 4. Imputar el cobro de GASUNN a la deuda en dólares ──\n";

    $gasunn = $conn->fetchOne(<<<'SQL'
        SELECT LOWER(HEX(i.id)) FROM pms_informacion_financiera i
        JOIN pms_reserva r ON r.id = i.reserva_id WHERE r.localizador = 'GASUNN'
        SQL);

    if ($gasunn === false) {
        echo "  (GASUNN no está en esta base)\n";
    } else {
        $info = $em->getRepository(PmsInformacionFinanciera::class)
            ->find(\Symfony\Component\Uid\Uuid::fromString(
                preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', (string) $gasunn) ?? '',
            ));
        $usd = $em->getRepository(MaestroMoneda::class)->find('USD');

        foreach ($info?->getPagos() ?? [] as $pago) {
            $pago->setMonedaSaldada($usd);
        }
        $em->flush();

        $servicio->recalcular([(string) $info?->getId()], $em);

        foreach ($conn->fetchAllAssociative(
            'SELECT moneda_id, total_cargos, total_pagos FROM pms_finanzas_total_moneda WHERE informacion_id = UNHEX(?)',
            [$gasunn],
        ) as $t) {
            printf(
                "  %s  cargos %8.2f  pagos %8.2f  saldo %+8.2f\n",
                $t['moneda_id'], (float) $t['total_cargos'], (float) $t['total_pagos'],
                (float) $t['total_cargos'] - (float) $t['total_pagos'],
            );
        }
    }

    // ── 5. El ON DELETE CASCADE ─────────────────────────────────────────────
    //
    // Se reproduce a mano lo que hace la cascada del ORM —quitar cargos y cobros y luego la
    // ficha— en vez de llamar a `$em->remove($reserva)`. Motivo: hoy **ninguna** ficha con filas
    // de totales pertenece a una reserva directa, y `PmsEventoCalendarioIntegrityListener` veta
    // borrar una de OTA, así que ese veto saltaría antes de llegar a lo que se quiere probar.
    //
    // Lo que se comprueba es exactamente lo que no cubre el ORM: la tabla de totales **no está en
    // ninguna cascada suya**, así que quitarla es cosa del `ON DELETE CASCADE` de la base. Si
    // faltara, este DELETE daría 1451 y quedarían filas huérfanas.
    echo "\n── 5. Borrar una ficha con filas de totales ──\n";

    $victima = $conn->fetchOne('SELECT LOWER(HEX(informacion_id)) FROM pms_finanzas_total_moneda LIMIT 1');

    try {
        $conn->executeStatement('DELETE FROM pms_cargo_financiero WHERE informacion_id = UNHEX(?)', [$victima]);
        $conn->executeStatement('DELETE FROM pms_pago_financiero  WHERE informacion_id = UNHEX(?)', [$victima]);
        $conn->executeStatement('DELETE FROM pms_informacion_financiera WHERE id = UNHEX(?)', [$victima]);

        $quedan = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM pms_finanzas_total_moneda WHERE informacion_id = UNHEX(?)',
            [$victima],
        );

        printf("  %s ficha borrada sin error; filas de totales huérfanas: %d (debe ser 0)\n", $quedan === 0 ? '✔' : '✘', $quedan);
    } catch (Throwable $e) {
        printf("  ✘ %s\n", mb_substr($e->getMessage(), 0, 120));
    }
} catch (Throwable $e) {
    printf("\n💥 %s\n%s\n", $e->getMessage(), $e->getTraceAsString());
} finally {
    $conn->rollBack();
    echo "\n↩︎  rollback: no se ha escrito nada.\n";
}
