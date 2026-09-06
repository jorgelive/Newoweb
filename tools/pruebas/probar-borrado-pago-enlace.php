<?php
// ¿Se puede borrar un pago que vino de un enlace de pasarela? No debe poderse, y la reserva
// que lo tiene debe DECIR por qué antes de que nadie lo intente.
//
// No hay test unitario que cubra esto: las reglas las aplican listeners de Doctrine, así que
// hace falta base de datos. Transacción con rollback, sobre datos reales.
//
// ⚠️ ORDEN IMPORTA: un flush rechazado **cierra el EntityManager**, así que las pruebas que
// necesitan seguir usándolo van ANTES que las que provocan el rechazo. Se comprueba:
//   1. Un pago normal (a mano) sigue siendo borrable — el veto no se ha comido a los demás.
//   2. Un pago con `enlacePagoId` NO es borrable, y `getMotivoNoBorrable()` lo dice.
//   3. La RESERVA que lo contiene se declara no borrable, con su motivo — es lo que la SPA
//      lee para no ofrecer el botón, y lo que faltaba: antes daba un 500 sin texto.
//   4. Y si aun así se intenta, el rechazo llega con el motivo dentro.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();
$conn = $em->getConnection();
$conn->beginTransaction();

use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
use Symfony\Component\Uid\Uuid;

$ok = static fn (bool $cond, string $texto): string => sprintf("%s %s\n", $cond ? '✅' : '❌', $texto);

// Resuelve el manager en cada llamada, no lo captura: el punto 5 usa uno nuevo (el rechazo
// del punto 4 cierra el anterior) y un `use ($em)` lo dejaría escribiendo en el cerrado.
$nuevoPago = static function (PmsInformacionFinanciera $info, ?Uuid $enlace) use ($c): PmsPagoFinanciero {
    $em = $c->get('doctrine')->getManager();
    $pago = (new PmsPagoFinanciero())
        ->setInformacionFinanciera($info)
        ->setMoneda($info->getMoneda())
        ->setMonto('1.00')
        ->setMedioPago(PmsMedioPago::TARJETA_CREDITO)
        ->setFechaPago(new DateTimeImmutable())
        ->setEnlacePagoId($enlace);
    $em->persist($pago);
    $em->flush();

    return $pago;
};

try {
    // Una cabecera CON reserva: el punto 3 la necesita.
    $info = null;
    foreach ($em->getRepository(PmsInformacionFinanciera::class)->findBy([], null, 20) as $cand) {
        if ($cand->getReserva() !== null) {
            $info = $cand;
            break;
        }
    }

    if ($info === null) {
        exit("No hay ninguna cabecera financiera con reserva en esta base. Nada que probar.\n");
    }

    $reserva = $info->getReserva();
    printf("Cabecera %s (reserva %s)\n\n", $info->getId(), $reserva->getLocalizador() ?? '—');

    // ── 1 y 2. La regla, preguntada a la entidad ─────────────────────────────
    $manual = $nuevoPago($info, null);
    $porEnlace = $nuevoPago($info, Uuid::v7());

    echo $ok($manual->isBorrable(), 'Un pago registrado a mano SIGUE siendo borrable');
    echo $ok(!$porEnlace->isBorrable(), 'Un pago con enlacePagoId NO es borrable');
    printf("   motivo: %s\n\n", $porEnlace->getMotivoNoBorrableTexto() ?? '(ninguno)');

    // ── 3. La reserva avisa ANTES de que nadie pulse ─────────────────────────
    $motivoReserva = $reserva->getMotivoNoBorrable();
    echo $ok($motivoReserva !== null, 'La RESERVA se declara no borrable por culpa del cobro');
    echo $ok($reserva->isSafeToDelete() === false, '`safeToDelete` (lo que lee la SPA) va en false');
    printf("   motivo: %s\n\n", $motivoReserva ?? '(ninguno)');

    // ── 4. Y si se intenta igual, el rechazo trae su texto ───────────────────
    // Ojo: esto cierra el EntityManager. Nada después de aquí puede usarlo.
    $mensaje = null;
    try {
        $em->remove($reserva);
        $em->flush();
    } catch (Throwable $e) {
        $mensaje = sprintf('%s — %s', $e::class, $e->getMessage());
    }
    echo $ok(
        $mensaje !== null && !str_contains($mensaje, 'DomainException'),
        'Al intentarlo, el rechazo llega con su motivo (no un DomainException pelado → 500)',
    );
    printf("   %s\n\n", $mensaje ?? '(no lanzó nada)');

    // ── 5. La OTRA puerta: borrar el pago suelto, sin pasar por la reserva ───
    //
    // Ésa la guarda el listener de coherencia en `onFlush`, no `preRemove`. Hace falta un
    // EntityManager nuevo porque el rechazo de arriba cerró el anterior; la transacción sigue
    // siendo la misma, que es de la conexión y no del manager.
    $c->get('doctrine')->resetManager();
    $em = $c->get('doctrine')->getManager();

    $info = $em->getRepository(PmsInformacionFinanciera::class)->find($info->getId());
    $suelto = $nuevoPago($info, Uuid::v7());

    $rechazado = null;
    try {
        $em->remove($suelto);
        $em->flush();
    } catch (Throwable $e) {
        $rechazado = $e->getMessage();
    }
    echo $ok($rechazado !== null, 'Borrar el pago POR SÍ SOLO también lo rechaza la coherencia');
    printf("   %s\n", $rechazado ?? '(no lanzó nada)');
} finally {
    // Rollback siempre: esto corre contra datos reales y no debe dejar rastro.
    $conn->rollBack();
    echo "\n(rollback hecho: la base queda como estaba)\n";
}
