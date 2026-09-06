<?php

declare(strict_types=1);

/**
 * PRE-VUELO antes de migrar el proveedor anidado en PRODUCCIÓN.
 *
 * `Version20260816160000` sube la presentación del proveedor de la tarifa al componente, y
 * `Version20260816180000` retira las columnas viejas. Entre las dos, cualquier ambigüedad
 * que hubiera en los datos se resuelve eligiendo UNA tarifa — y lo que no se elija se
 * pierde al soltar las columnas.
 *
 * Este script NO escribe nada. Sólo responde, antes de tocar la base:
 *
 *   1. ¿Cuánto hay que mover?
 *   2. ¿Dónde hay AMBIGÜEDAD —tarifas del mismo componente con proveedores distintos—?
 *      Ésa es la lista de componentes a revisar y reasignar después de migrar.
 *   3. ¿Qué se descarta exactamente en cada uno de esos casos?
 *   4. ¿Hay anonimato activo que haya que respetar al invertir la bandera?
 *
 * Correrlo en producción antes de `doctrine:migrations:migrate` y guardar la salida.
 *
 * Uso: php tools/inspeccion/auditar-proveedor-anidado.php
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
/** @var Connection $c */
$c = $em->getConnection();

$columnas = $c->fetchFirstColumn("
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cotizacion_cottarifa'
      AND COLUMN_NAME = 'proveedor_titulo_snapshot'
");

if ($columnas === []) {
    echo "Las columnas viejas ya no están: esta base ya migró. Nada que auditar.\n";
    exit(0);
}

echo "══ Pre-vuelo: proveedor anidado en cotizacion_cottarifa ══\n\n";

// ── 1. Volumen ──────────────────────────────────────────────────────────────
$tot = $c->fetchAssociative("
    SELECT COUNT(*) AS tarifas,
           SUM(proveedor_maestro_id IS NOT NULL) AS con_proveedor,
           SUM(JSON_LENGTH(proveedor_titulo_snapshot) > 0) AS con_titulo,
           SUM(proveedor_servicio_maestro_id IS NOT NULL) AS con_servicio,
           SUM(proveedor_oculto = 1) AS ocultas
    FROM cotizacion_cottarifa
") ?: [];

printf("1. Volumen\n");
foreach ($tot as $k => $v) {
    printf("   %-16s : %s\n", $k, (string) $v);
}

$componentes = (int) $c->fetchOne(
    'SELECT COUNT(DISTINCT cotcomponente_id) FROM cotizacion_cottarifa
     WHERE proveedor_maestro_id IS NOT NULL OR JSON_LENGTH(proveedor_titulo_snapshot) > 0'
);
printf("   %-16s : %d\n\n", 'componentes', $componentes);

// ── 2. Ambigüedad: dónde la migración tendrá que elegir ─────────────────────
$ambiguos = $c->fetchAllAssociative("
    SELECT LOWER(HEX(cotcomponente_id)) AS componente,
           COUNT(*) AS tarifas,
           COUNT(DISTINCT proveedor_maestro_id) AS proveedores_distintos,
           COUNT(DISTINCT NULLIF(CAST(proveedor_titulo_snapshot AS CHAR), '[]')) AS titulos_distintos,
           COUNT(DISTINCT proveedor_servicio_maestro_id) AS servicios_distintos,
           COUNT(DISTINCT proveedor_oculto) AS anonimatos_distintos
    FROM cotizacion_cottarifa
    GROUP BY cotcomponente_id
    HAVING proveedores_distintos > 1
        OR titulos_distintos > 1
        OR servicios_distintos > 1
        OR anonimatos_distintos > 1
    ORDER BY proveedores_distintos DESC, tarifas DESC
");

printf("2. Ambigüedad (componentes cuyas tarifas NO coinciden)\n");

if ($ambiguos === []) {
    printf("   ninguno  ✔  la migración no tiene que elegir: el traslado es exacto.\n\n");
} else {
    printf("   ⚠️  %d componente(s). La migración copiará UNA tarifa entera y descartará\n", count($ambiguos));
    printf("       el resto. Éstos son los que hay que reasignar a mano después.\n\n");
    printf("   %-34s %8s %6s %8s %9s %6s\n", 'componente', 'tarifas', 'prov', 'títulos', 'servicios', 'anon');
    foreach ($ambiguos as $a) {
        printf(
            "   %-34s %8d %6d %8d %9d %6d\n",
            $a['componente'],
            $a['tarifas'],
            $a['proveedores_distintos'],
            $a['titulos_distintos'],
            $a['servicios_distintos'],
            $a['anonimatos_distintos'],
        );
    }
    echo "\n";

    // ── 3. Qué se descarta exactamente ──────────────────────────────────────
    printf("3. Detalle de lo que se descarta\n");
    foreach (array_slice($ambiguos, 0, 10) as $a) {
        $detalle = $c->fetchAllAssociative(
            "SELECT LOWER(HEX(id)) AS tarifa,
                    COALESCE(nombre_interno_snapshot, '(sin nombre)') AS tarifa_nombre,
                    COALESCE(proveedor_nombre_snapshot, '—') AS proveedor,
                    CASE WHEN JSON_LENGTH(proveedor_titulo_snapshot) > 0 THEN 'sí' ELSE 'no' END AS titulo,
                    proveedor_oculto AS oculto
             FROM cotizacion_cottarifa
             WHERE cotcomponente_id = UNHEX(:cid)
             ORDER BY (proveedor_maestro_id IS NULL), id",
            ['cid' => $a['componente']]
        );

        printf("   ── componente %s\n", $a['componente']);
        foreach ($detalle as $i => $d) {
            printf(
                "      %s %-28s prov=%-24s título=%-3s oculto=%d\n",
                $i === 0 ? 'GANA →' : '      ',
                mb_substr((string) $d['tarifa_nombre'], 0, 28),
                mb_substr((string) $d['proveedor'], 0, 24),
                $d['titulo'],
                (int) $d['oculto'],
            );
        }
    }
    if (count($ambiguos) > 10) {
        printf("   … y %d más\n", count($ambiguos) - 10);
    }
    echo "\n";
}

// ── 4. Anonimato activo ─────────────────────────────────────────────────────
$globales = (int) $c->fetchOne('SELECT COUNT(*) FROM cotizacion_cotizacion WHERE proveedor_oculto = 1');
$porTarifa = (int) $tot['ocultas'];

printf("4. Anonimato activo (se invierte a `proveedor_visible`)\n");
printf("   cotizaciones con flag global : %d  (no se toca: sigue en Cotizacion)\n", $globales);
printf("   tarifas ocultas              : %d  %s\n", $porTarifa,
    $porTarifa === 0 ? '✔ nada que preservar' : '→ su componente quedará NO visible');

echo "\n══ Fin. Este script no modificó nada. ══\n";
