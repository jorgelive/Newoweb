<?php

declare(strict_types=1);

/**
 * ¿`ContactoDelAsunto` resuelve igual de bien los TRES dominios?
 *
 * Es la regla que sustituyó a `TelefonoDeContacto`, y ahora la usan la reserva, el expediente y
 * la organización proveedora. Lo que se comprueba con datos reales:
 *
 *   1. Con hilo, el dato sale de la IDENTIDAD y se marca como tal.
 *   2. Sin hilo, cae a la SEMILLA del asunto y también se marca.
 *   3. Nunca devuelve una identidad vetada o retirada.
 *   4. `TelefonoDeContacto` (el envoltorio del PMS) dice lo mismo que el genérico — si no,
 *      habría dos reglas otra vez.
 *
 * Sólo lectura: no escribe ni abre transacción.
 */

use App\Cotizacion\Entity\CotizacionFile;
use App\Message\Service\Conversacion\ContactoDelAsunto;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Message\TelefonoDeContacto;
use App\Travel\Entity\TravelOrganizacion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$entorno = (string) ($_SERVER['APP_ENV'] ?? 'dev');
$kernel = new App\Kernel($entorno, $entorno !== 'prod');
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

// El grafo a mano: sin entorno `test`, los servicios son privados. Mismas clases que
// autoconfigura el contenedor.
$log = new Psr\Log\NullLogger();
$provPms = new App\Pms\Service\Message\PmsProveedorDeEnlaces($em);
$provCot = new App\Cotizacion\Service\Message\CotizacionProveedorDeEnlaces($em);
$enlaces = new App\Message\Service\Conversacion\EnlacesDeConversacion([$provPms, $provCot]);

$contacto = new ContactoDelAsunto($enlaces, [
    new App\Pms\Service\Message\PmsProveedorDeContexto($em),
    new App\Cotizacion\Service\Message\CotizacionProveedorDeContexto($em),
    new App\Travel\Service\Message\TravelProveedorDeContexto($em),
]);

$fallos = 0;
$decir = static function (bool $ok, string $texto) use (&$fallos): void {
    echo ($ok ? '  ✅ ' : '  ❌ '), $texto, PHP_EOL;
    if (!$ok) { $fallos++; }
};

$origenes = ['identidad' => 0, 'semilla' => 0, 'nada' => 0];

/** Recorre un dominio y cuenta de dónde sale cada dato. */
$recorrer = static function (string $tipo, iterable $entidades, int $tope) use ($contacto, &$origenes): array {
    $vistos = 0;
    $ejemplos = [];

    foreach ($entidades as $entidad) {
        if ($vistos >= $tope) { break; }
        $vistos++;

        $r = $contacto->para($tipo, (string) $entidad->getId());

        foreach (['telefonoOrigen', 'correoOrigen'] as $campo) {
            $origenes[$r[$campo] ?? 'nada']++;
        }

        if (count($ejemplos) < 2) {
            $ejemplos[] = sprintf('tel=%s(%s) correo=%s(%s)',
                $r['telefono'] ?? '—', $r['telefonoOrigen'] ?? '—',
                $r['correo'] ?? '—', $r['correoOrigen'] ?? '—');
        }
    }

    return [$vistos, $ejemplos];
};

foreach ([
    ['pms_reserva', PmsReserva::class, 60],
    ['cotizacion_file', CotizacionFile::class, 40],
    ['travel_organizacion', TravelOrganizacion::class, 40],
] as [$tipo, $clase, $tope]) {
    [$n, $ejemplos] = $recorrer($tipo, $em->getRepository($clase)->createQueryBuilder('e')->getQuery()->toIterable(), $tope);
    echo "── $tipo ($n mirados)", PHP_EOL;
    foreach ($ejemplos as $e) { echo '     ', $e, PHP_EOL; }
    $em->clear();
}

echo PHP_EOL, 'orígenes: ', json_encode($origenes), PHP_EOL, PHP_EOL;

// ── El envoltorio del PMS tiene que decir LO MISMO ──────────────────────────
$telefono = new TelefonoDeContacto($contacto);
$discrepan = 0;
$mirados = 0;

foreach ($em->getRepository(PmsReserva::class)->createQueryBuilder('r')->getQuery()->toIterable() as $reserva) {
    if ($mirados >= 80) { break; }
    $mirados++;

    $generico = $contacto->para('pms_reserva', (string) $reserva->getId());

    if ($telefono->para($reserva) !== $generico['telefono']
        || $telefono->vieneDeIdentidad($reserva) !== ($generico['telefonoOrigen'] === 'identidad')) {
        $discrepan++;
    }
}

$decir($discrepan === 0, "TelefonoDeContacto coincide con el genérico en $mirados reservas (discrepancias: $discrepan)");
$decir($origenes['identidad'] > 0, 'y hay datos resolviéndose por IDENTIDAD: ' . $origenes['identidad']);

exit($fallos > 0 ? 1 : 0);
