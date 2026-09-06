<?php

declare(strict_types=1);

/**
 * FASE 0 del rediseño «contabilidad por moneda»: medir antes de tocar nada.
 *
 * Sonda de **SOLO LECTURA** (no abre transacción porque no escribe una sola fila). Responde las
 * tres preguntas que deciden si el plan es viable tal como está escrito:
 *
 *   1. ¿Cuántas fichas tienen deuda en MÁS DE UNA moneda? Son las únicas que cambian de aspecto.
 *   2. ¿Cuánto da su CUADRE con el tipo de cambio del día en que se abrió la ficha? Es lo que
 *      fija `UMBRAL_CUADRE`: hay que verlo, no adivinarlo.
 *   3. ¿Qué fichas pierden hoy registros porque les falta el tipo de cambio? Ésas son las que el
 *      modelo nuevo arregla solo, y la diferencia entre el total viejo y el nuevo lo demuestra.
 *
 * El rollup nuevo se calcula aquí con el MISMO SQL que llevará
 * `PmsInformacionFinancieraRecalculoService`, para que lo que se mida sea lo que se va a
 * desplegar y no una aproximación escrita para la ocasión.
 *
 * Uso: php tools/inspeccion/probar-totales-moneda.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
/** @var Connection $conn */
$conn = $em->getConnection();

// ── El rollup NUEVO, tal cual irá al servicio ────────────────────────────────
// Cargos en su propia moneda; cobros en la suya. Sin `moneda_saldada` todavía (la columna no
// existe): esta sonda mide el estado ANTES de la imputación, que es justo lo que hace falta para
// encontrar los GASUNN.
$SQL_NUEVO = <<<'SQL'
    SELECT m.informacion_id, m.moneda_id,
           SUM(m.cargo) AS total_cargos,
           SUM(m.pago)  AS total_pagos
    FROM (
        SELECT c.informacion_id, COALESCE(c.moneda_id,'USD') AS moneda_id,
               COALESCE(c.total_linea, c.monto, 0) AS cargo, 0 AS pago
        FROM pms_cargo_financiero c
        INNER JOIN pms_informacion_financiera i2 ON i2.id = c.informacion_id
        WHERE c.tipo = 'charge'
          AND (i2.activa = 1 OR c.tipo_cargo = 'penalizacion')

        UNION ALL

        SELECT p.informacion_id, COALESCE(p.moneda_id,'USD'), 0, COALESCE(p.monto, 0)
        FROM pms_pago_financiero p
    ) m
    GROUP BY m.informacion_id, m.moneda_id
    SQL;

$porFicha = [];
foreach ($conn->fetchAllAssociative($SQL_NUEVO) as $fila) {
    $porFicha[bin2hex((string) $fila['informacion_id'])][(string) $fila['moneda_id']] = [
        'cargos' => (float) $fila['total_cargos'],
        'pagos' => (float) $fila['total_pagos'],
    ];
}

// ── Lo que hay guardado hoy, y con qué localizador reconocerlo ───────────────
$actual = [];
$sqlActual = <<<'SQL'
    SELECT i.id, i.moneda_id, i.total_cargos, i.total_pagos, i.activa, i.created_at,
           r.localizador
    FROM pms_informacion_financiera i
    LEFT JOIN pms_reserva r ON r.id = i.reserva_id
    SQL;

foreach ($conn->fetchAllAssociative($sqlActual) as $fila) {
    $actual[bin2hex((string) $fila['id'])] = $fila;
}

// ── El tipo de cambio del día, para el cuadre ────────────────────────────────
$tcPorDia = [];
foreach ($conn->fetchAllAssociative("SELECT fecha, venta FROM maestro_tipocambio WHERE moneda_id = 'USD'") as $f) {
    $tcPorDia[(string) $f['fecha']] = (float) $f['venta'];
}
$tcUltimo = $tcPorDia === [] ? 0.0 : (float) end($tcPorDia);

/** El TC de ese día, o el último conocido — mismo criterio que `TipocambioManager`. */
$tcDe = static function (?string $fechaHora) use ($tcPorDia, $tcUltimo): float {
    $dia = substr((string) $fechaHora, 0, 10);

    return $tcPorDia[$dia] ?? $tcUltimo;
};

// ── Informe ──────────────────────────────────────────────────────────────────
$multi = [];
$cuadres = [];
$discrepan = [];

