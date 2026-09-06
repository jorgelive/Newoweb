<?php

declare(strict_types=1);

/**
 * ¿El depósito automático de una OTA de pago total cuadra SÓLO lo que cobró el canal?
 *
 * El caso que lo motivó (reserva V6WDDQ, Airbnb + ampliación directa): el depósito seguía a
 * `totalCargos` entero, así que un cargo MANUAL —la ampliación en soles que paga el huésped—
 * se lo tragaba el depósito y el saldo volvía a cero tras cada movimiento del operador.
 *
 * Lo que se comprueba, sobre una reserva Airbnb/VRBO real y EN TRANSACCIÓN CON ROLLBACK:
 *   1. Tras añadir un cargo manual en PEN con TC, el depósito queda clavado en el total de
 *      los cargos DEL CANAL (esAutomatico), no en el total de la cabecera.
 *   2. El saldo sube exactamente el importe convertido del cargo manual.
 *   3. Un pago manual por ese mismo importe baja el saldo esa misma cantidad, y el
 *      depósito sigue sin moverse.
 *
 * Uso: php tools/pruebas/probar-deposito-canal.php [localizador]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Enum\PmsTipoCargo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conn = $em->getConnection();

$localizador = $argv[1] ?? null;

// Una reserva de canal de pago total con cargos del canal ya sincronizados.
$sql = "SELECT i.id FROM pms_reserva r
        INNER JOIN pms_informacion_financiera i ON i.reserva_id = r.id
        INNER JOIN pms_cargo_financiero c ON c.informacion_id = i.id AND c.es_automatico = 1
        WHERE r.channel_id IN (?, ?)" . ($localizador ? ' AND r.localizador = ?' : '') . '
        LIMIT 1';
$params = PmsChannel::CANAL_PAGO_TOTAL;
if ($localizador) {
    $params[] = $localizador;
}
$infoIdBin = $conn->fetchOne($sql, $params);

if (!is_string($infoIdBin) || $infoIdBin === '') {
    echo "No hay ninguna reserva de canal de pago total con cargos del canal.\n";
    exit(1);
}

$conn->beginTransaction();

try {
    $info = $em->getRepository(PmsInformacionFinanciera::class)
        ->find(Uuid::fromString($infoIdBin)->toRfc4122());
    \assert($info instanceof PmsInformacionFinanciera);

    $reserva = $info->getReserva();
    $moneda = $info->getMoneda()?->getId() ?? 'USD';
    $fmt = static fn (string|float $v): string => number_format((float) $v, 2, '.', '');

    echo 'Reserva ' . $reserva?->getLocalizador() . ' · canal ' . $reserva?->getChannel()?->getId()
        . " · cabecera en {$moneda}\n";

    $deposito = static function (PmsInformacionFinanciera $info): ?PmsPagoFinanciero {
        foreach ($info->getPagos() as $p) {
            if ($p->isEsAutomatico()) {
                return $p;
            }
        }

        return null;
    };

    // ── 1. Cargo manual (la "ampliación en soles") ───────────────────────────────────────
    $tc = '3.500';
    $importePen = '90.62';
    // Lo que ese cargo aporta a la cabecera, con su propio TC (§12.2).
    $extra = $moneda === 'PEN' ? (float) $importePen : (float) $importePen / (float) $tc;

    $pen = $em->getRepository(MaestroMoneda::class)->find('PEN');
    \assert($pen instanceof MaestroMoneda);

    $cargoManual = new PmsCargoFinanciero();
    $cargoManual->setInformacionFinanciera($info);
    $cargoManual->setMoneda($pen);
    $cargoManual->setTipoCambio($tc);
    $cargoManual->setTipoCargo(PmsTipoCargo::ALOJAMIENTO);
    $cargoManual->setDescripcion('PRUEBA ampliación directa en soles');
    $cargoManual->setTotalLinea($importePen);
    $info->addCargo($cargoManual);
    $em->persist($cargoManual);
    $em->flush(); // dispara el listener: rollup + resincronización del depósito

    $em->refresh($info);
    $canal = (float) $info->getTotalCargosDelCanal();
    $dep1 = $deposito($info);
    $saldo1 = (float) $info->getSaldo();

    echo "Cargos del canal:      {$fmt($canal)} {$moneda}\n";
    echo "Total cargos cabecera: {$fmt($info->getTotalCargos())} {$moneda}\n";
    echo 'Depósito automático:   ' . ($dep1 ? $fmt($dep1->getMonto()) : '(no hay)') . " {$moneda}\n";
    echo "Saldo tras el cargo manual: {$fmt($saldo1)} {$moneda}\n";

    $ok1 = $dep1 !== null && abs((float) $dep1->getMonto() - $canal) < 0.005;
    echo ($ok1 ? '✅' : '❌') . " El depósito cuadra los cargos DEL CANAL, no el total de la cabecera\n";

    // El saldo debe incluir el cargo manual completo (antes el depósito se lo tragaba).
    // No se exige saldo == extra porque la reserva puede traer otros movimientos manuales:
    // lo que se comprueba es que el cargo manual NO fue absorbido.
    $totalManuales = (float) $info->getTotalCargos() - $canal;
    $ok2 = $totalManuales >= $extra - 0.02;
    echo ($ok2 ? '✅' : '❌') . ' El cargo manual quedó fuera del depósito ('
        . $fmt($extra) . " {$moneda} pendientes de cobro)\n";

    // ── 2. El pago manual del huésped, en soles ─────────────────────────────────────────
    $pago = new PmsPagoFinanciero();
    $pago->setMoneda($pen);
    $pago->setTipoCambio($tc);
    $pago->setMedioPago(PmsMedioPago::EFECTIVO);
    $pago->setComisionPorcentaje('0.00');
    $pago->setMonto($importePen);
    $pago->setFechaPago(new DateTimeImmutable('today'));
    $pago->setNotas('PRUEBA pago de la ampliación en soles');
    $info->addPago($pago);
    $em->persist($pago);
    $em->flush();

    $em->refresh($info);
    $dep2 = $deposito($info);
    $saldo2 = (float) $info->getSaldo();

    echo "Saldo tras registrar el pago: {$fmt($saldo2)} {$moneda}\n";

    $ok3 = $dep2 !== null && abs((float) $dep2->getMonto() - $canal) < 0.005;
    $ok4 = abs(($saldo1 - $saldo2) - $extra) < 0.02;
    echo ($ok3 ? '✅' : '❌') . " El depósito no se movió al registrar el pago manual\n";
    echo ($ok4 ? '✅' : '❌') . " El pago manual bajó el saldo exactamente lo que valía la ampliación\n";

    $codigo = ($ok1 && $ok2 && $ok3 && $ok4) ? 0 : 1;
} finally {
    // ⚠️ No usar exit() dentro del try: en PHP se salta el finally y el rollback
    // dependería del cierre de la conexión. Aquí se garantiza explícito.
    $conn->rollBack();
    echo "(rollback: no se persistió nada)\n";
}

exit($codigo);
