<?php

declare(strict_types=1);

/**
 * ¿El enlace automático pide el ADELANTO antes de la llegada y el SALDO desde ese día?
 *
 * La regla —`PmsPrepagoCalculador::queSePide()`, 28/08/2026— gobernaba sólo el MENSAJE que se
 * le redacta al huésped. El emisor de enlaces preguntaba por `pendiente()`, que no mira
 * fechas, así que pasado el día de llegada el texto decía «paga el total» y el enlace que lo
 * acompañaba se titulaba «Adelanto de reserva» por una fracción. Ver docs/FinanzasEnlacesPago.md.
 *
 * Lo que se comprueba, sobre UNA reserva real y EN TRANSACCIÓN CON ROLLBACK:
 *   1. Con la llegada en el futuro → «Adelanto de reserva …» por una fracción del saldo.
 *   2. Movida la llegada a HOY → «Saldo de reserva …» por el saldo entero, y el adelanto
 *      anterior queda ANULADO: nunca dos pagables a la vez.
 *   3. Con un enlace MANUAL del operador por el saldo exacto: el emisor lo reutiliza —no emite
 *      otro— **y retira igualmente el adelanto viejo**. Sin eso salía por la puerta del
 *      «ya hay uno por ese importe» dejando dos enlaces vivos por cantidades distintas, que es
 *      exactamente el caso que hay hoy en producción (3GFMC7).
 *   4. Con un PAGO parcial y la reserva ya llegada, se emite por el saldo restante en vez de
 *      no emitir nada (decisión del 06/09/2026). Antes de la llegada, ese mismo pago sigue
 *      cerrando la puerta.
 *   5. Si el «adelanto» ya ES el saldo entero —estancia de UNA noche con `primera_noche_total`—
 *      el enlace se llama «Saldo» desde el primer día, sin esperar al de llegada.
 *   6. El enlace que emitió un OPERADOR a mano sobrevive a las emisiones — el bug del
 *      05/09/2026, que anulaba cualquier enlace vivo y no sólo los automáticos. Sólo se puede
 *      comprobar si la reserva elegida ya tenía uno; el caso montado a propósito es el 8 de
 *      `var/probar-prepago-automatico.php`.
 *
 * ⚠️ **La fecha se mueve por SQL, no por el ORM**, y a propósito: tocar `PmsReserva` dispara
 * los listeners de sincronización, y esta prueba no tiene por qué hablar con Beds24. Va dentro
 * de la misma transacción, así que el rollback la deshace igual.
 *
 * La previsualización del agente (`emitirSimulado()`) no se comprueba aquí porque el servicio
 * no es público en el contenedor; comparte el mismo `loQueSePide()` por construcción, que es
 * la garantía de que preview y emisión no se separen.
 *
 * ⚠️ **Hay que correrla con `FINANZAS_ENLACES_PREPAGO=1`.** En local el interruptor está en 0,
 * y con él apagado `emitirPorCambioDeCargos()` no hace NADA: la prueba encontraba el enlace
 * que la reserva ya tenía de antes y lo daba por recién emitido. Un verde por dato viejo es
 * peor que un rojo, así que ahora se comprueba el interruptor y se aborta.
 *
 * Uso: FINANZAS_ENLACES_PREPAGO=1 php var/probar-prepago-dia-de-llegada.php [LOCALIZADOR]
 *      (por defecto, la reserva más próxima con llegada futura, canal que no cobra por
 *       nosotros y sin ningún pago: las tres condiciones para que haya adelanto que pedir)
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\User;
use App\Finanzas\Service\FinEnlacePagoService;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
/** @var FinEnlacePagoRepository $repo */
$repo = $em->getRepository(FinEnlacePago::class);
$conn = $em->getConnection();

// ⚠️ Segunda trampa, hermana de la del flag: con llaves `sk_live_` fuera de producción
// `CulqiClient` se declara NO configurado a propósito —desde una base copiada de la real,
// «Devolver» reembolsaría un cargo de verdad— y entonces `crear()` no encuentra pasarela y
// no se emite nada. El síntoma sería un ❌ mudo culpando al código.
$claveCulqi = (string) ($_SERVER['CULQI_SECRET_KEY'] ?? '');

