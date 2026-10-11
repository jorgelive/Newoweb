<?php

declare(strict_types=1);

/**
 * Las reglas del 10/10/2026 sobre los enlaces de una reserva, contra datos reales y con rollback:
 *
 *   1. Última hora (reservada con ≤ 2 días): el primer enlace ya es del TOTAL.
 *   2. «Cobrar el total» marcado: un solo enlace vivo, del total, y el del adelanto anulado.
 *      Quitarlo devuelve el adelanto.
 *   3. Adelanto pagado por enlace: a los 10 minutos no hay saldo; a los 31, sí, y uno solo.
 *      Y el aviso al huésped se deja en cola una sola vez.
 *   4. Un documento, un enlace vivo: dos enlaces manuales seguidos dejan uno.
 *
 *   php tools/pruebas/probar-prepago-total-y-saldo.php [LOCALIZADOR]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/KernelDePrueba.php';

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\User;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinEnlacePagoEstado;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Service\FinEnlacePagoService;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Finanzas\PmsPrepagoEnlaceService;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

if (($_SERVER['FINANZAS_ENLACES_PREPAGO'] ?? '0') !== '1' || !str_starts_with((string) ($_SERVER['CULQI_SECRET_KEY'] ?? ''), 'sk_test_')) {
    echo "Hace falta FINANZAS_ENLACES_PREPAGO=1 y llaves sk_test_ de Culqi: sin eso no se emite nada y la prueba mentiría.\n";
    exit(1);
}

$kernel = new KernelDePrueba('dev', true);
$kernel->boot();
$c = $kernel->getContainer();

/** @var EntityManagerInterface $em */
$em = $c->get('doctrine')->getManager();
$conn = $em->getConnection();
$prepago = $c->get(PmsPrepagoEnlaceService::class);
$enlaces = $c->get(FinEnlacePagoService::class);
\assert($prepago instanceof PmsPrepagoEnlaceService && $enlaces instanceof FinEnlacePagoService);
$repo = $em->getRepository(FinEnlacePago::class);

$localizador = $argv[1] ?? null;
// Una reserva que pide adelanto: canal que no cobra por nosotros, alguna estancia en pie, sin pagos.
$infoId = $conn->fetchOne(
    "SELECT i.id FROM pms_reserva r INNER JOIN pms_informacion_financiera i ON i.reserva_id = r.id
     WHERE r.channel_id NOT IN ('airbnb','vrbo') AND r.fecha_llegada > CURDATE()
       AND EXISTS (SELECT 1 FROM pms_evento_calendario ev WHERE ev.reserva_id = r.id AND COALESCE(ev.estado_id,'') NOT IN ('cancelada','bloqueo'))
       AND NOT EXISTS (SELECT 1 FROM pms_pago_financiero p WHERE p.informacion_id = i.id)"
    . ($localizador ? ' AND r.localizador = ?' : '') . ' ORDER BY r.fecha_llegada LIMIT 1',
    $localizador ? [$localizador] : [],
);

if (!is_string($infoId) || $infoId === '') {
    echo "No hay ninguna reserva candidata.\n";
    exit(1);
}

$conn->beginTransaction();
$fallos = 0;
$comprobar = static function (bool $ok, string $que, string $detalle = '') use (&$fallos): void {
    printf("%s %s%s\n", $ok ? '✅' : '❌', $que, $detalle !== '' ? "\n   " . $detalle : '');
    $fallos += $ok ? 0 : 1;
};

