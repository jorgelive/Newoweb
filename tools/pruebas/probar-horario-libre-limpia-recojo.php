<?php

declare(strict_types=1);

/**
 * Al pasar un componente a «horario libre», ¿se limpia la hora de recojo huérfana?
 *
 * El caso real (08/09/2026): una orden le decía al proveedor «🕐 10:00 · Almuerzo en Urubamba»
 * sobre un componente sin horario. El 10:00 era una `horaRecojo` que quedó huérfana al marcar el
 * componente, porque el input que podría haberla borrado desaparece con el flag.
 *
 * Comprueba las dos mitades: que limpia en la transición, y que NO toca nada cuando no la hay.
 *
 * Escribe de verdad y hace ROLLBACK.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Operacion\Entity\OperacionServicio;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$em->getConnection()->beginTransaction();

$fallos = 0;
$ok = static function (string $que, bool $bien) use (&$fallos): void {
    printf("· %-58s %s\n", $que, $bien ? '✅' : '❌');
    $fallos += $bien ? 0 : 1;
};

try {
    $servicio = null;

    foreach ($em->getRepository(OperacionServicio::class)->findAll() as $s) {
        if ($s->getCotizacionComponente() !== null) {
            $servicio = $s;
            break;
        }
    }

    if ($servicio === null) {
        echo "No hay ningún servicio con componente para probar.\n";
        exit(1);
    }

    $componente = $servicio->getCotizacionComponente();
    printf("Servicio: %s\n\n", $servicio->getNombreComponente());

    // ── 1. La transición limpia ─────────────────────────────────────────────
    $componente->setSinHorario(false);
    $servicio->setHoraRecojo('10:00');
    $em->flush();

    $ok('la hora queda puesta mientras el componente admite hora', $servicio->getHoraRecojo() === '10:00');

    $componente->setSinHorario(true);
    $em->flush();

    $ok('al pasar a horario libre, la hora se limpia', $servicio->getHoraRecojo() === null);

    // ── 2. Sin transición no se toca nada ───────────────────────────────────
    // Ya está en `true`: escribir una hora y guardar otra cosa no debe borrarla. El campo sigue
    // siendo del operador; lo que se limpia es el huérfano de la transición, no cualquier valor.
    $servicio->setHoraRecojo('11:30');
    $em->flush();

    // Una modificación ajena, del propio servicio: si algo se limpiara aquí sería que el
    // listener mira más de lo que dice.
    $servicio->setEstadoOperacion($servicio->getEstadoOperacion());
    $servicio->setCostoNegociado($servicio->getCostoNegociado());
    $em->flush();

    $ok('sin transición, una hora escrita después NO se toca', $servicio->getHoraRecojo() === '11:30');

    // ── 3. Y volver a admitir hora tampoco la borra ─────────────────────────
    $componente->setSinHorario(false);
    $em->flush();

    $ok('al volver a admitir hora, tampoco se borra', $servicio->getHoraRecojo() === '11:30');
} finally {
    $em->getConnection()->rollBack();
    echo "\n(rollback: no se ha guardado nada)\n";
}

exit($fallos === 0 ? 0 : 1);
