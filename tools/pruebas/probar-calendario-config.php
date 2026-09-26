<?php

declare(strict_types=1);

/**
 * El feed de los calendarios (reservas y tarifas) sale IGUAL antes y después de tipar su configuración.
 *
 * Genera, contra la base LOCAL, lo que devuelven `/fullcalendar/load/event|resource/{calendario}`
 * para cada calendario del YAML real —con y sin roles, con y sin `current_page`, en tres ventanas de
 * fechas—, más unas configuraciones SINTÉTICAS que ejercitan lo que el YAML de hoy no usa (valores
 * por defecto, `event.tooltip`, `priceDecimals`, `url` en la raíz, filtros de estado en forma plana,
 * el provider Doctrine legacy, configuraciones rotas y su mensaje de error). Sólo LEE.
 *
 * Uso:
 *   php tools/pruebas/probar-calendario-config.php --guardar=/ruta/antes.json   # foto
 *   php tools/pruebas/probar-calendario-config.php --contra=/ruta/antes.json    # ✅ / ❌
 *
 * La foto se saca con el código de ANTES (otra rama, otro worktree) y se compara con el de después.
 *
 * ⚠️ Los ids que salen de `spl_object_id()` —el provider compactado legacy agrupa por él, ver
 * `docs/Calendar_architecture.md` §5— cambian con cualquier objeto que se cree antes de hidratar, así
 * que se renumeran por orden de aparición antes de comparar. Todo lo demás se compara tal cual.
 *
 * Ver `docs/Calendar_architecture.md` §3 y `docs/TiposDeFrontera.md`.
 */

use App\Calendar\Config\ConfiguracionCalendario;
use App\Calendar\Controller\FullcalendarLoadController;
use App\Calendar\Provider\ProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$opciones = getopt('', ['guardar:', 'contra:']);
$guardar = is_string($opciones['guardar'] ?? null) ? $opciones['guardar'] : null;
$contra = is_string($opciones['contra'] ?? null) ? $opciones['contra'] : null;
if ($guardar === null && $contra === null) {
    fwrite(STDERR, "Uso: --guardar=ruta.json | --contra=ruta.json\n");
    exit(2);
}

$kernel = new App\Kernel('dev', false);
$kernel->boot();
$contenedor = $kernel->getContainer();

/** @var EntityManagerInterface $em */
$em = $contenedor->get('doctrine')->getManager();
/** @var FullcalendarLoadController $controlador */
$controlador = $contenedor->get(FullcalendarLoadController::class);

// El registro y el almacén del token son privados en el contenedor: se toman de quien los usa de
// verdad —el controlador y el provider Doctrine—, que es además la misma instancia que consulta
// el AuthorizationChecker.
/** @var ProviderRegistry $registro */
$registro = (new ReflectionProperty($controlador, 'providerRegistry'))->getValue($controlador);
$tokens = null;
foreach ((new ReflectionProperty($registro, 'providers'))->getValue($registro) as $provider) {
    if ($provider instanceof App\Calendar\Provider\DoctrineCalendarProvider) {
        $tokens = (new ReflectionProperty($provider, 'tokenStorage'))->getValue($provider);
    }
}
if (!$tokens instanceof TokenStorageInterface) {
    fwrite(STDERR, "No encontré el TokenStorage del provider Doctrine.\n");
    exit(2);
}

// Las claves de calendario, leídas como las lee CalendarConfigResolver (prefijo `calendars_`).
$claves = [];
foreach ($contenedor->getParameterBag()->all() as $nombre => $valor) {
    if (str_starts_with((string) $nombre, 'calendars_') && is_array($valor)) {
        $claves = array_merge($claves, array_keys($valor));
    }
}
sort($claves);

$ventanas = [
    'ago' => ['2026-08-01T00:00:00', '2026-09-01T00:00:00'],
    'sep' => ['2026-09-01T00:00:00', '2026-10-01T00:00:00'],
    'oct-dic' => ['2026-10-01T00:00:00', '2027-01-01T00:00:00'],
];

$identidades = [
    'con-roles' => ['ROLE_USER', 'ROLE_RESERVAS_WRITE', 'ROLE_RESERVAS_SHOW'],
    'sin-roles' => null,
];

