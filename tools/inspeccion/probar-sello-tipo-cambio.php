<?php

declare(strict_types=1);

/**
 * ¿Nace con tipo de cambio TODO registro financiero, venga por donde venga?
 *
 * `PmsTipoCambioSnapshotListener` sella el campo en `prePersist`. Esto lo comprueba por los
 * caminos que de verdad se usan, EN TRANSACCIÓN CON ROLLBACK:
 *
 *   1. Un cargo creado a pelo y persistido. Es el MISMO camino (`persist` + `flush`) que usan
 *      los seis creadores, incluidos los dos que no sellaban —`PmsCargosAutomaticosService` y
 *      `PmsReservaOrigenCobroResolver`—: por eso no hace falta una prueba por creador, y por eso
 *      el listener se puso en `prePersist` en vez de en cada uno.
 *   2. Un cobro con `fechaPago` de hace días: tiene que traer el cambio DE ESE DÍA, no el de hoy.
 *   3. Un registro que ya trae su propio tipo de cambio: NO se pisa.
 *
 * Uso: php tools/inspeccion/probar-sello-tipo-cambio.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Enum\PmsTipoCargo;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conn = $em->getConnection();

$info = $em->getRepository(PmsInformacionFinanciera::class)->findOneBy([], ['createdAt' => 'DESC']);
$usd = $em->getRepository(MaestroMoneda::class)->find('USD');

if ($info === null || $usd === null) {
    exit("Faltan datos con los que probar.\n");
}

// La cotización que DEBERÍA salir en cada caso, leída directamente de la tabla.
$tcDe = static function (string $dia) use ($conn): ?string {
    return $conn->fetchOne(
        'SELECT venta FROM maestro_tipocambio WHERE moneda_id = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 1',
        ['USD', $dia],
    ) ?: null;
};

$hoy = (new DateTimeImmutable('now'))->format('Y-m-d');
$haceDias = (new DateTimeImmutable('-4 days'));

printf("Cotización esperada · hoy (%s): %s   ·   %s: %s\n\n",
    $hoy, $tcDe($hoy) ?? '—', $haceDias->format('Y-m-d'), $tcDe($haceDias->format('Y-m-d')) ?? '—');

$conn->beginTransaction();

$comprobar = static function (string $titulo, ?string $obtenido, ?string $esperado): void {
    $ok = $obtenido !== null && $obtenido === $esperado;
    printf("  %s %-52s TC = %-8s (esperado %s)\n", $ok ? '✔' : '✘', $titulo, $obtenido ?? 'NULL', $esperado ?? '—');
};

try {
    // ── 1. Cargo a pelo ──────────────────────────────────────────────────────
    $c1 = (new PmsCargoFinanciero())
        ->setTipoCargo(PmsTipoCargo::OTRO)->setDescripcion('SONDA · cargo a pelo')
        ->setMonto('10.00')->setTotalLinea('10.00')->setMoneda($usd);
    $info->addCargo($c1);
    $em->persist($c1);

    // ── 3. Cargo que ya trae el suyo ─────────────────────────────────────────
    $c2 = (new PmsCargoFinanciero())
        ->setTipoCargo(PmsTipoCargo::OTRO)->setDescripcion('SONDA · cargo con TC propio')
        ->setMonto('10.00')->setTotalLinea('10.00')->setMoneda($usd)->setTipoCambio('9.999');
    $info->addCargo($c2);
    $em->persist($c2);

    // ── 2. Cobro con fecha de hace días ──────────────────────────────────────
    $p1 = (new PmsPagoFinanciero())
        ->setMonto('10.00')->setMoneda($usd)
        ->setMedioPago(PmsMedioPago::EFECTIVO)
        ->setFechaPago($haceDias);
    $info->addPago($p1);
    $em->persist($p1);

    $em->flush();

    echo "Resultados:\n";
    $comprobar('1. cargo persistido (el camino de los seis creadores)', $c1->getTipoCambio(), $tcDe($hoy));
    $comprobar('3. cargo que ya traía el suyo (no se pisa)', $c2->getTipoCambio(), '9.999');
    $comprobar('2. cobro con fechaPago de hace 4 días', $p1->getTipoCambio(), $tcDe($haceDias->format('Y-m-d')));
} catch (Throwable $e) {
    printf("\n💥 %s\n", $e->getMessage());
} finally {
    $conn->rollBack();
    echo "\n↩︎  rollback: no se ha escrito nada.\n";
}
