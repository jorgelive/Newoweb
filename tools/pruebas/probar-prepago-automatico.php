<?php

declare(strict_types=1);

/**
 * ¿El enlace de adelanto se emite solo cuando la reserva estrena importes?
 *
 * Lo que se comprueba, sobre una reserva real y EN TRANSACCIÓN CON ROLLBACK:
 *   1. Al añadir un cargo, la reserva estrena UN enlace, sin caducidad y sin autor.
 *   2. Un segundo movimiento por el mismo importe NO emite otro (idempotencia).
 *   3. Si el importe del adelanto CAMBIA, se emite uno nuevo y el viejo queda ANULADO
 *      —nunca dos pagables a la vez, que es lo que haría pagar el que no toca—.
 *   8. Y un enlace que emitió UN OPERADOR A MANO sobrevive a ese mismo recálculo — el bug
 *      real del 05/09/2026: `emitirConTurno()` llamaba a `anularVigentes()` (sin filtrar
 *      autor) en vez de `anularAutomaticosVigentes()`, así que el sync de Beds24 se llevaba
 *      por delante cualquier enlace manual de la reserva, fuera cual fuera su importe.
 *
 * ⚠️ **Hay que correrla con `FINANZAS_ENLACES_PREPAGO=1`.** En local el interruptor está en 0
 * y el emisor no hace nada: la prueba salía en rojo sin que hubiera nada roto.
 *
 * ⚠️ Y la reserva se elige por LLEGADA FUTURA y sin pagos. Desde el 06/09/2026 el emisor pide
 * el SALDO entero a partir del día de llegada (`PmsPrepagoCalculador::queSePide()`), así que
 * una reserva ya empezada emitiría «Saldo de reserva» y no el adelanto que aquí se comprueba.
 * Ese camino tiene su propia prueba: `tools/pruebas/probar-prepago-dia-de-llegada.php`.
 *
 * Uso: FINANZAS_ENLACES_PREPAGO=1 php tools/pruebas/probar-prepago-automatico.php [localizador]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\User;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinEnlacePagoEstado;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\FinEnlacePagoService;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Enum\PmsTipoCargo;
use Doctrine\ORM\EntityManagerInterface;

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
    echo "El emisor automático está APAGADO (FINANZAS_ENLACES_PREPAGO=0): no emitiría nada.\n";
    echo "  FINANZAS_ENLACES_PREPAGO=1 php tools/pruebas/probar-prepago-automatico.php\n";
    exit(1);
}

$localizador = $argv[1] ?? null;

// Una reserva a la que de verdad se le pueda pedir adelanto. Las tres condiciones importan:
// canal que NO cobra por nosotros, cabecera activa, y NINGÚN pago —`pendiente()` devuelve null
// en cuanto hay uno—. Sin filtrar, esto cogía «la última creada» y acababa en una reserva ya
// pagada: no se emitía nada y la prueba salía en ROJO culpando al código.
$sql = "SELECT i.id FROM pms_reserva r
        INNER JOIN pms_informacion_financiera i ON i.reserva_id = r.id
        WHERE r.channel_id NOT IN ('airbnb','vrbo')
          AND i.activa = 1
          AND r.fecha_llegada > CURDATE()
          AND NOT EXISTS (SELECT 1 FROM pms_pago_financiero p WHERE p.informacion_id = i.id)"
    . ($localizador ? ' AND r.localizador = ?' : '') . '
        ORDER BY r.fecha_llegada LIMIT 1';
$infoIdBin = $conn->fetchOne($sql, $localizador ? [$localizador] : []);

if (!is_string($infoIdBin) || $infoIdBin === '') {
    echo "No hay ninguna reserva de canal que no cobre por nosotros.\n";
    exit(1);
}

$conn->beginTransaction();
$codigo = 1;

try {
    $info = $em->getRepository(PmsInformacionFinanciera::class)
        ->find(Symfony\Component\Uid\Uuid::fromString($infoIdBin)->toRfc4122());
    \assert($info instanceof PmsInformacionFinanciera);

    $reserva = $info->getReserva();
    $reservaId = $reserva?->getId();
    \assert($reservaId !== null);

    $vivos = static function () use ($repo, $reservaId): array {
        return array_values(array_filter(
            $repo->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId),
            static fn (FinEnlacePago $e): bool => $e->estaVigente(),
        ));
    };

    printf("Reserva %s · canal %s\n", $reserva?->getLocalizador(), $reserva?->getChannel()?->getId());
    printf("Enlaces vivos al empezar: %d\n\n", count($vivos()));

    $usd = $em->getRepository(MaestroMoneda::class)->find('USD');
    \assert($usd instanceof MaestroMoneda);

    $añadirCargo = static function (string $importe) use ($em, $info, $usd): void {
        $c = new PmsCargoFinanciero();
        $c->setInformacionFinanciera($info);
        $c->setMoneda($usd);
        $c->setTipoCambio('3.500');
        $c->setTipoCargo(PmsTipoCargo::ALOJAMIENTO);
        $c->setDescripcion('PRUEBA prepago automático');
        $c->setTotalLinea($importe);
        $info->addCargo($c);
        $em->persist($c);
        $em->flush();
        $em->refresh($info);
    };

    // ── 1. Estrena importes → estrena enlace ────────────────────────────────
    $añadirCargo('200.00');
    $tras1 = $vivos();
    $ok1 = count($tras1) === 1;
    printf("%s Tras el primer cargo hay %d enlace(s) vivo(s)\n", $ok1 ? '✅' : '❌', count($tras1));

    if ($ok1) {
        $e = $tras1[0];
        $sinCaducidad = $e->getExpiraEn() === null;
        $sinAutor = $e->getCreadoPorNombre() === null;
        printf("   %s sin caducidad · %s sin autor (%s %s)\n",
            $sinCaducidad ? '✅' : '❌', $sinAutor ? '✅' : '❌',
            $e->getMonedaCodigo(), $e->getMontoNeto());
        $ok1 = $ok1 && $sinCaducidad && $sinAutor;
    }

    // ── 2. Mismo importe → NO emite otro ────────────────────────────────────
    // Un cargo en 0.00: mueve el recálculo pero NO el importe del adelanto.
    $añadirCargo('0.00');

    $tras2 = $vivos();
    $ok2 = count($tras2) === 1;
    printf("%s Un movimiento sin cambio de importe NO emite otro (%d vivo)\n", $ok2 ? '✅' : '❌', count($tras2));

    // ── 3. Cambia el importe → uno nuevo y el viejo ANULADO ─────────────────
    $viejoId = $tras2[0]->getId() ?? null;
    $añadirCargo('120.00');

    $tras3 = $vivos();
    $ok3 = count($tras3) === 1;
    printf("%s Al cambiar el adelanto sigue habiendo UN solo enlace vivo (%d)\n", $ok3 ? '✅' : '❌', count($tras3));

    $viejo = $viejoId ? $repo->find($viejoId) : null;
    $ok4 = $viejo instanceof FinEnlacePago && $viejo->getEstado() === FinEnlacePagoEstado::ANULADO;
    printf("%s El anterior quedó ANULADO (%s)\n", $ok4 ? '✅' : '❌',
        $viejo instanceof FinEnlacePago ? $viejo->getEstado()->value : 'no encontrado');

    // ── 4. La reserva se anula → el enlace automático tiene que MORIR ────────
    //
    // Lo destapó la revisión: con la cabecera inactiva los cargos dejan de sumar PERO la
    // PENALIZACIÓN sigue contando (§12.7), así que la base NO es cero y el calculador
    // devolvería una fracción de la penalidad. Y como los automáticos se emiten sin
    // caducidad, ese enlace quedaría pagable para siempre sobre una reserva cancelada.
    $vivoAntes = $vivos()[0] ?? null;

    $info->setActiva(false);
    $em->flush();
    $em->refresh($info);

    $ok5 = $vivos() === [];
    printf("%s Con la reserva anulada no queda ningún enlace automático vivo (%d)\n", $ok5 ? '✅' : '❌', count($vivos()));

    $ok6 = $vivoAntes === null
        || $repo->find($vivoAntes->getId())?->getEstado() === FinEnlacePagoEstado::ANULADO;
    printf("%s Y el que había quedó ANULADO, no vivo ni caducado\n", $ok6 ? '✅' : '❌');

    // ── 5. El turno excluye de verdad ────────────────────────────────────────
    //
    // Se toma el mismo lock desde OTRA conexión y se comprueba que el emisor se retira en vez
    // de emitir. Es lo único que no se puede ver con una sola conexión: `GET_LOCK` es
    // reentrante para la sesión que ya lo tiene.
    $info->setActiva(true);
    $em->flush();
    $em->refresh($info);

    $otra = Doctrine\DBAL\DriverManager::getConnection($conn->getParams());
    $nombreLock = substr('prepago-' . $conn->getDatabase() . '-' . $reservaId, 0, 64);
    $tomado = (int) $otra->fetchOne('SELECT GET_LOCK(?, 0)', [$nombreLock]) === 1;

    $vivosAntes = count($vivos());
    $añadirCargo('75.00');            // cambia el importe: sin lock emitiría uno nuevo
    $ok7 = $tomado && count($vivos()) === $vivosAntes;

    printf("%s Con el turno tomado por otra conexión, el emisor se retira (%d → %d enlaces)\n",
        $ok7 ? '✅' : '❌', $vivosAntes, count($vivos()));

    $otra->executeStatement('SELECT RELEASE_LOCK(?)', [$nombreLock]);
    $otra->close();

    // ── 8. Un enlace MANUAL sobrevive al recálculo automático ───────────────
    //
    // `FinEnlacePagoService` es un servicio privado del contenedor (no hay motivo de negocio
    // para exponerlo); se llega a él por reflexión sobre `Container::load()`, el mismo método
    // protegido que el propio contenedor compilado usa internamente para resolver sus
    // servicios privados — en vez de reimplementar aquí todo el wiring de sus dependencias.
    $cargador = new ReflectionMethod($kernel->getContainer(), 'load');
    $cargador->setAccessible(true);
    /** @var FinEnlacePagoService $enlacesService */
    $enlacesService = $cargador->invoke($kernel->getContainer(), 'getFinEnlacePagoServiceService');

    $operador = $em->getRepository(User::class)->findOneBy([]);
    \assert($operador instanceof User);

    $manual = $enlacesService->crear(
        origenTipo: FinOrigenCobro::PMS_RESERVA,
        origenId: $reservaId,
        montoNeto: '999.99', // un importe que la política de prepago jamás va a pedir
        concepto: 'PRUEBA: cobro manual del operador, por otro concepto',
        creadoPor: $operador,
    );
    $manualId = $manual->getId();

    $vivosAntesDe8 = count($vivos());
    $añadirCargo('50.00'); // vuelve a mover el adelanto automático
    $vivosDespuesDe8 = $vivos();

    $sigueVivo = $repo->find($manualId);
    $ok8 = $sigueVivo instanceof FinEnlacePago
        && $sigueVivo->estaVigente()
        && $sigueVivo->getEstado() !== FinEnlacePagoEstado::ANULADO;

    printf(
        "%s El enlace manual del operador sigue VIGENTE tras el recálculo automático (estado: %s)\n",
        $ok8 ? '✅' : '❌',
        $sigueVivo instanceof FinEnlacePago ? $sigueVivo->getEstado()->value : 'no encontrado',
    );
    printf("   enlaces vivos antes/después del cargo: %d → %d\n", $vivosAntesDe8, count($vivosDespuesDe8));

    $codigo = ($ok1 && $ok2 && $ok3 && $ok4 && $ok5 && $ok6 && $ok7 && $ok8) ? 0 : 1;
} finally {
    $conn->rollBack();
    echo "\n(rollback: no se persistió nada)\n";
}

exit($codigo);