if ($claveCulqi !== '' && !str_starts_with($claveCulqi, 'sk_test_')) {
    echo "Culqi tiene llaves REALES en dev: el cliente se niega a operar y no se emitirá nada.\n";
    echo "Pon las de prueba (sk_test_/pk_test_) en .env.local. Ver CulqiClient::peticion().\n";
    exit(1);
}

if (($_SERVER['FINANZAS_ENLACES_PREPAGO'] ?? '0') !== '1') {
    echo "El emisor automático está APAGADO (FINANZAS_ENLACES_PREPAGO=0).\n";
    echo "Sin él no se emite nada y esta prueba mediría datos viejos. Relánzala así:\n";
    echo "  FINANZAS_ENLACES_PREPAGO=1 php var/probar-prepago-dia-de-llegada.php\n";
    exit(1);
}

$localizador = $argv[1] ?? null;

$sql = 'SELECT i.id FROM pms_reserva r
        INNER JOIN pms_informacion_financiera i ON i.reserva_id = r.id
        WHERE i.activa = 1
          AND r.channel_id NOT IN (\'airbnb\', \'vrbo\')
          AND r.fecha_llegada > CURDATE()
          AND NOT EXISTS (SELECT 1 FROM pms_pago_financiero p WHERE p.informacion_id = i.id)'
    . ($localizador ? ' AND r.localizador = ?' : '')
    . ' ORDER BY r.fecha_llegada LIMIT 1';

$infoIdBin = $conn->fetchOne($sql, $localizador ? [$localizador] : []);

if (!is_string($infoIdBin) || $infoIdBin === '') {
    echo "No hay ninguna reserva futura, sin pagos y de canal que no cobre por nosotros.\n";
    exit(1);
}

$conn->beginTransaction();
$codigo = 0;

try {
    $info = $em->getRepository(PmsInformacionFinanciera::class)
        ->find(Uuid::fromString($infoIdBin)->toRfc4122());
    \assert($info instanceof PmsInformacionFinanciera);

    $reserva = $info->getReserva();
    \assert($reserva instanceof PmsReserva);
    $reservaId = $reserva->getId();
    \assert($reservaId !== null);

    $vivos = static fn (): array => array_values(array_filter(
        $repo->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId),
        static fn (FinEnlacePago $e): bool => $e->estaVigente(),
    ));

    $automaticos = static fn (): array => array_values(array_filter(
        $vivos(),
        static fn (FinEnlacePago $e): bool => $e->getCreadoPorNombre() === null,
    ));

    $manuales = static fn (): int => count(array_filter(
        $vivos(),
        static fn (FinEnlacePago $e): bool => $e->getCreadoPorNombre() !== null,
    ));

    $saldoDe = static function (string $moneda) use ($info): ?string {
        return PmsTotalesPorMoneda::de($info)->porMoneda[$moneda]['saldo'] ?? null;
    };

    $usd = $em->getRepository(MaestroMoneda::class)->find('USD');
    \assert($usd instanceof MaestroMoneda);

    $añadirCargo = static function (string $importe) use ($em, $info, $usd): void {
        $c = new PmsCargoFinanciero();
        $c->setInformacionFinanciera($info);
        $c->setMoneda($usd);
        $c->setTipoCambio('3.500');
        $c->setTipoCargo(PmsTipoCargo::ALOJAMIENTO);
        $c->setDescripcion('PRUEBA día de llegada');
        $c->setTotalLinea($importe);
        $info->addCargo($c);
        $em->persist($c);
        $em->flush();
        $em->refresh($info);
    };

    printf(
        "Reserva %s · canal %s · llegada %s\n",
        $reserva->getLocalizador(),
        $reserva->getChannel()?->getId(),
        $reserva->getFechaLlegada()?->format('Y-m-d') ?? '—',
    );
    printf("Vivos al empezar: %d automático(s), %d del operador\n\n", count($automaticos()), $manuales());

    $manualesAntes = $manuales();

    // ── 1. Llegada en el FUTURO → adelanto ──────────────────────────────────
    $añadirCargo('200.00');
    $trasAdelanto = $automaticos();

    $ok1 = count($trasAdelanto) === 1
        && str_starts_with((string) $trasAdelanto[0]->getConcepto(), 'Adelanto de reserva');
    printf("%s Antes de la llegada emite ADELANTO\n", $ok1 ? '✅' : '❌');

    if (count($trasAdelanto) === 1) {
        $e = $trasAdelanto[0];
        $saldo = $saldoDe((string) $e->getMonedaCodigo());
        $esFraccion = $saldo !== null && (float) $e->getMontoNeto() < (float) $saldo;
        printf(
            "   «%s» · %s %s de un saldo de %s  %s\n",
            $e->getConcepto(), $e->getMonedaCodigo(), $e->getMontoNeto(), $saldo ?? '—',
            $esFraccion ? '✅ es una fracción' : '❌ no es una fracción',
        );
        $ok1 = $ok1 && $esFraccion;
        $idAdelanto = $e->getId();
    }

    $codigo = $ok1 ? $codigo : 1;

    // ── 2. La llegada pasa a ser HOY → saldo entero ─────────────────────────
    // Por SQL: mover la fecha con el ORM despertaría a los listeners de sincronización.
    // ⚠️ El id va con tipo BINARY explícito. Sin él, Doctrine manda la forma canónica del UUID
    // contra una columna BINARY(16) y el UPDATE afecta a CERO filas **sin dar error** — la
    // misma trampa que documenta §2 de docs/FinanzasEnlacesPago.md para `porOrigen()`. Se
    // comprueba el número de filas para que un fallo así no se lea como «la regla no funciona».
    $afectadas = $conn->executeStatement(
        'UPDATE pms_reserva SET fecha_llegada = CURDATE() WHERE id = ?',
        [$reservaId->toBinary()],
        [\Doctrine\DBAL\ParameterType::BINARY],
    );

    if ($afectadas !== 1) {
        printf("\n❌ No se pudo mover la fecha de llegada (%d filas): la prueba no vale.\n", $afectadas);
        $conn->rollBack();
        exit(1);
    }
    $em->refresh($reserva);
    $em->refresh($info);

    // Cualquier movimiento vuelve a pasar por el mismo `postFlush`: es lo que ocurre de verdad
    // cuando entra un webhook de Beds24 el día de la llegada.
    $añadirCargo('0.01');
    $trasSaldo = $automaticos();

    $ok2 = count($trasSaldo) === 1
        && str_starts_with((string) $trasSaldo[0]->getConcepto(), 'Saldo de reserva');
    printf("\n%s Desde el día de la llegada emite SALDO\n", $ok2 ? '✅' : '❌');

    if (count($trasSaldo) === 1) {
        $e = $trasSaldo[0];
        $saldo = $saldoDe((string) $e->getMonedaCodigo());
        $esEntero = $saldo !== null && abs((float) $saldo - (float) $e->getMontoNeto()) < 0.005;
        printf(
            "   «%s» · %s %s de un saldo de %s  %s\n",
            $e->getConcepto(), $e->getMonedaCodigo(), $e->getMontoNeto(), $saldo ?? '—',
            $esEntero ? '✅ es el saldo entero' : '❌ no es el saldo entero',
        );
        $ok2 = $ok2 && $esEntero;

        $releva = isset($idAdelanto) && (string) $e->getId() !== (string) $idAdelanto;
        printf("%s El adelanto anterior quedó relevado (no hay dos pagables)\n", $releva ? '✅' : '❌');
        $ok2 = $ok2 && $releva;
    } else {
        printf("   hay %d automático(s) vivo(s)\n", count($trasSaldo));
    }

    $codigo = $ok2 ? $codigo : 1;

    // ── 3. Un manual por el saldo exacto: se reutiliza Y retira el adelanto ─
    //
    // Se rehace el escenario: se devuelve la llegada al futuro para que vuelva a emitirse un
    // ADELANTO, se emite a mano un enlace por el saldo, y se cruza la fecha otra vez.
    $conn->executeStatement(
        'UPDATE pms_reserva SET fecha_llegada = ? WHERE id = ?',
        [(new DateTimeImmutable('+10 days'))->format('Y-m-d'), $reservaId->toBinary()],
        [null, \Doctrine\DBAL\ParameterType::BINARY],
    );
    $em->refresh($reserva);
    $añadirCargo('0.01');

    $conAdelanto = $automaticos();

    if (count($conAdelanto) === 1 && str_starts_with((string) $conAdelanto[0]->getConcepto(), 'Adelanto')) {
        $cargador = new ReflectionMethod($kernel->getContainer(), 'load');
        $cargador->setAccessible(true);
        /** @var FinEnlacePagoService $enlacesService */
        $enlacesService = $cargador->invoke($kernel->getContainer(), 'getFinEnlacePagoServiceService');

        $operador = $em->getRepository(User::class)->findOneBy([]);
        \assert($operador instanceof User);

        $saldoAhora = $saldoDe('USD');
        $manual = $enlacesService->crear(
            origenTipo: FinOrigenCobro::PMS_RESERVA,
            origenId: $reservaId,
            montoNeto: $saldoAhora,
            concepto: 'PRUEBA: el operador cobra el total a mano',
            creadoPor: $operador,
            moneda: 'USD',
        );

        $idAdelanto2 = $conAdelanto[0]->getId();

        // Cruza el día de llegada con el manual ya emitido por el saldo exacto.
        $conn->executeStatement(
            'UPDATE pms_reserva SET fecha_llegada = CURDATE() WHERE id = ?',
            [$reservaId->toBinary()],
            [\Doctrine\DBAL\ParameterType::BINARY],
        );
        $em->refresh($reserva);
        $añadirCargo('0.00');

        $auto = $automaticos();
        $okSinNuevo = $auto === [];
        printf(
            "\n%s Con un manual por el saldo (%s), no emite otro automático (%d vivo)\n",
            $okSinNuevo ? '✅' : '❌', $saldoAhora, count($auto),
        );

        $adelantoRetirado = $repo->find($idAdelanto2)?->estaVigente() === false;
        printf("%s Y el adelanto viejo quedó RETIRADO igualmente\n", $adelantoRetirado ? '✅' : '❌');

        $manualVivo = $repo->find($manual->getId())?->estaVigente() === true;
        printf("%s El enlace del operador sigue vivo\n", $manualVivo ? '✅' : '❌');

        $codigo = ($okSinNuevo && $adelantoRetirado && $manualVivo) ? $codigo : 1;
    } else {
        printf("\n⚠️  No se pudo rehacer el adelanto; el caso 3 no se comprobó.\n");
    }

    // ── 4. Pago parcial + ya llegó → enlace por el saldo restante ───────────
    //
    // Se registra un pago a cuenta con la reserva ya llegada: antes eso cerraba la puerta y no
    // quedaba ningún enlace; ahora se pide lo que falta.
    $pago = new PmsPagoFinanciero();
    $pago->setInformacionFinanciera($info);
    $pago->setMoneda($usd);
    $pago->setTipoCambio('3.500');
    $pago->setMedioPago(PmsMedioPago::EFECTIVO);
    $pago->setMonto('50.00');
    $pago->setFechaPago(new DateTimeImmutable());
    $pago->setNotas('PRUEBA pago parcial');
    $info->addPago($pago);
    $em->persist($pago);
    $em->flush();
    $em->refresh($info);

    $trasPago = $automaticos();
    $saldoTrasPago = $saldoDe('USD');

    $ok4 = count($trasPago) === 1
        && str_starts_with((string) $trasPago[0]->getConcepto(), 'Saldo de reserva')
        && $saldoTrasPago !== null
        && abs((float) $saldoTrasPago - (float) $trasPago[0]->getMontoNeto()) < 0.005;

    printf("\n%s Con un pago parcial y ya llegada, emite por el SALDO restante\n", $ok4 ? '✅' : '❌');
    printf(
        "   %d automático(s) · %s · saldo %s\n",
        count($trasPago),
        count($trasPago) === 1
            ? sprintf('«%s» %s', $trasPago[0]->getConcepto(), $trasPago[0]->getMontoNeto())
            : '—',
        $saldoTrasPago ?? '—',
    );
    $codigo = $ok4 ? $codigo : 1;

    // Y antes de la llegada ese mismo pago SÍ cierra la puerta.
    $conn->executeStatement(
        'UPDATE pms_reserva SET fecha_llegada = ? WHERE id = ?',
        [(new DateTimeImmutable('+10 days'))->format('Y-m-d'), $reservaId->toBinary()],
        [null, \Doctrine\DBAL\ParameterType::BINARY],
    );
    $em->refresh($reserva);
    $añadirCargo('0.01');

    $ok4b = $automaticos() === [];
    printf("%s Antes de la llegada, el pago sigue cerrando la puerta (%d vivo)\n", $ok4b ? '✅' : '❌', count($automaticos()));
    $codigo = $ok4b ? $codigo : 1;

    // ── 5. El adelanto que ya ES el saldo se llama «Saldo» ──────────────────
    //
    // Se fuerza el caso de la estancia de UNA noche: con `primera_noche_total`, el calculador
    // reparte la base entre las noches y cobra una, así que con una sola noche la fracción es
    // el total. No se toca la política: se dejan las estancias en una noche.
    // TODAS las estancias a la MISMA noche: `getNoches()` cuenta noches distintas entre todos
    // los eventos vivos —dos casitas la misma noche son una noche—, así que con varios eventos
    // en días distintos seguirían siendo varias.
    $conn->executeStatement(
        'UPDATE pms_evento_calendario e
         SET e.inicio = (SELECT * FROM (SELECT MIN(DATE(x.inicio)) FROM pms_evento_calendario x WHERE x.reserva_id = ?) t),
             e.fin    = (SELECT * FROM (SELECT DATE_ADD(MIN(DATE(x.inicio)), INTERVAL 1 DAY) FROM pms_evento_calendario x WHERE x.reserva_id = ?) t2)
         WHERE e.reserva_id = ?',
        [$reservaId->toBinary(), $reservaId->toBinary(), $reservaId->toBinary()],
        [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY],
    );

    // ⚠️ `refresh($reserva)` NO recarga una colección ya cargada: `getNoches()` seguiría
    // leyendo los `fin` viejos. Hay que refrescar cada estancia.
    foreach ($reserva->getEventosCalendario() as $evento) {
        $em->refresh($evento);
    }
    // El pago de antes taparía el caso: lo que se comprueba aquí es el ADELANTO.
    $em->remove($pago);
    $em->flush();
    $em->refresh($info);
    $em->refresh($reserva);
    $añadirCargo('0.02');

    $unaNoche = $automaticos();
    $saldoUnaNoche = $saldoDe('USD');
    $ok5 = count($unaNoche) === 1
        && str_starts_with((string) $unaNoche[0]->getConcepto(), 'Saldo de reserva')
        && $saldoUnaNoche !== null
        && abs((float) $saldoUnaNoche - (float) $unaNoche[0]->getMontoNeto()) < 0.005;

    printf("\n%s Estancia de UNA noche: el enlace se llama «Saldo», no «Adelanto»\n", $ok5 ? '✅' : '❌');
    printf(
        "   %s · saldo %s · noches %d\n",
        count($unaNoche) === 1
            ? sprintf('«%s» %s', $unaNoche[0]->getConcepto(), $unaNoche[0]->getMontoNeto())
            : sprintf('%d automáticos', count($unaNoche)),
        $saldoUnaNoche ?? '—',
        $reserva->getNoches(),
    );
    $codigo = $ok5 ? $codigo : 1;

    // ── 6. Lo del operador sigue vivo ───────────────────────────────────────
    if ($manualesAntes > 0) {
        $ok3 = $manuales() === $manualesAntes;
        printf(
            "\n%s Los %d enlace(s) del operador siguen vivos (%d)\n",
            $ok3 ? '✅' : '❌', $manualesAntes, $manuales(),
        );
        $codigo = $ok3 ? $codigo : 1;
    } else {
        printf("\n⚠️  Esta reserva no traía enlaces del operador de antes: el caso 6 no aplica.\n");
    }
} finally {
    $conn->rollBack();
    echo "\n(rollback: no se persistió nada)\n";
}

exit($codigo);
