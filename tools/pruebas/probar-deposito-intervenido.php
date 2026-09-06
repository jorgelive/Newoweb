<?php

declare(strict_types=1);

/**
 * ¿El candado del depósito automático se puede abrir, y se cierra igual de bien?
 *
 * El depósito de las OTA de pago total es un reflejo de los cargos y el sistema lo cuadra en
 * cada recálculo (§12.4.5). Eso está bien el 99% de las veces, pero deja sin salida el 1%:
 * la OTA que deposita un importe distinto del que facturó. `intervenido` es esa salida.
 *
 * Lo que se comprueba, sobre una reserva Airbnb/VRBO real y EN TRANSACCIÓN CON ROLLBACK:
 *   1. Sin intervenir, editar el importe sigue lanzando DomainException (el candado no se
 *      ha aflojado por accidente).
 *   2. Marcando `intervenido` en el MISMO flush, la edición pasa y el importe se guarda.
 *   3. Un recálculo posterior —un cargo manual nuevo— NO le devuelve su valor automático.
 *      Éste es el corazón de la prueba: es lo que hacía inútil el campo editable.
 *   4. No nació un segundo depósito (el fallo que costó el diseño anterior, §12.4.5).
 *   5. Al devolverlo al automático, vuelve a cuadrar los cargos del canal él solo.
 *
 * Uso: php var/probar-deposito-intervenido.php [localizador]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
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

// Una reserva de canal de pago total que YA tenga su depósito automático creado.
$sql = "SELECT i.id FROM pms_reserva r
        INNER JOIN pms_informacion_financiera i ON i.reserva_id = r.id
        INNER JOIN pms_pago_financiero p ON p.informacion_id = i.id AND p.es_automatico = 1
        WHERE r.channel_id IN (?, ?)" . ($localizador ? ' AND r.localizador = ?' : '') . '
        LIMIT 1';
$params = PmsChannel::CANAL_PAGO_TOTAL;
if ($localizador) {
    $params[] = $localizador;
}
$infoIdBin = $conn->fetchOne($sql, $params);

if (!is_string($infoIdBin) || $infoIdBin === '') {
    echo "No hay ninguna reserva de canal de pago total con depósito automático.\n";
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

    /** @return list<PmsPagoFinanciero> */
    $depositos = static function (PmsInformacionFinanciera $info): array {
        $out = [];
        foreach ($info->getPagos() as $p) {
            if ($p->isEsAutomatico()) {
                $out[] = $p;
            }
        }

        return $out;
    };

    $deposito = static fn (PmsInformacionFinanciera $info): ?PmsPagoFinanciero
        => $depositos($info)[0] ?? null;

    $automatico = (float) $info->getTotalCargosDelCanal();

    echo 'Reserva ' . $reserva?->getLocalizador() . ' · canal ' . $reserva?->getChannel()?->getId()
        . " · cabecera en {$moneda}\n";
    echo "Cargos del canal (valor automático del depósito): {$fmt($automatico)} {$moneda}\n";

    // ── 1. El candado sigue cerrado mientras nadie lo abra ────────────────────────────────
    $dep = $deposito($info);
    \assert($dep instanceof PmsPagoFinanciero);

    $vetado = false;
    try {
        $dep->setMonto($fmt($automatico + 25));
        $em->flush();
    } catch (DomainException) {
        $vetado = true;
    }
    // Doctrine se queda con la entidad mutada aunque el flush fallara: hay que devolverla
    // a su estado real antes de seguir, o el siguiente flush arrastraría el importe vetado.
    $em->clear();
    $info = $em->getRepository(PmsInformacionFinanciera::class)
        ->find(Uuid::fromString($infoIdBin)->toRfc4122());
    \assert($info instanceof PmsInformacionFinanciera);

    echo ($vetado ? '✅' : '❌') . " Sin intervenir, editar el importe se rechaza\n";

    // ── 2. Con el candado abierto, la edición pasa ───────────────────────────────────────
    $aMano = $fmt($automatico + 25);
    $dep = $deposito($info);
    \assert($dep instanceof PmsPagoFinanciero);
    $dep->setIntervenido(true);
    $dep->setMonto($aMano);
    $em->flush();

    $em->refresh($info);
    $dep = $deposito($info);
    $ok2 = $dep !== null && abs((float) $dep->getMonto() - (float) $aMano) < 0.005;
    echo ($ok2 ? '✅' : '❌') . ' El importe intervenido se guardó ('
        . ($dep ? $fmt($dep->getMonto()) : '(no hay)') . " {$moneda})\n";

    // ── 3. Un recálculo posterior NO se lo pisa ──────────────────────────────────────────
    $pen = $em->getRepository(MaestroMoneda::class)->find('PEN');
    \assert($pen instanceof MaestroMoneda);

    $cargo = new PmsCargoFinanciero();
    $cargo->setInformacionFinanciera($info);
    $cargo->setMoneda($pen);
    $cargo->setTipoCambio('3.500');
    $cargo->setTipoCargo(PmsTipoCargo::OTRO);
    $cargo->setDescripcion('PRUEBA movimiento que fuerza el recálculo');
    $cargo->setTotalLinea('35.00');
    $info->addCargo($cargo);
    $em->persist($cargo);
    $em->flush();

    $em->refresh($info);
    $dep = $deposito($info);
    $ok3 = $dep !== null && abs((float) $dep->getMonto() - (float) $aMano) < 0.005;
    echo ($ok3 ? '✅' : '❌') . ' El recálculo respetó el importe intervenido ('
        . ($dep ? $fmt($dep->getMonto()) : '(no hay)') . " {$moneda})\n";

    // ── 4. Y no apareció un segundo depósito ─────────────────────────────────────────────
    $cuantos = count($depositos($info));
    $ok4 = $cuantos === 1;
    echo ($ok4 ? '✅' : '❌') . " Sigue habiendo UN solo depósito del canal (hay {$cuantos})\n";

    // ── 5. La marcha atrás lo devuelve al automático ─────────────────────────────────────
    $dep = $deposito($info);
    \assert($dep instanceof PmsPagoFinanciero);
    $dep->setIntervenido(false);
    $em->flush();

    $em->refresh($info);
    $dep = $deposito($info);
    $esperado = (float) $info->getTotalCargosDelCanal();
    $ok5 = $dep !== null && abs((float) $dep->getMonto() - $esperado) < 0.005;
    echo ($ok5 ? '✅' : '❌') . ' Al devolverlo al automático volvió a cuadrar los cargos del canal ('
        . ($dep ? $fmt($dep->getMonto()) : '(no hay)') . ' vs ' . $fmt($esperado) . " {$moneda})\n";

    $codigo = ($vetado && $ok2 && $ok3 && $ok4 && $ok5) ? 0 : 1;
} finally {
    // ⚠️ No usar exit() dentro del try: en PHP se salta el finally y el rollback
    // dependería del cierre de la conexión. Aquí se garantiza explícito.
    $conn->rollBack();
    echo "(rollback: no se persistió nada)\n";
}

exit($codigo);
