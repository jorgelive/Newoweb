<?php

declare(strict_types=1);

/**
 * Un expediente ANTIGUO, con teléfono y sin identidad: ¿se promueve al abrirle el hilo?
 *
 * Es la pregunta de «¿y los que ya están creados?». Antes de este cambio su teléfono vivía sólo
 * en el expediente; ahora el panel lo enseña marcado **sin verificar** y el botón «Editar» abre
 * el hilo, que es lo que convierte la semilla en identidad.
 *
 *   1. De partida, el contacto sale de la SEMILLA.
 *   2. Al abrir el hilo, nacen las identidades con ese mismo valor.
 *   3. Y a partir de ahí el mismo resolutor dice IDENTIDAD, sin que el expediente cambie.
 *
 * ⚠️ En transacción con `rollback`: no deja hilo ni identidad.
 */

use App\Cotizacion\Entity\CotizacionFile;
use App\Message\Service\Conversacion\AperturaDeHilo;
use App\Message\Service\Conversacion\ContactoDelAsunto;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$entorno = (string) ($_SERVER['APP_ENV'] ?? 'dev');
$kernel = new App\Kernel($entorno, $entorno !== 'prod');
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

$log = new Psr\Log\NullLogger();
$provPms = new App\Pms\Service\Message\PmsProveedorDeEnlaces($em);
$provCot = new App\Cotizacion\Service\Message\CotizacionProveedorDeEnlaces($em);
$enlaces = new EnlacesDeConversacion([$provPms, $provCot]);

$contextos = [
    new App\Pms\Service\Message\PmsProveedorDeContexto($em),
    new App\Cotizacion\Service\Message\CotizacionProveedorDeContexto($em),
    new App\Travel\Service\Message\TravelProveedorDeContexto($em),
];

$contacto = new ContactoDelAsunto($enlaces, $contextos);

$factoria = new App\Message\Factory\MessageConversationFactory(
    $em,
    new App\Message\Service\Conversacion\ResolutorDeHilo($em, $log),
    $enlaces,
    $log,
    [
        new App\Pms\Service\Message\PmsSincronizadorDeEnlace($em, new App\Pms\Service\Message\PmsHitosDeEstancia(), $provPms),
        new App\Cotizacion\Service\Message\CotizacionSincronizadorDeEnlace($em, $provCot),
    ]
);
$apertura = new AperturaDeHilo($factoria, $log, $contextos);

$fallos = 0;
$decir = static function (bool $ok, string $texto) use (&$fallos): void {
    echo ($ok ? '  ✅ ' : '  ❌ '), $texto, PHP_EOL;
    if (!$ok) { $fallos++; }
};

$em->getConnection()->beginTransaction();

try {
    // ── Un expediente con contacto y SIN hilo ───────────────────────────────
    $elegido = null;
    foreach ($em->getRepository(CotizacionFile::class)->findAll() as $file) {
        $r = $contacto->para('cotizacion_file', (string) $file->getId());

        if (($r['telefonoOrigen'] ?? null) === 'semilla' || ($r['correoOrigen'] ?? null) === 'semilla') {
            $elegido = $file;
            break;
        }
    }

    if ($elegido === null) {
        echo "⚠️  No hay ningún expediente en estado «semilla»: todos tienen identidad ya.", PHP_EOL;
        $em->getConnection()->rollBack();
        exit(0);
    }

    $id = (string) $elegido->getId();
    $antes = $contacto->para('cotizacion_file', $id);

    echo 'Expediente ', $elegido->getLocalizador() ?? $id, PHP_EOL;
    echo '  antes: ', json_encode($antes, JSON_UNESCAPED_UNICODE), PHP_EOL, PHP_EOL;

    $decir(($antes['telefono'] ?? $antes['correo']) !== null, 'de partida hay un dato de contacto');
    $decir(in_array('semilla', [$antes['telefonoOrigen'], $antes['correoOrigen']], true), 'y sale de la SEMILLA');

    // ── Lo que hace el botón «Editar» ───────────────────────────────────────
    $hilo = $apertura->abrir('cotizacion_file', $id);
    $decir($hilo->getId() !== null, 'el botón «Editar» abre el hilo');

    $identidades = [];
    foreach ($hilo->getIdentidades() as $i) { $identidades[] = $i->getTipo()->value . '=' . $i->getValor(); }
    $decir($identidades !== [], 'y nacen las identidades: ' . implode(', ', $identidades));

    // ── Y el mismo resolutor cambia de respuesta ───────────────────────────
    $em->clear();
    $despues = $contacto->para('cotizacion_file', $id);
    echo PHP_EOL, '  después: ', json_encode($despues, JSON_UNESCAPED_UNICODE), PHP_EOL;

    $promovido = ($antes['telefonoOrigen'] === 'semilla' && $despues['telefonoOrigen'] === 'identidad')
        || ($antes['correoOrigen'] === 'semilla' && $despues['correoOrigen'] === 'identidad');
    $decir($promovido, 'y a partir de ahí el dato sale de la IDENTIDAD');
    $decir($despues['conversacionId'] !== null, 'con hilo al que llevar el editor de identificadores');
} finally {
    if ($em->getConnection()->isTransactionActive()) { $em->getConnection()->rollBack(); }
    echo PHP_EOL, "↩️  rollback: no queda hilo ni identidad.", PHP_EOL;
}

exit($fallos > 0 ? 1 : 0);
