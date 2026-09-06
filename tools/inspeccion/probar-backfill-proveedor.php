<?php

declare(strict_types=1);

/**
 * ¿El backfill de `Version20260816160000` aguanta datos AMBIGUOS?
 *
 * Los datos locales no sirven para probarlo por dos motivos: ya migraron (las columnas
 * viejas no existen) y, cuando existían, no tenían ni una colisión. Producción sí puede
 * tenerlas, y ahí está el riesgo que esta sonda cubre:
 *
 *   1. **Nada de Frankenstein.** Con tres tarifas de proveedores distintos, TODOS los
 *      campos copiados tienen que venir de la MISMA tarifa. Un `MAX()` por columna —que
 *      es lo que había— cogería el título de una y la URL de otra, y montaría un
 *      proveedor que no corresponde a ninguna empresa real.
 *   2. **Elección estable.** El mismo conjunto de filas tiene que dar el mismo ganador
 *      aunque cambie el orden físico de inserción.
 *   3. **Preferencia correcta.** Gana la que identifica al proveedor contra el maestro y
 *      trae título público, no simplemente la primera que aparezca.
 *   4. **La bandera se siembra con la regla vieja**: visible ⟺ había título y no estaba
 *      oculta.
 *
 * Trabaja sobre tablas temporales `zz_probe_*` que crea y destruye. No toca nada real.
 *
 * ⚠️ La consulta de abajo es un ESPEJO de la de la migración: si cambia una, cambia la
 * otra. Se duplica a propósito para poder apuntarla a las tablas de prueba.
 *
 * Uso: php var/probar-backfill-proveedor.php
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

$c->executeStatement('DROP TABLE IF EXISTS zz_probe_tarifa');
$c->executeStatement('CREATE TABLE zz_probe_tarifa (
    id BINARY(16) PRIMARY KEY,
    cotcomponente_id BINARY(16) NOT NULL,
    nombre_interno_snapshot VARCHAR(150) NULL,
    proveedor_maestro_id VARCHAR(36) NULL,
    proveedor_nombre_snapshot VARCHAR(150) NULL,
    proveedor_titulo_snapshot JSON NULL,
    proveedor_url_snapshot VARCHAR(255) NULL,
    proveedor_imagenes_snapshot JSON NULL,
    proveedor_servicio_maestro_id VARCHAR(36) NULL,
    proveedor_servicio_titulo_snapshot JSON NULL,
    proveedor_servicio_url_snapshot VARCHAR(255) NULL,
    proveedor_servicio_imagenes_snapshot JSON NULL,
    proveedor_oculto TINYINT(1) NOT NULL DEFAULT 0
)');

$COMP = '11111111111111111111111111111111';

/** Inserta una tarifa de prueba. */
$insertar = static function (
    Connection $c,
    string $hexId,
    string $nombre,
    ?string $maestro,
    ?string $prov,
    ?string $titulo,
    ?string $url,
    int $oculto = 0,
) use ($COMP): void {
    $c->executeStatement(
        'INSERT INTO zz_probe_tarifa (id, cotcomponente_id, nombre_interno_snapshot,
            proveedor_maestro_id, proveedor_nombre_snapshot, proveedor_titulo_snapshot,
            proveedor_url_snapshot, proveedor_imagenes_snapshot,
            proveedor_servicio_maestro_id, proveedor_servicio_titulo_snapshot,
            proveedor_servicio_url_snapshot, proveedor_servicio_imagenes_snapshot, proveedor_oculto)
         VALUES (UNHEX(:id), UNHEX(:comp), :nom, :mid, :prov, :tit, :url, :img,
                 :smid, :stit, :surl, :simg, :oc)',
        [
            'id' => $hexId, 'comp' => $COMP, 'nom' => $nombre,
            'mid' => $maestro, 'prov' => $prov,
            'tit' => $titulo === null ? '[]' : json_encode([['language' => 'es', 'content' => $titulo]]),
            'url' => $url,
            'img' => json_encode([['imageUrl' => $url . '/foto.jpg', 'orden' => 1, 'isPortada' => true]]),
            'smid' => $maestro === null ? null : $maestro . '-serv',
            'stit' => $titulo === null ? '[]' : json_encode([['language' => 'es', 'content' => $titulo . ' · Doble']]),
            'surl' => $url === null ? null : $url . '/habitacion',
            'simg' => '[]',
            'oc' => $oculto,
        ]
    );
};

// Tres tarifas del MISMO componente con proveedores distintos. `bbb…` es la única que
// tiene maestro Y título, así que debe ganar aunque no sea la de id más bajo.
$insertar($c, str_repeat('aa', 16), 'Adulto',   null,     'Suelto SA',  null,      null,                 0);
$insertar($c, str_repeat('bb', 16), 'Niño',     'uuid-B', 'Cosituc',    'Cosituc', 'https://cosituc.pe', 0);
$insertar($c, str_repeat('cc', 16), 'Senior',   'uuid-C', 'Revendedor', null,      'https://revend.pe',  1);