try {
    $info = $em->getRepository(PmsInformacionFinanciera::class)->find(Symfony\Component\Uid\Uuid::fromBinary($infoId)->toRfc4122());
    \assert($info instanceof PmsInformacionFinanciera);
    $reserva = $info->getReserva();
    $rid = $reserva?->getId();
    \assert($reserva !== null && $rid !== null);
    $usd = $em->getRepository(MaestroMoneda::class)->find('USD');
    \assert($usd instanceof MaestroMoneda);

    $vivos = static fn (): array => array_values(array_filter(
        $repo->porOrigen(FinOrigenCobro::PMS_RESERVA, $rid),
        static fn (FinEnlacePago $e): bool => $e->estaVigente(),
    ));
    $describir = static fn (array $l): string => implode(' | ', array_map(
        static fn (FinEnlacePago $e): string => sprintf('«%s» %s', $e->getConcepto(), $e->getMontoNeto()), $l));
    $moverFechas = static function (string $llegada, string $reservada) use ($conn, $em, $reserva, $rid): void {
        $conn->executeStatement('UPDATE pms_reserva SET fecha_llegada = ?, primera_fecha_reserva_canal = ? WHERE id = ?',
            [$llegada, $reservada, $rid->toBinary()], [null, null, ParameterType::BINARY]);
        $em->refresh($reserva);
    };
    $mover = static function (string $importe) use ($em, $info, $usd): void {
        $cargo = (new PmsCargoFinanciero())->setInformacionFinanciera($info)->setMoneda($usd)->setTipoCambio('3.500')
            ->setTipoCargo(PmsTipoCargo::ALOJAMIENTO)->setDescripcion('PRUEBA total y saldo')->setTotalLinea($importe);
        $info->addCargo($cargo);
        $em->persist($cargo);
        $em->flush();
        $em->refresh($info);
    };
    $hoy = new DateTimeImmutable('today');

    printf("Reserva %s · canal %s\n\n", $reserva->getLocalizador(), $reserva->getChannel()?->getId());

    // ── 1. Última hora ─────────────────────────────────────────────────────
    $moverFechas($hoy->modify('+2 days')->format('Y-m-d'), $hoy->format('Y-m-d 10:00:00'));
    $mover('200.00');
    $v = $vivos();
    $comprobar(count($v) === 1 && str_starts_with((string) $v[0]->getConcepto(), 'Pago total'),
        'Reservada con 2 días de antelación: el enlace ya es del TOTAL', $describir($v));

    // ── 2. «Cobrar el total» ───────────────────────────────────────────────
    $moverFechas($hoy->modify('+20 days')->format('Y-m-d'), $hoy->modify('-60 days')->format('Y-m-d 10:00:00'));
    $mover('0.01');
    $v = $vivos();
    $comprobar(count($v) === 1 && str_starts_with((string) $v[0]->getConcepto(), 'Adelanto'),
        'Con antelación normal vuelve a pedir el ADELANTO, y uno solo', $describir($v));
    $adelanto = $v[0] ?? null;

    $info->setCobroTotalPedido(true);
    $em->flush();
    $em->refresh($info);
    $v = $vivos();
    $comprobar(count($v) === 1 && str_starts_with((string) $v[0]->getConcepto(), 'Pago total'),
        'Marcado «cobrar el total»: un solo enlace vivo y es del total', $describir($v));
    $comprobar($adelanto !== null && $repo->find($adelanto->getId())?->getEstado() === FinEnlacePagoEstado::ANULADO,
        'Y el del adelanto quedó ANULADO');

    $info->setCobroTotalPedido(false);
    $em->flush();
    $em->refresh($info);
    $v = $vivos();
    $comprobar(count($v) === 1 && str_starts_with((string) $v[0]->getConcepto(), 'Adelanto'),
        'Quitada la marca: vuelve el adelanto, uno solo', $describir($v));
    $adelanto = $v[0] ?? null;
    \assert($adelanto instanceof FinEnlacePago);

    // ── 3. Saldo media hora después de pagar el adelanto ───────────────────
    // Como lo deja `confirmarPago()`: el enlace en PAGADO con su hora, y el pago en la reserva.
    $adelanto->setEstado(FinEnlacePagoEstado::PAGADO)->setPagadoEn(new DateTimeImmutable('-10 minutes'));
    $pago = (new PmsPagoFinanciero())->setInformacionFinanciera($info)->setMoneda($usd)->setTipoCambio('3.500')
        ->setMedioPago(PmsMedioPago::TARJETA_CREDITO)->setMonto($adelanto->getMontoNeto())
        ->setFechaPago(new DateTimeImmutable())->setNotas('PRUEBA adelanto por enlace');
    $info->addPago($pago);
    $em->persist($pago);
    $em->flush();
    $em->refresh($info);
    $comprobar($vivos() === [], 'A los 10 minutos del pago todavía no hay enlace del saldo', $describir($vivos()));

    $adelanto->setPagadoEn(new DateTimeImmutable('-31 minutes'));
    $em->flush();
    $prepago->emitirPorCambioDeCargos($info);   // lo que hace el barrido
    $v = $vivos();
    $saldo = PmsTotalesPorMonedaDeLaPrueba::saldoUsd($info);
    $comprobar(count($v) === 1 && str_starts_with((string) $v[0]->getConcepto(), 'Saldo de reserva')
        && abs((float) $v[0]->getMontoNeto() - (float) $saldo) < 0.005,
        'A los 31 minutos: un enlace, «Saldo», por lo que queda', $describir($v) . ' · saldo ' . $saldo);

    $prepago->emitirPorCambioDeCargos($info);
    $comprobar(count($vivos()) === 1, 'Repetir el barrido no emite otro');

    // ── 3b. El aviso al huésped, una sola vez ──────────────────────────────
    // Seguro dentro de la transacción: el envío va por la cola asíncrona (Messenger sobre
    // Doctrine, en esta misma base), así que el rollback se lo lleva y no sale nada hacia Meta.
    $aviso = $c->get(App\Pms\Service\Message\SaldoPendiente::class);
    \assert($aviso instanceof App\Pms\Service\Message\SaldoPendiente);
    $primero = $aviso->avisar($reserva);
    $mensaje = $em->getRepository(App\Message\Entity\Message::class)->findOneBy(
        ['asuntoId' => (string) $rid], ['createdAt' => 'DESC']);
    $comprobar($primero && $mensaje?->getTemplate()?->getCode() === 'saldo_pendiente',
        'Se deja en cola el aviso «saldo_pendiente» con el importe del enlace',
        'importe_saldo = ' . json_encode($mensaje?->getVariablesPlantilla()['importe_saldo'] ?? null));
    $comprobar(!$aviso->avisar($reserva), 'Un segundo barrido no lo repite');

    // ── 4. Un documento, un enlace vivo ────────────────────────────────────
    $operador = $em->getRepository(User::class)->findOneBy([]);
    \assert($operador instanceof User);
    $enlaces->crear(FinOrigenCobro::PMS_RESERVA, $rid, '10.00', concepto: 'PRUEBA manual 1', creadoPor: $operador, moneda: 'USD');
    $enlaces->crear(FinOrigenCobro::PMS_RESERVA, $rid, '20.00', concepto: 'PRUEBA manual 2', creadoPor: $operador, moneda: 'USD');
    $v = $vivos();
    $comprobar(count($v) === 1 && $v[0]->getConcepto() === 'PRUEBA manual 2',
        'Dos enlaces a mano seguidos: queda vivo sólo el último', $describir($v));

    $mover('5.00');
    $comprobar(count($vivos()) === 1, 'Con un manual vivo, un movimiento no le pone un automático al lado', $describir($vivos()));
} finally {
    $conn->rollBack();
    echo "\n(rollback: no se persistió nada)\n";
}

exit($fallos === 0 ? 0 : 1);

/** El saldo en USD como lo ve el emisor. */
final class PmsTotalesPorMonedaDeLaPrueba
{
    public static function saldoUsd(PmsInformacionFinanciera $info): ?string
    {
        return App\Pms\Service\Finance\PmsTotalesPorMoneda::de($info)->porMoneda['USD']['saldo'] ?? null;
    }
}