foreach ($porFicha as $hex => $monedas) {
    $ficha = $actual[$hex] ?? null;

    if ($ficha === null) {
        continue;
    }

    $base = (string) ($ficha['moneda_id'] ?? 'USD');
    $tc = $tcDe($ficha['created_at'] ?? null);

    // ¿En cuántas monedas hay algo que no sea cero?
    $conMovimiento = array_filter($monedas, static fn (array $m): bool => $m['cargos'] != 0.0 || $m['pagos'] != 0.0);

    // El CUADRE: los saldos de cada moneda, llevados todos a la base con UN solo TC.
    $cuadre = 0.0;
    foreach ($conMovimiento as $moneda => $m) {
        $saldo = $m['cargos'] - $m['pagos'];
        $cuadre += match (true) {
            $moneda === $base => $saldo,
            $moneda === 'USD' && $base === 'PEN' => $saldo * $tc,
            $moneda === 'PEN' && $base === 'USD' => $tc > 0 ? $saldo / $tc : 0.0,
            default => $saldo,
        };
    }

    // El total viejo vs. el nuevo, en la moneda base: la diferencia es lo que hoy se pierde.
    $viejoCargos = (float) ($ficha['total_cargos'] ?? 0);
    $viejoSaldo = $viejoCargos - (float) ($ficha['total_pagos'] ?? 0);

    if (count($conMovimiento) > 1) {
        // ⚠️ Dos casos que parecen el mismo y NO lo son, y sólo uno fija el umbral:
        //
        //   · CRUCE  — hay una moneda con COBROS Y SIN NINGÚN CARGO. Ese dinero no puede
        //     saldar nada suyo: o paga la deuda de la otra moneda, o es un regalo. Es el patrón
        //     de GASUNN, y aquí el cuadre debe dar ≈0: lo que sobre es redondeo del cambio, que
        //     es justo lo que hay que medir para fijar el umbral.
        //   · DEUDA EN DOS — hay cargos en las dos monedas. El cuadre da lo que de verdad se
        //     debe, y tiene que quedar FUERA del umbral: no es un residuo, es dinero.
        //
        // ⚠️ La prueba mira SÓLO el lado de los cobros. Exigir además una moneda «con cargos y
        // sin cobros» dejaba fuera a XTHRMQ, cuya deuda en soles está pagada a medias en soles
        // y a medias en dólares — que es un cruce de manual.
        $esCruce = array_filter(
            $conMovimiento,
            static fn (array $m): bool => $m['pagos'] != 0.0 && $m['cargos'] == 0.0,
        ) !== [];

        $multi[] = [$ficha, $conMovimiento, $cuadre, $tc, $base, $esCruce];

        if ($esCruce) {
            $cuadres[] = abs($cuadre);
        }
    }

    if (abs($cuadre - $viejoSaldo) > 0.01) {
        $discrepan[] = [$ficha, $conMovimiento, $cuadre, $viejoSaldo, $base];
    }
}

printf("Fichas con movimiento: %d de %d\n", count($porFicha), count($actual));
printf("Fichas con MÁS DE UNA moneda: %d\n\n", count($multi));

echo str_repeat('─', 78), "\n";
echo "1) LAS QUE CAMBIAN DE ASPECTO (deuda en más de una moneda)\n";
echo str_repeat('─', 78), "\n";

foreach ($multi as [$ficha, $monedas, $cuadre, $tc, $base, $esCruce]) {
    printf(
        "\n%-8s  %-14s base %s · abierta %s · TC %.3f%s\n",
        $ficha['localizador'] ?? '(sin loc)',
        $esCruce ? '[CRUCE]' : '[DEUDA EN DOS]',
        $base,
        substr((string) $ficha['created_at'], 0, 10),
        $tc,
        ((int) $ficha['activa'] === 0 ? '  ⚠️ ANULADA' : ''),
    );

    foreach ($monedas as $moneda => $m) {
        printf(
            "    %s  cargos %10.2f   pagos %10.2f   saldo %10.2f\n",
            $moneda, $m['cargos'], $m['pagos'], $m['cargos'] - $m['pagos'],
        );
    }

    printf(
        "    → CUADRE en %s: %+.2f   %s\n",
        $base,
        $cuadre,
        $esCruce
            ? (abs($cuadre) <= 1.0 ? '✔ residuo de cambio, imputable con un clic' : '✘ FUERA: no es sólo redondeo, míralo')
            : 'deuda real en dos monedas — tiene que quedar fuera del umbral',
    );
}

echo "\n", str_repeat('─', 78), "\n";
echo "2) EL UMBRAL: qué residuo dejan los CRUCES (las deudas reales no cuentan aquí)\n";
echo str_repeat('─', 78), "\n";

if ($cuadres === []) {
    echo "  (ningún cruce de monedas)\n";
} else {
    sort($cuadres);
    printf("  n=%d   mín %.2f   mediana %.2f   máx %.2f\n", count($cuadres), $cuadres[0], $cuadres[intdiv(count($cuadres), 2)], end($cuadres));
    foreach ([0.50, 1.00, 2.00, 5.00] as $u) {
        $dentro = count(array_filter($cuadres, static fn (float $c): bool => $c <= $u));
        printf("  umbral %4.2f → cuadran %d de %d cruces\n", $u, $dentro, count($cuadres));
    }
}

echo "\n", str_repeat('─', 78), "\n";
echo "3) DONDE EL TOTAL VIEJO Y EL NUEVO NO DICEN LO MISMO\n";
echo "   (normalmente: registros que hoy desaparecen por falta de tipo de cambio)\n";
echo str_repeat('─', 78), "\n";

if ($discrepan === []) {
    echo "  ninguna: el modelo nuevo reproduce el saldo actual en todas las fichas.\n";
}

foreach ($discrepan as [$ficha, $monedas, $cuadre, $viejoSaldo, $base]) {
    printf(
        "\n%-8s  saldo guardado %.2f %s   →   cuadre nuevo %+.2f %s   (dif %+.2f)\n",
        $ficha['localizador'] ?? '(sin loc)', $viejoSaldo, $base, $cuadre, $base, $cuadre - $viejoSaldo,
    );

    foreach ($monedas as $moneda => $m) {
        printf("    %s  saldo %10.2f\n", $moneda, $m['cargos'] - $m['pagos']);
    }
}

echo "\nNada se ha escrito: esta sonda sólo hace SELECT.\n";
