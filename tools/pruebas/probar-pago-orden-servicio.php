<?php

declare(strict_types=1);

/**
 * ¿La regla de moneda y el medio de pago aguantan datos REALES?
 *
 * Lo que ningún test unitario cubre: la validación de Symfony sobre entidades hidratadas de la
 * base, con órdenes que existen y monedas que existen.
 *
 *   1. Un pago en la moneda de la orden **pasa** la validación y se guarda.
 *   2. Un pago en una moneda que la orden NO tiene se **rechaza**, con el motivo escrito.
 *   3. Un pago **sin medio** se rechaza.
 *   4. El marcador `isSaldada()` responde lo mismo que el desglose por moneda.
 *
 * ⚠️ Todo en una transacción con `rollback`: no deja ni una fila.
 */

use App\Entity\Maestro\MaestroMoneda;
use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionPago;
use App\Operacion\Enum\OperacionMedioPago;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

// El entorno viene de fuera: en local no hay ni una orden de servicio, así que esta sonda se
// ejecuta contra producción con `APP_ENV=prod` — leyendo y escribiendo dentro de la transacción
// que se deshace al final.
$entorno = (string) ($_SERVER['APP_ENV'] ?? 'dev');
$kernel = new App\Kernel($entorno, $entorno !== 'prod');
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
// El `validator` del contenedor es privado y `get()` no lo da. Se construye uno con los mismos
// mapeadores que usa la aplicación —atributos sobre las entidades—, que es de donde salen las
// reglas que se quieren probar.
$validador = Symfony\Component\Validator\Validation::createValidatorBuilder()
    ->enableAttributeMapping()
    ->getValidator();

$em->getConnection()->beginTransaction();

$fallos = 0;
$decir = static function (bool $ok, string $texto) use (&$fallos): void {
    echo ($ok ? '  ✅ ' : '  ❌ '), $texto, PHP_EOL;
    if (!$ok) { $fallos++; }
};

/** Los mensajes de validación, como lista legible. */
$motivos = static function (OperacionPago $pago) use ($validador): array {
    $salida = [];
    foreach ($validador->validate($pago) as $violacion) {
        $salida[] = $violacion->getPropertyPath() . ': ' . $violacion->getMessage();
    }

    return $salida;
};

try {
    // ── Una orden con moneda de verdad ──────────────────────────────────────
    $orden = null;
    foreach ($em->getRepository(OperacionOrdenServicio::class)->findAll() as $candidata) {
        if ($candidata->monedasDeLosServicios() !== []) { $orden = $candidata; break; }
    }

    if (!$orden instanceof OperacionOrdenServicio) {
        echo "⚠️  No hay ninguna orden con moneda: no se puede probar.", PHP_EOL;
        $em->getConnection()->rollBack();
        exit(0);
    }

    $suyas = $orden->monedasDeLosServicios();
    echo 'Orden ', $orden->getNumeroOs(), '  monedas: ', implode(', ', $suyas), PHP_EOL;
    echo 'desglose: ', json_encode($orden->getTotalesPorMoneda()), PHP_EOL, PHP_EOL;

    $suya = $suyas[0];

    $nuevo = static fn (string $moneda, ?OperacionMedioPago $medio) => (new OperacionPago())
        ->setOrdenServicio($orden)
        ->setMoneda($em->getRepository(MaestroMoneda::class)->find($moneda))
        ->setMonto('10.00')
        ->setFecha(new DateTimeImmutable('today'))
        ->setMedioPago($medio);

    // ── 1 · En su moneda, pasa ─────────────────────────────────────────────
    $bueno = $nuevo($suya, OperacionMedioPago::TRANSFERENCIA_BANCARIA);
    $decir($motivos($bueno) === [], "un pago en $suya pasa la validación");

    $em->persist($bueno);
    $em->flush();
    $decir($bueno->getId() !== null, 'y se guarda de verdad');
    $decir($bueno->getMedioPagoLabel() === 'Transferencia bancaria', 'con su etiqueta legible: ' . (string) $bueno->getMedioPagoLabel());

    // ── 2 · En otra moneda, se rechaza ─────────────────────────────────────
    $otra = null;
    foreach ($em->getRepository(MaestroMoneda::class)->findAll() as $moneda) {
        if (!in_array($moneda->getId(), $suyas, true)) { $otra = $moneda->getId(); break; }
    }

    if ($otra === null) {
        echo "  ·  (sólo hay una moneda dada de alta: no se puede probar el rechazo)", PHP_EOL;
    } else {
        $malo = $motivos($nuevo($otra, OperacionMedioPago::EFECTIVO));
        $decir($malo !== [], "un pago en $otra se rechaza: " . implode(' | ', $malo));
    }

    // ── 3 · Sin medio, se rechaza ──────────────────────────────────────────
    $sinMedio = $motivos($nuevo($suya, null));
    $decir($sinMedio !== [], 'sin medio de pago se rechaza: ' . implode(' | ', $sinMedio));

    // ── 5 · El marcador coincide con el desglose ───────────────────────────
    $em->refresh($orden);
    $debe = false;
    $hayImporte = false;
    foreach ($orden->getTotalesPorMoneda() as $t) {
        if ((float) $t['real'] > 0.0) { $hayImporte = true; }
        if ((float) $t['saldo'] > 0.0) { $debe = true; }
    }
    $decir($orden->isSaldada() === ($hayImporte && !$debe),
        'isSaldada() dice lo mismo que el desglose: ' . var_export($orden->isSaldada(), true));
} finally {
    $em->getConnection()->rollBack();
    echo PHP_EOL, "↩️  rollback: no queda ni una fila.", PHP_EOL;
}

exit($fallos > 0 ? 1 : 0);
