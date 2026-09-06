<?php

declare(strict_types=1);

/**
 * ¿Qué pasa si en un expediente se teclea un teléfono que YA es de otra persona?
 *
 * Es la pregunta con la respuesta menos obvia del modelo de identidad, porque hay dos casos y
 * sólo uno es visible:
 *
 *   1. **Si el asunto NO tiene hilo todavía**, se engancha al hilo que ya tiene ese número: el
 *      expediente pasa a ser un asunto más de esa persona. Correcto cuando de verdad es la
 *      misma —el cliente que vuelve— y exactamente igual de callado cuando es un dedazo.
 *   2. **Si el asunto YA tiene hilo propio**, el identificador ajeno se **descarta**: la
 *      resolución encuentra primero su enlace titular, y `ResolutorDeHilo::vincular()` se niega
 *      a robárselo a su dueño porque `(tipo, valor)` es único.
 *
 * En los dos casos de descarte queda un aviso en el log y **nada en pantalla**.
 *
 * ⚠️ En transacción con `rollback`: no toca el expediente ni los hilos.
 */

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Message\CotizacionFileMessageContext;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
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
$enlaces = new App\Message\Service\Conversacion\EnlacesDeConversacion([$provPms, $provCot]);
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

$fallos = 0;
$decir = static function (bool $ok, string $texto) use (&$fallos): void {
    echo ($ok ? '  ✅ ' : '  ❌ '), $texto, PHP_EOL;
    if (!$ok) { $fallos++; }
};

$em->getConnection()->beginTransaction();

try {
    // ── Un teléfono que ya es de alguien ────────────────────────────────────
    $ajena = $em->getRepository(MessageIdentidad::class)->findOneBy(['tipo' => IdentidadTipo::TELEFONO]);
    $file = $em->getRepository(CotizacionFile::class)->findOneBy([]);

    if ($ajena === null || $file === null) {
        echo "⚠️  Faltan datos (identidad o expediente) para probar.", PHP_EOL;
        $em->getConnection()->rollBack();
        exit(0);
    }

    $duenio = $ajena->getConversacion();
    echo 'Teléfono ya existente: ', $ajena->getValor(), '  → hilo de: ', $duenio?->getGuestName(), PHP_EOL;
    echo 'Se le teclea al expediente: ', $file->getLocalizador() ?? (string) $file->getId(), PHP_EOL, PHP_EOL;

    $hilosAntes = (int) $em->createQuery('SELECT COUNT(c.id) FROM App\Message\Entity\MessageConversation c')->getSingleScalarResult();
    $teniaHilo = $enlaces->hiloTitularDe('cotizacion_file', (string) $file->getId()) !== null;
    echo '  el expediente ', $teniaHilo ? 'YA tiene hilo propio' : 'todavía no tiene hilo', PHP_EOL, PHP_EOL;

    // Lo que hace el operador: escribe el número en la semilla y guarda.
    $file->setTelefono($ajena->getValor());
    $file->setEmail('nuevo-correo-inventado@ejemplo.com');

    $hilo = $factoria->upsertFromContext(new CotizacionFileMessageContext($file), flush: true);

    $hilosDespues = (int) $em->createQuery('SELECT COUNT(c.id) FROM App\Message\Entity\MessageConversation c')->getSingleScalarResult();

    // ⚠️ El resultado depende de si el asunto YA tenía hilo, y las dos ramas son correctas.
    // Medirlo con una sola aserción daba un falso fallo en producción, donde este expediente sí
    // lo tiene.
    $seEngancho = $hilo->getId()?->equals($duenio?->getId()) === true;

    if ($teniaHilo) {
        $decir(!$seEngancho, 'el asunto YA tenía hilo: se queda en el suyo, no se muda');
        $suyos = [];
        foreach ($hilo->getIdentidades() as $i) {
            if ($i->getTipo() === IdentidadTipo::TELEFONO) { $suyos[] = $i->getValor(); }
        }
        $decir(!in_array($ajena->getValor(), $suyos, true),
            '⚠️ y el teléfono ajeno se DESCARTA, sin decir nada: ' . implode(', ', $suyos ?: ['—']));
    } else {
        $decir($seEngancho, 'el asunto NO tenía hilo: se engancha al de ESA persona');
    }

    $decir($hilosDespues === $hilosAntes, "y no nace ningún hilo ($hilosAntes → $hilosDespues)");

    $asuntos = [];
    foreach ($enlaces->de($hilo) as $a) { $asuntos[] = $a->getContextType(); }
    $decir(in_array('cotizacion_file', $asuntos, true),
        'el expediente queda como ASUNTO de ese hilo: ' . implode(', ', $asuntos));

    // ── El correo: ¿se registró? ────────────────────────────────────────────
    $correos = [];
    foreach ($hilo->getIdentidades() as $i) {
        if ($i->getTipo() === IdentidadTipo::EMAIL) { $correos[] = $i->getValor(); }
    }
    $decir(in_array('nuevo-correo-inventado@ejemplo.com', $correos, true),
        'y el correo nuevo SÍ se registra cuando no es de nadie: ' . implode(', ', $correos ?: ['—']));
    // ── El caso mudo: un correo que YA es de OTRA persona ───────────────────
    echo PHP_EOL, '── y si el correo es de otra persona distinta:', PHP_EOL;

    $correoAjeno = null;
    foreach ($em->getRepository(MessageIdentidad::class)->findBy(['tipo' => IdentidadTipo::EMAIL]) as $i) {
        if ($i->getConversacion()?->getId()?->equals($hilo->getId()) !== true) { $correoAjeno = $i; break; }
    }

    if ($correoAjeno === null) {
        echo '  ·  (no hay ningún correo de otro hilo con el que probar)', PHP_EOL;
    } else {
        echo '  correo ajeno: ', $correoAjeno->getValor(), '  → de: ',
             $correoAjeno->getConversacion()?->getGuestName(), PHP_EOL;

        $file->setEmail($correoAjeno->getValor());
        $factoria->upsertFromContext(new CotizacionFileMessageContext($file), flush: true);

        $suyos = [];
        foreach ($hilo->getIdentidades() as $i) {
            if ($i->getTipo() === IdentidadTipo::EMAIL) { $suyos[] = $i->getValor(); }
        }

        $decir(!in_array($correoAjeno->getValor(), $suyos, true),
            'NO se le roba a su dueño — el correo se descarta');
        $decir($correoAjeno->getConversacion()?->getId()?->equals($hilo->getId()) !== true,
            'y sigue siendo de quien era');
        echo '  ⚠️  y el operador no ve NADA: sólo queda un warning en el log.', PHP_EOL;
    }

} finally {
    if ($em->getConnection()->isTransactionActive()) { $em->getConnection()->rollBack(); }
    echo PHP_EOL, "↩️  rollback: nada tocado.", PHP_EOL;
}

exit($fallos > 0 ? 1 : 0);
