<?php

declare(strict_types=1);

/**
 * ¿Ahora dice POR QUÉ no se borra una reserva?
 *
 * Reproduce el fallo del 20/08/2026 23:49 sobre datos reales: una reserva con hilo de chat.
 * Antes moría en MySQL con una violación de clave ajena, salía del `commit()` de Doctrine y
 * llegaba al panel como un 500 sin nada que leer.
 *
 *   1. Una reserva CON conversación se niega, con el nombre de quién la tiene.
 *   2. El motivo es legible: nada de `FK_D58944CED67139E8`.
 *   3. Una reserva SIN nada que lo impida no se queja (no se llega a borrar: se revierte).
 *
 * ⚠️ Todo en transacción con `rollback`. No borra nada.
 */

use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
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

$fallos = 0;
$decir = static function (bool $ok, string $texto) use (&$fallos): void {
    echo ($ok ? '  ✅ ' : '  ❌ '), $texto, PHP_EOL;
    if (!$ok) { $fallos++; }
};

$em->getConnection()->beginTransaction();

try {
    // ── La reserva que reproduce el caso ────────────────────────────────────
    //
    // ⚠️ **Doctrine cascadea a los HIJOS antes de llamar al padre** (`UnitOfWork::doRemove()`
    // llama a `cascadeRemove()` primero, para no tener que inicializar proxies después). Así
    // que si alguna estancia está bloqueada, quien habla es
    // `PmsEventoCalendarioSecurityListener` y esta guarda ni se ejecuta.
    //
    // El caso del 20/08 era justo el contrario: un BLOQUEO de calendario —estancia borrable— con
    // conversación. Hay que buscar una así o la sonda mide otra cosa.
    $conHilo = null;
    $enlace = null;

    foreach ($em->getRepository(PmsConversacionEnlace::class)->findAll() as $candidato) {
        $reserva = $candidato->getReserva();

        if ($reserva === null) {
            continue;
        }

        $todasBorrables = true;
        foreach ($reserva->getEventosCalendario() as $evento) {
            if (!$evento->isSafeToDelete()) { $todasBorrables = false; break; }
        }

        if ($todasBorrables) { $conHilo = $reserva; $enlace = $candidato; break; }
    }

    if ($conHilo === null) {
        echo "⚠️  No hay ninguna reserva con hilo y estancias borrables: todas las que tienen",
             PHP_EOL, "   conversación están bloqueadas antes por Beds24. No se puede reproducir.", PHP_EOL;
        $em->getConnection()->rollBack();
        exit(0);
    }
    echo 'Reserva con hilo: ', $conHilo?->getLocalizador(), '  ·  hilo de: ',
         $enlace->getConversacion()?->getGuestName(), PHP_EOL, PHP_EOL;

    try {
        $em->remove($conHilo);
        $em->flush();
        $decir(false, 'se borró — la guarda NO saltó');
    } catch (AccessDeniedHttpException $e) {
        $decir(true, 'se niega con motivo: «' . $e->getMessage() . '»');
        $decir(!str_contains($e->getMessage(), 'FK_'), 'y el motivo NO menciona claves ajenas de MySQL');
        $decir(str_contains($e->getMessage(), 'retírala primero'), 'y dice qué hacer para poder borrarla');
    } catch (Throwable $e) {
        $decir(false, 'saltó otra cosa: ' . $e::class . ' — ' . substr($e->getMessage(), 0, 160));
    }
} finally {
    // ⚠️ El EntityManager queda CERRADO tras una excepción en flush; el rollback es de la
    // conexión, que sigue viva, así que la transacción se deshace igual.
    if ($em->getConnection()->isTransactionActive()) {
        $em->getConnection()->rollBack();
    }
    echo PHP_EOL, "↩️  rollback: no se borró nada.", PHP_EOL;
}

exit($fallos > 0 ? 1 : 0);
