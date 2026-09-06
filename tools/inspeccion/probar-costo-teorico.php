<?php

declare(strict_types=1);

/**
 * ¿Qué enseña el tooltip de «costo teórico» sobre estancias directas reales?
 *
 * Sonda de SOLO LECTURA: no persiste ni modifica nada. Recorre las últimas estancias directas
 * y pide a `PmsCargosAutomaticosService::costoTeorico()` el mismo desglose que el panel pinta
 * junto al cargo en cero, para poder contrastarlo a mano contra el tarifario.
 *
 * Corre en `test` porque ahí el contenedor expone los servicios privados
 * (`framework.test: true`); no toca ninguna base distinta de la de siempre.
 *
 * Uso: php var/probar-costo-teorico.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Service\Finance\MonedaResolver;
use App\Pms\Service\Finance\PmsCargosAutomaticosService;
use App\Pms\Service\Tarifa\Engine\TarifaDailyPriceFlattener;
use App\Pms\Service\Tarifa\Engine\TarifaLogicalRangeCompressor;
use App\Pms\Service\Tarifa\Engine\TarifaPricingEngine;
use App\Pms\Service\Tarifa\PmsTarifaCalculadora;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

// El servicio se arma a mano en vez de pedirlo al contenedor: en `dev` es privado, y el
// contenedor de `test` apunta a `..._test`, una base que aquí no existe. Son cuatro
// dependencias sin estado, así que montarlas es más barato que preparar una base de pruebas.
$svc = new PmsCargosAutomaticosService(
    $em,
    new PmsTarifaCalculadora($em, new TarifaPricingEngine(new TarifaDailyPriceFlattener(), new TarifaLogicalRangeCompressor())),
    new MonedaResolver($em),
    new NullLogger(),
);

$eventos = $em->createQuery(
    'SELECT e FROM ' . PmsEventoCalendario::class . ' e
     LEFT JOIN e.channel ch
     WHERE ch.id = :directo OR ch.id IS NULL
     ORDER BY e.inicio DESC'
)->setParameter('directo', 'directo')->setMaxResults(15)->getResult();

printf("Estancias directas revisadas: %d\n\n", count($eventos));

foreach ($eventos as $ev) {
    $pax = (int) $ev->getCantidadAdultos() + (int) $ev->getCantidadNinos();
    printf(
        "%-12s %s → %s  (%d pax, %s)\n",
        $ev->getPmsUnidad()?->getNombre() ?? '¿?',
        $ev->getInicio()?->format('d/m/Y') ?? '—',
        $ev->getFin()?->format('d/m/Y') ?? '—',
        $pax,
        $ev->getEstado()?->getId() ?? '—',
    );

    $t = $svc->costoTeorico($ev);

    if ($t === null) {
        echo "   → sin coste teórico (bloqueo, extensión o sin fechas)\n\n";
        continue;
    }

    $a = $t['alojamiento'];
    echo $a === null
        ? "   [cama]  sin tarifa para TODAS las noches\n"
        : sprintf("   [cama]  %s x %d N = %s\n", $a['porNoche'] ?? 'variable', $a['noches'], $a['importe']);

    if ($t['paxAdicional'] !== null) {
        printf(
            "   [pers]  %s x %d P x %d N = %s\n",
            $t['paxAdicional']['porPersonaNoche'],
            $t['paxAdicional']['personas'],
            $t['paxAdicional']['noches'],
            $t['paxAdicional']['importe'],
        );
    }

    if ($t['limpieza'] !== null) {
        printf("   [esco]  %s%s\n", $t['limpieza']['importe'], $t['limpieza']['esPorcentaje'] ? ' (porcentaje)' : '');
    }

    printf("   ====    TOTAL %s %s\n\n", $t['total'], $t['moneda'] ?? '(sin moneda)');
}