$ponerIdentidad = static function (?array $roles) use ($tokens): void {
    $tokens->setToken($roles === null ? null : new UsernamePasswordToken(new InMemoryUser('sonda', null, $roles), 'main', $roles));
};

/**
 * Renumera los ids que vienen de `spl_object_id()`: sólo dígitos, o «dígitos-índice».
 */
$normalizar = static function (mixed $datos): mixed {
    $mapa = [];
    $canon = static function (string $valor) use (&$mapa): string {
        if (preg_match('/^(\d+)(-\d+)?$/', $valor, $m) !== 1) {
            return $valor;
        }
        $mapa[$m[1]] ??= '#' . count($mapa);

        return $mapa[$m[1]] . ($m[2] ?? '');
    };
    $recorrer = static function (mixed $x) use (&$recorrer, $canon): mixed {
        if (!is_array($x)) {
            return $x;
        }
        foreach ($x as $k => $v) {
            if (in_array($k, ['id', 'resourceId', 'tarifaRangoId'], true) && (is_string($v) || is_int($v))) {
                $x[$k] = $canon((string) $v);
            } else {
                $x[$k] = $recorrer($v);
            }
        }

        return $x;
    };

    return $recorrer($datos);
};

$foto = [];
$anotar = static function (string $etiqueta, callable $produce) use (&$foto, $normalizar, $em): void {
    try {
        $foto[$etiqueta] = $normalizar($produce());
    } catch (Throwable $e) {
        $foto[$etiqueta] = ['__error' => $e::class . ': ' . $e->getMessage()];
    }
    $em->clear();
};

$json = static function (object $respuesta): mixed {
    return json_decode((string) $respuesta->getContent(), true);
};

// ── 1. Los calendarios REALES, por el controlador: mismo camino que una petición del panel ──
foreach ($identidades as $quien => $roles) {
    $ponerIdentidad($roles);
    foreach ($claves as $clave) {
        foreach ($ventanas as $nombreVentana => [$desde, $hasta]) {
            foreach (['sin-pagina' => null, 'con-pagina' => base64_encode('https://util.openperu.test/admin?x=1')] as $pag => $pagina) {
                $consulta = ['start' => $desde, 'end' => $hasta] + ($pagina !== null ? ['current_page' => $pagina] : []);
                $anotar("real|$quien|$clave|$nombreVentana|$pag|eventos", static fn () => $json($controlador->events(new Request($consulta), $clave)));
            }
            $anotar("real|$quien|$clave|$nombreVentana|recursos", static fn () => $json($controlador->resources(new Request(['start' => $desde, 'end' => $hasta]), $clave)));
        }
    }
}

