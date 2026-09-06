<?php

declare(strict_types=1);

/**
 * Borrar una Orden de Servicio: ¿vuelven los servicios al pool, y se protege lo ya emitido?
 *
 *   1. Un BORRADOR se borra, y sus servicios quedan LIBRES (`orden_servicio_id` a null) —lo hace
 *      la clave ajena con `ON DELETE SET NULL`, no el código—.
 *   2. Sus pagos y su bitácora sí caen en cascada: son de la orden, no del servicio.
 *   3. Una orden EMITIDA se niega, con el motivo y diciendo qué hacer en su lugar.
 *
 * ⚠️ Transacción con `rollback`: no borra nada.
 */

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$entorno = (string) ($_SERVER['APP_ENV'] ?? 'dev');
$kernel = new App\Kernel($entorno, $entorno !== 'prod');
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$c = $em->getConnection();

$fallos = 0;
$decir = static function (bool $ok, string $texto) use (&$fallos): void {
    echo ($ok ? '  ✅ ' : '  ❌ '), $texto, PHP_EOL;
    if (!$ok) { $fallos++; }
};

$c->beginTransaction();

try {
    // ── 1 · Un borrador con servicios ───────────────────────────────────────
    $borrador = null;
    foreach ($em->getRepository(OperacionOrdenServicio::class)->findAll() as $o) {
        if ($o->getEstadoOs() === EstadoOrdenServicioEnum::BORRADOR && count($o->getOperacionServicios()) > 0) {
            $borrador = $o;
            break;
        }
    }

    if ($borrador === null) {
        echo "⚠️  No hay ningún borrador con servicios: no se puede probar el pool.", PHP_EOL;
    } else {
        $hex = strtoupper(str_replace('-', '', (string) $borrador->getId()));
        $servicios = [];
        foreach ($borrador->getOperacionServicios() as $s) { $servicios[] = (string) $s->getId(); }

        echo 'Borrador ', $borrador->getNumeroOs(), ' con ', count($servicios), " servicio(s)", PHP_EOL;

        $pagos = (int) $c->fetchOne('SELECT COUNT(*) FROM operacion_pago WHERE orden_servicio_id = UNHEX(?)', [$hex]);

        $em->remove($borrador);
        $em->flush();

        $decir((int) $c->fetchOne('SELECT COUNT(*) FROM operacion_orden_servicio WHERE id = UNHEX(?)', [$hex]) === 0,
            'el borrador se borra');

        $libres = 0;
        foreach ($servicios as $sid) {
            $ligado = $c->fetchOne('SELECT orden_servicio_id FROM operacion_servicio WHERE id = UNHEX(?)',
                [strtoupper(str_replace('-', '', $sid))]);
            if ($ligado === null || $ligado === false) { $libres++; }
        }
        $decir($libres === count($servicios),
            "y sus servicios VUELVEN AL POOL: $libres de " . count($servicios) . ' sueltos');

        $decir((int) $c->fetchOne('SELECT COUNT(*) FROM operacion_pago WHERE orden_servicio_id = UNHEX(?)', [$hex]) === 0,
            "sus pagos caen en cascada (había $pagos)");
    }

    // ── 2 · Una orden que ya salió ──────────────────────────────────────────
    echo PHP_EOL, '── y una orden ya emitida:', PHP_EOL;

    $emitida = null;
    foreach ($em->getRepository(OperacionOrdenServicio::class)->findAll() as $o) {
        if ($o->getEstadoOs() !== EstadoOrdenServicioEnum::BORRADOR) { $emitida = $o; break; }
    }

    if ($emitida === null) {
        // No hay ninguna: se simula moviendo el estado en memoria, que es lo que mira la guarda.
        $emitida = $em->getRepository(OperacionOrdenServicio::class)->findOneBy([]);
        $emitida?->setEstadoOs(EstadoOrdenServicioEnum::EMITIDA);
        echo '  (ninguna emitida en la base: se simula el estado)', PHP_EOL;
    }

    if ($emitida !== null) {
        try {
            $em->remove($emitida);
            $em->flush();
            $decir(false, 'se borró una orden emitida — la guarda NO saltó');
        } catch (AccessDeniedHttpException $e) {
            $decir(true, 'se niega: «' . $e->getMessage() . '»');
            $decir(str_contains($e->getMessage(), 'Anúlala'), 'y dice qué hacer en su lugar');
        }
    }
} finally {
    if ($c->isTransactionActive()) { $c->rollBack(); }
    echo PHP_EOL, "↩️  rollback: no se borró nada.", PHP_EOL;
}

exit($fallos > 0 ? 1 : 0);
