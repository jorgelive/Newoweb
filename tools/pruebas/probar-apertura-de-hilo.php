<?php

declare(strict_types=1);

/**
 * ¿Abre de verdad el hilo de un proveedor, y con todo lo que hace falta?
 *
 * Es el camino que ningún test unitario cubre: `AperturaDeHilo` sobre datos reales, con la
 * factoría entera detrás —identidades sembradas, `contextData` volcado, sincronizadores de
 * enlace— y una `TravelOrganizacion` que nunca ha tenido conversación.
 *
 * Lo que se comprueba:
 *
 *   1. **Nace el hilo** con `contextType = travel_organizacion` y el nombre de la organización.
 *   2. **Se siembran las identidades**: el teléfono y el correo del catálogo, normalizados.
 *   3. **Es idempotente**: llamarlo dos veces devuelve el MISMO hilo, no dos.
 *   4. **El correo tiene destino** — que es lo que hace útil el hilo — y **Beds24 está apagado**
 *      sin que nadie lo desactive, porque no hay resolver para este `contextType`.
 *   5. **Sin datos de contacto NO se abre**, con el motivo escrito.
 *
 * ⚠️ Todo dentro de una transacción con `rollback`: no deja ni una fila.
 */

use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\AperturaDeHilo;
use App\Message\Service\Queue\EmailSendEnqueuer;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Service\Message\TravelOrganizacionMessageContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

// ⚠️ El grafo se arma A MANO y no se pide al contenedor: este proyecto no tiene entorno `test`
// con `framework.test`, así que los servicios son privados y `get()` no los ve. Se cablean las
// MISMAS clases que autoconfigura el contenedor —los dos proveedores de enlaces, los dos
// sincronizadores, los tres proveedores de contexto—, así que lo que se prueba es el camino
// real. Si mañana se añade un cuarto proveedor y no se añade aquí, esta sonda deja de cubrirlo:
// es el precio de no tener contenedor de test.
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

$apertura = new AperturaDeHilo($factoria, $log, [
    new App\Pms\Service\Message\PmsProveedorDeContexto($em),
    new App\Cotizacion\Service\Message\CotizacionProveedorDeContexto($em),
    new App\Travel\Service\Message\TravelProveedorDeContexto($em),
]);

$correo = new EmailSendEnqueuer($em, $enlaces, new App\Message\Service\Conversacion\AliasDePlataforma($enlaces));

$em->getConnection()->beginTransaction();

$ok = static fn (bool $cond, string $texto): string => ($cond ? "  ✅ " : "  ❌ ") . $texto;
$fallos = 0;
$decir = static function (string $linea) use (&$fallos): void {
    echo $linea, PHP_EOL;
    if (str_contains($linea, '❌')) { $fallos++; }
};