/** Espejo de la consulta de Version20260816160000, apuntada a la tabla de prueba. */
$elegir = static fn (Connection $c): array => $c->fetchAllAssociative("
    SELECT t.*
    FROM zz_probe_tarifa t
    JOIN (
        SELECT cotcomponente_id, MIN(orden_id) AS elegida
        FROM (
            SELECT cotcomponente_id,
                   CONCAT(
                       CASE WHEN proveedor_maestro_id IS NULL THEN '1' ELSE '0' END,
                       CASE WHEN JSON_LENGTH(proveedor_titulo_snapshot) > 0 THEN '0' ELSE '1' END,
                       HEX(id)
                   ) AS orden_id
            FROM zz_probe_tarifa
            WHERE proveedor_maestro_id IS NOT NULL
               OR TRIM(COALESCE(proveedor_nombre_snapshot, '')) <> ''
               OR JSON_LENGTH(proveedor_titulo_snapshot) > 0
               OR proveedor_servicio_maestro_id IS NOT NULL
        ) x
        GROUP BY cotcomponente_id
    ) sel
      ON sel.cotcomponente_id = t.cotcomponente_id
     AND CONCAT(
             CASE WHEN t.proveedor_maestro_id IS NULL THEN '1' ELSE '0' END,
             CASE WHEN JSON_LENGTH(t.proveedor_titulo_snapshot) > 0 THEN '0' ELSE '1' END,
             HEX(t.id)
         ) = sel.elegida
");

$filas = $elegir($c);

echo "══ Backfill del proveedor con datos ambiguos ══\n\n";

printf("0. Escenario: 3 tarifas del mismo componente, 3 proveedores distintos\n\n");

printf("1. Una sola fila elegida\n");
printf("   filas devueltas : %d  %s\n\n", count($filas), count($filas) === 1 ? '✔' : '✘');

$g = $filas[0] ?? [];

printf("2. Preferencia: gana la que tiene maestro Y título\n");
printf("   ganadora        : %-10s %s\n", $g['nombre_interno_snapshot'] ?? '—',
    ($g['nombre_interno_snapshot'] ?? '') === 'Niño' ? '✔' : '✘ esperaba «Niño»');

printf("\n3. Nada de Frankenstein: todo del MISMO proveedor\n");
$coherente = ($g['proveedor_nombre_snapshot'] ?? '') === 'Cosituc'
    && str_contains((string) ($g['proveedor_titulo_snapshot'] ?? ''), 'Cosituc')
    && ($g['proveedor_url_snapshot'] ?? '') === 'https://cosituc.pe'
    && ($g['proveedor_maestro_id'] ?? '') === 'uuid-B'
    && str_contains((string) ($g['proveedor_imagenes_snapshot'] ?? ''), 'cosituc.pe')
    && ($g['proveedor_servicio_maestro_id'] ?? '') === 'uuid-B-serv'
    && ($g['proveedor_servicio_url_snapshot'] ?? '') === 'https://cosituc.pe/habitacion';
printf("   nombre          : %s\n", $g['proveedor_nombre_snapshot'] ?? '—');
printf("   url             : %s\n", $g['proveedor_url_snapshot'] ?? '—');
printf("   url servicio    : %s\n", $g['proveedor_servicio_url_snapshot'] ?? '—');
printf("   todo coherente  : %s  %s\n", var_export($coherente, true), $coherente ? '✔' : '✘ MEZCLÓ FUENTES');

printf("\n4. Bandera sembrada con la regla vieja (título Y no oculta)\n");
$vis = ((int) ($g['proveedor_oculto'] ?? 0) === 0 && ($g['proveedor_titulo_snapshot'] ?? '[]') !== '[]') ? 1 : 0;
printf("   proveedor_visible : %d  %s\n", $vis, $vis === 1 ? '✔' : '✘');

// La oculta con maestro pero sin título NO debe ganar; si ganara, el componente saldría
// invisible y se perdería el único título que había.
printf("   la oculta no ganó : %s  %s\n",
    var_export(($g['nombre_interno_snapshot'] ?? '') !== 'Senior', true),
    ($g['nombre_interno_snapshot'] ?? '') !== 'Senior' ? '✔' : '✘');

printf("\n5. Estable: el orden de inserción no cambia al ganador\n");
$c->executeStatement('DELETE FROM zz_probe_tarifa');
$insertar($c, str_repeat('cc', 16), 'Senior',   'uuid-C', 'Revendedor', null,      'https://revend.pe',  1);
$insertar($c, str_repeat('bb', 16), 'Niño',     'uuid-B', 'Cosituc',    'Cosituc', 'https://cosituc.pe', 0);
$insertar($c, str_repeat('aa', 16), 'Adulto',   null,     'Suelto SA',  null,      null,                 0);
$g2 = $elegir($c)[0] ?? [];
printf("   ganadora        : %-10s %s\n", $g2['nombre_interno_snapshot'] ?? '—',
    ($g2['nombre_interno_snapshot'] ?? '') === 'Niño' ? '✔' : '✘');

$c->executeStatement('DROP TABLE IF EXISTS zz_probe_tarifa');
echo "\n══ Tablas de prueba destruidas. ══\n";
