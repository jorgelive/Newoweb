<?php
// REVISIÓN (solo lectura + rollback): getTotalesPorMoneda en canceladas, borradores,
// y el escenario anulada-con-pagos que hoy no existe en datos. Nada se persiste.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();
$conn = $em->getConnection();
$conn->beginTransaction();

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionPago;

try {
    $ordenes = $em->getRepository(OperacionOrdenServicio::class)->findAll();
    foreach ($ordenes as $o) {
        printf("Orden %s [%s] filas=%d items=%d pagos=%d\n",
            $o->getNumeroOs() ?? '(sin numero)',
            $o->getEstadoOs()->value,
            $o->getOperacionServicios()->count(),
            $o->getItems()->count(),
            $o->getPagos()->count());
        foreach ($o->getTotalesPorMoneda() as $t) {
            printf("   %s cotizado=%s real=%s pagado=%s saldo=%s\n",
                $t['moneda'], $t['cotizado'], $t['real'], $t['pagado'], $t['saldo']);
        }
    }

    // ── Escenario sintético: una ANULADA que tuvo un adelanto pagado ──
    $anulada = null;
    foreach ($ordenes as $o) {
        if ($o->getEstadoOs()->value === 'cancelada' && $o->getItems()->count() > 0) { $anulada = $o; break; }
    }
    if ($anulada !== null) {
        $monedaItem = null;
        foreach ($anulada->getItems() as $it) { $monedaItem = $it->getMoneda(); break; }
        $pago = (new OperacionPago())
            ->setMoneda($monedaItem)
            ->setMonto('50.00')
            ->setFecha(new DateTimeImmutable('2026-08-20'));
        // Solo en memoria: se añade a la colección sin persist ni flush.
        $anulada->addPago($pago);
        echo "\n— Sintético: la misma anulada CON un pago de 50.00 (solo en memoria) —\n";
        foreach ($anulada->getTotalesPorMoneda() as $t) {
            printf("   %s cotizado=%s real=%s pagado=%s saldo=%s\n",
                $t['moneda'], $t['cotizado'], $t['real'], $t['pagado'], $t['saldo']);
        }
    }
} finally {
    $conn->rollBack();
}