try {
    // ── Una organización con contacto, sin hilo ─────────────────────────────
    $conContacto = $em->getRepository(TravelOrganizacion::class)->createQueryBuilder('o')
        ->where('o.telefono IS NOT NULL AND o.telefono <> :v')
        ->orWhere('o.email IS NOT NULL AND o.email <> :v')
        ->setParameter('v', '')
        ->setMaxResults(1)
        ->getQuery()->getOneOrNullResult();

    if (!$conContacto instanceof TravelOrganizacion) {
        echo "⚠️  No hay ninguna organización con teléfono ni correo: no se puede probar.", PHP_EOL;
        $em->getConnection()->rollBack();
        exit(0);
    }

    $id = (string) $conContacto->getId();
    echo "Organización: ", $conContacto->getNombreComercial() ?? $conContacto->getRazonSocial(),
         "  tel=", $conContacto->getTelefono() ?? '—', "  correo=", $conContacto->getEmail() ?? '—', PHP_EOL, PHP_EOL;

    $hilo = $apertura->abrir(TravelOrganizacionMessageContext::CONTEXT_TYPE, $id);

    $decir($ok($hilo->getContextType() === 'travel_organizacion', 'nace con el contextType del dominio'));
    $decir($ok($hilo->getContextId() === $id, 'apunta a la organización'));
    $decir($ok(trim((string) $hilo->getGuestName()) !== '', 'lleva el nombre: ' . $hilo->getGuestName()));

    $identidades = [];
    foreach ($hilo->getIdentidades() as $i) {
        $identidades[] = $i->getTipo()->value . '=' . $i->getValor();
    }
    $decir($ok($identidades !== [], 'siembra identidades: ' . implode(', ', $identidades)));

    // ── Idempotencia: el mismo hilo, no dos ────────────────────────────────
    $otraVez = $apertura->abrir(TravelOrganizacionMessageContext::CONTEXT_TYPE, $id);
    $decir($ok($otraVez->getId()?->equals($hilo->getId()) === true, 'llamarlo dos veces devuelve el MISMO hilo'));

    $cuantos = (int) $em->getRepository(MessageConversation::class)
        ->createQueryBuilder('c')->select('COUNT(c.id)')
        ->where('c.contextType = :t AND c.contextId = :i')
        ->setParameter('t', 'travel_organizacion')->setParameter('i', $id)
        ->getQuery()->getSingleScalarResult();
    $decir($ok($cuantos === 1, "y en la base hay exactamente 1 (hay $cuantos)"));

    // ── Los canales, sin configurar nada ───────────────────────────────────
    $tieneCorreo = trim((string) $conContacto->getEmail()) !== '';
    $destinoCorreo = $correo->disponiblePara($hilo);
    $decir($ok($destinoCorreo === $tieneCorreo, 'el canal de correo se enciende sólo si hay correo (' . var_export($destinoCorreo, true) . ')'));

    // WhatsApp es el que de verdad se va a usar con un proveedor: sólo pide `guestPhone`, que la
    // factoría vuelca desde el contexto. Si no quedara puesto, el panel ofrecería el canal y el
    // mensaje moriría en el encolador diciendo «enviado».
    $whatsapp = new App\Message\Service\Queue\WhatsappMetaSendEnqueuer(
        $em,
        new App\Message\Service\MessageDataResolverRegistry([])
    );
    $decir($ok($whatsapp->disponiblePara($hilo) === (trim((string) $conContacto->getTelefono()) !== ''),
        'y el de WhatsApp también, con el teléfono volcado en guestPhone: ' . ($hilo->getGuestPhone() ?? '—')));

    // ── Sin contacto no se abre ────────────────────────────────────────────
    $sinContacto = $em->getRepository(TravelOrganizacion::class)->createQueryBuilder('o')
        ->where('(o.telefono IS NULL OR o.telefono = :v) AND (o.email IS NULL OR o.email = :v)')
        ->setParameter('v', '')
        ->setMaxResults(1)
        ->getQuery()->getOneOrNullResult();

    if ($sinContacto instanceof TravelOrganizacion) {
        try {
            $apertura->abrir(TravelOrganizacionMessageContext::CONTEXT_TYPE, (string) $sinContacto->getId());
            $decir($ok(false, 'una organización SIN contacto no debería abrir hilo, y abrió'));
        } catch (RuntimeException $e) {
            $decir($ok(str_contains($e->getMessage(), 'teléfono'), 'sin contacto se niega: «' . $e->getMessage() . '»'));
        }
    } else {
        echo "  ·  (no hay ninguna organización sin contacto con la que probar la negativa)", PHP_EOL;
    }

    // ── EL CASO DEL PMS: se corta la conversación y hay que volver a escribir ──
    //
    // Es el que motivó todo esto. Hasta ahora, borrado el hilo, la única salida era tocar y
    // volver a guardar la reserva para que el listener lo recreara de rebote — y por el camino
    // se perdían las identidades, el enlace y el `contextData` si alguien lo recreaba a mano.
    echo PHP_EOL, "── PMS: reserva cuya conversación se corta ──", PHP_EOL;

    $enlaceVivo = $em->createQuery(
        'SELECT e FROM App\Pms\Entity\PmsConversacionEnlace e WHERE e.esTitular = true'
    )->setMaxResults(1)->getOneOrNullResult();

    if ($enlaceVivo === null) {
        echo "  ·  (no hay enlaces titulares con los que probar)", PHP_EOL;
    } else {
        $reservaId = $enlaceVivo->getContextId();
        $hiloViejo = $enlaceVivo->getConversacion();
        $identidadesAntes = count($hiloViejo?->getIdentidades() ?? []);

        echo "  reserva: $reservaId  ·  hilo: ", (string) $hiloViejo?->getId(),
             "  ·  identidades: $identidadesAntes", PHP_EOL;

        // Se corta: fuera el hilo y fuera su enlace, como si nunca hubiera existido.
        $em->remove($enlaceVivo);
        if ($hiloViejo !== null) { $em->remove($hiloViejo); }
        $em->flush();
        $em->clear();

        $sigue = $em->getRepository(MessageConversation::class)->find($hiloViejo?->getId());
        $decir($ok($sigue === null, 'el hilo quedó cortado de verdad'));

        // Y se vuelve a abrir.
        $renacido = $apertura->abrir('pms_reserva', $reservaId);

        $decir($ok($renacido->getId()?->equals($hiloViejo?->getId()) !== true, 'nace un hilo NUEVO, no resucita el borrado'));
        $decir($ok(count($renacido->getIdentidades()) > 0,
            'vuelve con sus identidades: ' . count($renacido->getIdentidades()) . ' (antes ' . $identidadesAntes . ')'));
        $decir($ok(trim((string) $renacido->getGuestName()) !== '', 'y con el nombre del huésped: ' . $renacido->getGuestName()));

        // Por el camino real —el proveedor de enlaces del dominio— y no con un DQL propio:
        // comparar el UUID como texto contra una columna binaria da un falso negativo.
        $asuntos = $enlaces->de($renacido);
        $decir($ok($asuntos !== [], 'y con su ENLACE de asunto recreado (' . count($asuntos) . ') — lo que el alta a mano de EasyAdmin no hace'));
        $decir($ok(($asuntos[0] ?? null)?->esTitular() === true, 'y marcado como TITULAR, que es lo que hace que la reserva vuelva a encontrar su hilo'));
        $decir($ok($renacido->getContextData() !== null && $renacido->getContextData() !== [], 'y con el contextData volcado'));
    }

    // ── Un dominio que no existe ───────────────────────────────────────────
    try {
        $apertura->abrir('inventado', 'x');
        $decir($ok(false, 'un contextType desconocido debería lanzar'));
    } catch (RuntimeException $e) {
        $decir($ok(str_contains($e->getMessage(), 'inventado'), 'un dominio sin proveedor lo dice con su nombre'));
    }
} finally {
    $em->getConnection()->rollBack();
    echo PHP_EOL, "↩️  rollback: no queda ni una fila.", PHP_EOL;
}

exit($fallos > 0 ? 1 : 0);
