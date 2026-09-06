<?php

declare(strict_types=1);

/**
 * ¿La bandera `visibleParaCliente` del maestro persiste y se lee bien de ida y vuelta?
 *
 * Comprueba tres cosas que ningún test unitario cubre porque tocan base de datos:
 *
 *   1. El backfill dejó el estado equivalente al de antes: visible ⟺ tenía título.
 *   2. La bandera escribe y relee por el ORM (que es la vía por la que entra desde la API
 *      y desde EasyAdmin), incluido el paso por `AutoTranslationEventListener`.
 *   3. `puedeMostrarseAlCliente()` distingue los tres estados: nombrable con texto,
 *      nombrable sin texto (el hueco nuevo que la bandera hace posible) y no nombrable.
 *
 * Escribe dentro de una transacción con ROLLBACK: no deja rastro en la base.
 *
 * Uso: php tools/inspeccion/probar-proveedor-visible.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Travel\Entity\TravelOrganizacion;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

// ── 1. El backfill conservó el comportamiento anterior ──────────────────────
$conn = $em->getConnection();
$desalineados = (int) $conn->fetchOne(
    'SELECT COUNT(*) FROM travel_organizacion
     WHERE visible_para_cliente <> (JSON_LENGTH(titulo) > 0)'
);
$visibles = (int) $conn->fetchOne('SELECT COUNT(*) FROM travel_organizacion WHERE visible_para_cliente = 1');
$total    = (int) $conn->fetchOne('SELECT COUNT(*) FROM travel_organizacion');

printf("1. Backfill\n");
printf("   proveedores          : %d\n", $total);
printf("   nombrables           : %d\n", $visibles);
printf("   desalineados vs regla vieja : %d  %s\n\n", $desalineados, $desalineados === 0 ? '✔' : '✘ REVISAR');

// ── 2 y 3. Ida y vuelta por el ORM, en transacción que se revierte ──────────
$em->beginTransaction();

try {
    $p = new TravelOrganizacion();
    $p->setNombreComercial('SONDA · borrar si aparece');

    // Por defecto no es nombrable: es el opt-in que fija la columna.
    printf("2. Ida y vuelta por el ORM\n");
    printf("   recién construido, visible   : %s  %s\n",
        var_export($p->isVisibleParaCliente(), true),
        $p->isVisibleParaCliente() === false ? '✔' : '✘ deberia ser false');

    $p->setVisibleParaCliente(true);
    $p->setTitulo([['language' => 'es', 'content' => 'Hotel de prueba']]);
    $em->persist($p);
    $em->flush();

    $id = (string) $p->getId();
    $em->clear();

    $releido = $em->find(TravelOrganizacion::class, $id);
    if ($releido === null) {
        throw new RuntimeException('No se pudo releer el proveedor recién guardado.');
    }

    printf("   tras flush + clear + find    : %s  %s\n\n",
        var_export($releido->isVisibleParaCliente(), true),
        $releido->isVisibleParaCliente() === true ? '✔' : '✘ no persistió');

    printf("3. puedeMostrarseAlCliente()\n");

    printf("   nombrable + con título       : %s  %s\n",
        var_export($releido->puedeMostrarseAlCliente(), true),
        $releido->puedeMostrarseAlCliente() === true ? '✔' : '✘');

    // El hueco que la bandera hace posible y que antes no existía: marcado, sin texto.
    $releido->setTitulo([]);
    printf("   nombrable + SIN título       : %s  %s\n",
        var_export($releido->puedeMostrarseAlCliente(), true),
        $releido->puedeMostrarseAlCliente() === false ? '✔' : '✘');

    // Y el simétrico: hay texto, pero se decidió no nombrarlo. Antes era inexpresable.
    $releido->setTitulo([['language' => 'es', 'content' => 'Hotel de prueba']]);
    $releido->setVisibleParaCliente(false);
    printf("   NO nombrable + con título    : %s  %s\n",
        var_export($releido->puedeMostrarseAlCliente(), true),
        $releido->puedeMostrarseAlCliente() === false ? '✔' : '✘');
} finally {
    $em->rollback();
}

$quedan = (int) $conn->fetchOne(
    "SELECT COUNT(*) FROM travel_organizacion WHERE nombre_comercial LIKE 'SONDA%'"
);
printf("\nRastro dejado en la base: %d filas  %s\n", $quedan, $quedan === 0 ? '✔ rollback limpio' : '✘ LIMPIAR');
