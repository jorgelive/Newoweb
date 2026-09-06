<?php

declare(strict_types=1);

/**
 * ¿Se puede pasar a soles un cargo que todavía vale 0.00 — y sigue bloqueado si tiene importe?
 *
 * El candado de moneda (§12.4) existe porque la moneda va atada al importe y al tipo de cambio
 * capturados al registrarla. En un cargo en `0.00` no se registró nada, así que no hay foto que
 * romper — y hace falta poder cambiarla: una estancia directa nace con una línea en cero en la
 * moneda de la cabecera, y el precio acordado a menudo se cierra en soles.
 *
 * EN TRANSACCIÓN CON ROLLBACK sobre datos reales. Comprueba, en este orden:
 *   1. Cargo en 0.00, USD → PEN: PASA.
 *   2. Importe y moneda en el MISMO guardado, que es como lo manda el panel: PASA.
 *   3. Un cargo que YA tiene importe, PEN → USD: BLOQUEADO.
 *
 * ⚠️ El caso que bloquea va el ÚLTIMO a propósito: una excepción dentro de `flush()` deja la
 * unidad de trabajo sucia, y cualquier `flush()` posterior vuelve a intentar el cambio
 * rechazado — así que lo que fuera detrás no mediría lo que dice medir.
 *
 * Uso: php tools/inspeccion/probar-moneda-cargo-en-cero.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Enum\PmsTipoCargo;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

$info = $em->getRepository(PmsInformacionFinanciera::class)->findOneBy([], ['createdAt' => 'DESC']);
$usd = $em->getRepository(MaestroMoneda::class)->find('USD');
$pen = $em->getRepository(MaestroMoneda::class)->find('PEN');

if ($info === null || $usd === null || $pen === null) {
    exit("Faltan datos con los que probar.\n");
}

printf("Cabecera: %s (%s)\n\n", (string) $info->getId(), $info->getMoneda()?->getId() ?? '—');

$em->getConnection()->beginTransaction();

/** Crea una línea en cero como la que estrena una estancia directa. */
$lineaEnCero = static function (string $nota) use ($em, $info, $usd): PmsCargoFinanciero {
    $cargo = new PmsCargoFinanciero();
    $cargo->setTipoCargo(PmsTipoCargo::LIMPIEZA);
    $cargo->setDescripcion('SONDA · ' . $nota);
    $cargo->setMonto('0.00');
    $cargo->setTotalLinea('0.00');
    $cargo->setMoneda($usd);
    $info->addCargo($cargo);
    $em->persist($cargo);
    $em->flush();

    return $cargo;
};

$paso = static function (string $titulo, callable $accion): void {
    try {
        $accion();
        printf("  ✔ %s → PASA\n", $titulo);
    } catch (Throwable $e) {
        printf("  ✘ %s → BLOQUEADO: %s…\n", $titulo, mb_substr($e->getMessage(), 0, 70));
    }
};

try {
    echo "1) Cargo en 0.00 USD, sólo la moneda:\n";
    $a = $lineaEnCero('sólo moneda');
    $paso('USD → PEN', static function () use ($a, $pen, $em): void {
        $a->setMoneda($pen);
        $em->flush();
    });

    echo "\n2) Cargo en cero: importe Y moneda en el mismo guardado (lo que manda el panel):\n";
    $b = $lineaEnCero('importe y moneda a la vez');
    $paso('0.00 USD → 350.00 PEN de una vez', static function () use ($b, $pen, $em): void {
        $b->setMoneda($pen);
        $b->setMonto('350.00');
        $b->setTotalLinea('350.00');
        $em->flush();
    });

    echo "\n3) Cargo que YA tiene importe (el candado de siempre):\n";
    $c = $lineaEnCero('con importe previo');
    $c->setMonto('120.00');
    $c->setTotalLinea('120.00');
    $em->flush();
    $paso('USD → PEN', static function () use ($c, $pen, $em): void {
        $c->setMoneda($pen);
        $em->flush();
    });
} catch (Throwable $e) {
    printf("\n💥 %s\n", $e->getMessage());
} finally {
    $em->getConnection()->rollBack();
    echo "\n↩︎  rollback: no se ha escrito nada.\n";
}