// ── 2. Configuraciones SINTÉTICAS: lo que el YAML de hoy no ejercita ──
$tarifa = 'App\Pms\Entity\PmsTarifaRango';
$evento = 'App\Pms\Entity\PmsEventoCalendario';
$urlTarifa = [
    'show' => ['route' => 'panel_dashboard_pms_tarifa_rango_detail', 'role' => 'ROLE_RESERVAS_SHOW', 'params' => ['tl' => 'en', 'extra' => 1]],
    'edit' => ['route' => 'panel_dashboard_pms_tarifa_rango_edit', 'role' => 'ROLE_RESERVAS_WRITE'],
];
$sinteticas = [
    // Doctrine legacy: ningún calendario del YAML lo usa, pero sigue enchufado.
    'doctrine-completo' => [
        'entity' => $evento,
        'parameters' => [
            'start' => 'inicio', 'end' => 'fin', 'title' => 'descripcion', 'id' => 'id',
            'backgroundColor' => 'estado.color', 'textColor' => 'estado.nombre', 'color' => 'estadoPago.color',
            'borderColor' => 'estadoPago.nombre',
            'classNames' => 'referenciaCanal', 'tooltip' => ['descripcion', 'referenciaCanal', 'pmsUnidad.nombre'],
        ],
        'resource' => ['root' => 'pmsUnidad', 'id' => 'id', 'title' => 'nombre'],
    ],
    'doctrine-tooltip-texto' => [
        'entity' => $evento,
        'parameters' => ['start' => 'inicio', 'end' => 'fin', 'tooltip' => 'referenciaCanal', 'id' => ''],
    ],
    'doctrine-con-url' => [
        'entity' => $evento,
        // `api_genid` porque pide sólo `{id}`, que es lo único que pasa este provider.
        'parameters' => ['start' => 'inicio', 'end' => 'fin', 'url' => ['id' => 'pmsUnidad.id', 'edit' => ['role' => 'ROLE_RESERVAS_WRITE', 'route' => 'api_genid'], 'show' => ['route' => 'api_genid']]],
    ],
    'doctrine-metodo-inexistente' => ['entity' => $evento, 'repositorymethod' => 'noExiste'],
    // Tarifas sin compactar, con todo lo opcional encendido.
    'rangos-raw-todo' => [
        'provider' => 'tarifa_ranges_raw', 'entity' => $tarifa,
        'fields' => ['start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio', 'resourceRoot' => 'unidad', 'active' => 'activo'],
        'filters' => ['activeOnly' => true, 'showInactive' => true],
        'event' => ['priceDecimals' => 0, 'includeCurrency' => false, 'tooltip' => ['unidad.nombre', 'moneda.simbolo', 'precio', 'noExiste'],
            'url' => ['id' => 'unidad.id'] + $urlTarifa],
        'eventTime' => ['start' => '14:30', 'end' => ''],
    ],
    'rangos-raw-minimo' => ['provider' => 'tarifa_ranges_raw', 'entity' => $tarifa, 'fields' => ['start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio']],
    'rangos-spa-minimo' => ['provider' => 'tarifa_ranges_spa', 'entity' => $tarifa, 'fields' => ['start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio', 'currency' => 'moneda.simbolo']],
    'rangos-spa-activos-sin-campo' => ['provider' => 'tarifa_ranges_spa', 'entity' => $tarifa, 'fields' => ['start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio'], 'filters' => ['activeOnly' => true]],
    'rangos-raw-sin-fields' => ['provider' => 'tarifa_ranges_raw', 'entity' => $tarifa],
    'rangos-raw-sin-price' => ['provider' => 'tarifa_ranges_raw', 'entity' => $tarifa, 'fields' => ['start' => 'fechaInicio', 'end' => 'fechaFin']],
    'rangos-spa-entity-vacia' => ['provider' => 'tarifa_ranges_spa', 'entity' => '', 'fields' => []],
    // Compactadas: la `url` en la raíz (respaldo de `event.url`) y los mínimos.
    'compactado-url-raiz' => [
        'provider' => 'tarifa_compressed_ranges', 'entity' => $tarifa,
        'fields' => ['unit' => 'unidad', 'start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio', 'unitTitle' => 'codigoInterno'],
        'url' => $urlTarifa,
        'eventTime' => ['start' => '25:00:00', 'end' => '10:61'],
    ],
    'compactado-minimo' => ['provider' => 'tarifa_compressed_ranges', 'entity' => $tarifa, 'fields' => ['unit' => 'unidad', 'start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio']],
    'compactado-spa-minimo' => ['provider' => 'tarifa_compressed_ranges_spa', 'entity' => $tarifa, 'fields' => ['unit' => 'unidad', 'start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio']],
    'compactado-spa-formato' => [
        'provider' => 'tarifa_compressed_ranges_spa', 'entity' => $tarifa,
        'fields' => ['unit' => 'unidad', 'unitId' => 'unidad.id', 'start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio', 'currency' => 'moneda.simbolo', 'id' => 'id', 'important' => 'importante', 'weight' => 'prioridad', 'minStay' => 'minStay'],
        'event' => ['titleFormat' => '{neto20}/{neto30} {currency}', 'includeCurrency' => false],
    ],
    'compactado-filtros-no-mapa' => ['provider' => 'tarifa_compressed_ranges_spa', 'entity' => $tarifa, 'fields' => ['unit' => 'unidad', 'start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio'], 'filters' => 'x'],
    'compactado-activos-sin-campo' => ['provider' => 'tarifa_compressed_ranges', 'entity' => $tarifa, 'fields' => ['unit' => 'unidad', 'start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio'], 'filters' => ['activeOnly' => true]],
    // Estancias: los filtros de estado en sus formas planas, y el catálogo con variantes.
    'eventos-estado-texto' => ['provider' => 'pms_eventos_spa', 'filters' => ['estado' => 'cancelada'], 'resources' => ['showAll' => false]],
    'eventos-estado-lista' => ['provider' => 'pms_eventos_raw', 'filters' => ['estado' => ['confirmada', 'bloqueo']],
        'resources' => ['activeOnly' => true, 'titleField' => 'codigoInterno', 'extraFields' => ['slug' => 'slug', 'x' => 'noExiste.y']]],
    'eventos-in-y-not-in' => ['provider' => 'pms_eventos_raw', 'filters' => ['estado' => ['in' => ['confirmada', 'cancelada'], 'not_in' => 'cancelada'], 'estadoPago' => ['in' => [], 'not_in' => []]],
        'event' => ['url' => ['edit' => ['route' => 'panel_dashboard_pms_reserva_edit'], 'reservaShow' => ['route' => 'panel_dashboard_pms_reserva_detail', 'role' => 'ROLE_NADIE', 'params' => ['tl' => 'en']]]]],
    'eventos-sin-nada' => ['provider' => 'pms_eventos_spa'],
    'sin-provider' => ['foo' => 1, 'bar' => 2],
];

$aConfig = static function (array $crudo): mixed {
    // Antes del refactor los providers recibían el array; después, el objeto. La sonda vale para los dos.
    return class_exists(ConfiguracionCalendario::class) ? ConfiguracionCalendario::fromArray($crudo) : $crudo;
};

foreach ($identidades as $quien => $roles) {
    $ponerIdentidad($roles);
    foreach ($sinteticas as $nombre => $crudo) {
        foreach (['sin-pagina' => $crudo, 'con-pagina' => $crudo + ['runtime_returnTo' => 'cGFnaW5h']] as $pag => $variante) {
            [$desde, $hasta] = $ventanas['sep'];
            $anotar("sintetica|$quien|$nombre|$pag|eventos", static function () use ($registro, $aConfig, $variante, $desde, $hasta) {
                $config = $aConfig($variante);

                return json_decode((string) json_encode($registro->getProviderForConfig($config)->getEvents(new DateTimeImmutable($desde), new DateTimeImmutable($hasta), $config)), true);
            });
        }
        [$desde, $hasta] = $ventanas['oct-dic'];
        $anotar("sintetica|$quien|$nombre|recursos", static function () use ($registro, $aConfig, $crudo, $desde, $hasta) {
            $config = $aConfig($crudo);

            return json_decode((string) json_encode($registro->getProviderForConfig($config)->getResources(new DateTimeImmutable($desde), new DateTimeImmutable($hasta), $config)), true);
        });
    }
}
$tokens->setToken(null);

// ── 3. Guardar o comparar ──
$eventosTotales = 0;
foreach ($foto as $contenido) {
    $eventosTotales += is_array($contenido) && !isset($contenido['__error']) ? count($contenido) : 0;
}
$errores = count(array_filter($foto, static fn ($c) => is_array($c) && isset($c['__error'])));

if ($guardar !== null) {
    file_put_contents($guardar, json_encode($foto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    printf("📸 %d respuestas (%d elementos, %d errores esperados o no) guardadas en %s\n", count($foto), $eventosTotales, $errores, $guardar);
    exit(0);
}

$antes = json_decode((string) file_get_contents((string) $contra), true);
if (!is_array($antes)) {
    fwrite(STDERR, "No se pudo leer $contra\n");
    exit(2);
}

$distintas = [];
foreach (array_unique(array_merge(array_keys($antes), array_keys($foto))) as $etiqueta) {
    if (($antes[$etiqueta] ?? '«falta»') !== ($foto[$etiqueta] ?? '«falta»')) {
        $distintas[] = $etiqueta;
    }
}

printf("%d respuestas comparadas (%d elementos; %d terminan en error, igual que antes si no salen abajo)\n", count($foto), $eventosTotales, $errores);
if ($distintas === []) {
    echo "✅ Idénticas, byte a byte tras normalizar los spl_object_id.\n";
    exit(0);
}

foreach ($distintas as $etiqueta) {
    echo "❌ $etiqueta\n";
    echo '   antes:   ' . substr((string) json_encode($antes[$etiqueta] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 400) . "\n";
    echo '   después: ' . substr((string) json_encode($foto[$etiqueta] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 400) . "\n";
}
exit(1);
