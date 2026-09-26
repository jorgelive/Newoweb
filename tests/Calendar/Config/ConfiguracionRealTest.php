<?php

declare(strict_types=1);

namespace App\Tests\Calendar\Config;

use App\Calendar\Config\ConfiguracionCalendario;
use App\Calendar\Provider\CalendarProviderInterface;
use App\Calendar\Provider\DoctrineCalendarProvider;
use App\Calendar\Provider\PmsEventosRawCalendarProvider;
use App\Calendar\Provider\PmsEventosSpaCalendarProvider;
use App\Calendar\Provider\ProviderRegistry;
use App\Calendar\Provider\TarifaCompressedRangesCalendarProvider;
use App\Calendar\Provider\TarifaCompressedRangesSpaCalendarProvider;
use App\Calendar\Provider\TarifaRangesRawCalendarProvider;
use App\Calendar\Provider\TarifaRangesSpaCalendarProvider;
use App\Pms\Entity\PmsEventoEstado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * La configuración REAL de los calendarios (los `parameters.calendars_*` de `config/`) se lee
 * entera, cada calendario cae en exactamente UN provider, y los valores que pintan el panel son
 * los esperados — incluidos los que no están escritos y salen del valor por defecto.
 *
 * Sin contenedor ni base: el YAML se parsea como lo haría Symfony y los providers se crean sin
 * constructor, porque `supports()` sólo mira la configuración. Que lo que PINTAN no cambió lo
 * comprueba `tools/pruebas/probar-calendario-config.php` contra la base local.
 */
#[CoversClass(ConfiguracionCalendario::class)]
#[CoversClass(ProviderRegistry::class)]
final class ConfiguracionRealTest extends TestCase
{
    /** El provider que tiene que atender cada nombre de `provider:` del YAML. */
    private const PROVIDERS = [
        'pms_eventos_raw' => PmsEventosRawCalendarProvider::class,
        'pms_eventos_spa' => PmsEventosSpaCalendarProvider::class,
        'tarifa_ranges_raw' => TarifaRangesRawCalendarProvider::class,
        'tarifa_ranges_spa' => TarifaRangesSpaCalendarProvider::class,
        'tarifa_compressed_ranges' => TarifaCompressedRangesCalendarProvider::class,
        'tarifa_compressed_ranges_spa' => TarifaCompressedRangesSpaCalendarProvider::class,
    ];

    /** @var array<string, ConfiguracionCalendario>|null */
    private static ?array $calendarios = null;

    /**
     * Los calendarios del repo, fusionados como los fusiona `CalendarConfigResolver`.
     *
     * @return array<string, ConfiguracionCalendario>
     */
    private static function calendarios(): array
    {
        if (self::$calendarios !== null) {
            return self::$calendarios;
        }

        $raiz = dirname(__DIR__, 3) . '/config';
        $archivos = array_merge(glob($raiz . '/*.yaml') ?: [], glob($raiz . '/services/*.yaml') ?: []);

        $crudos = [];
        foreach ($archivos as $archivo) {
            // `PARSE_CONSTANT` por el `!php/const PmsEventoEstado::OCUPAN_UNIDAD` del calendario de
            // ocupación; `PARSE_CUSTOM_TAGS` para no tropezar con `!tagged_iterator` y compañía.
            $yaml = Yaml::parseFile($archivo, Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
            $parametros = is_array($yaml) && is_array($yaml['parameters'] ?? null) ? $yaml['parameters'] : [];
            foreach ($parametros as $nombre => $valor) {
                if (($nombre === 'calendars' || str_starts_with((string) $nombre, 'calendars_')) && is_array($valor)) {
                    $crudos = array_merge($crudos, $valor);
                }
            }
        }

        $calendarios = [];
        foreach ($crudos as $clave => $crudo) {
            self::assertIsArray($crudo, "El calendario «{$clave}» no es un mapa.");
            $calendarios[(string) $clave] = ConfiguracionCalendario::fromArray($crudo);
        }

        return self::$calendarios = $calendarios;
    }

    private static function calendario(string $clave): ConfiguracionCalendario
    {
        $c = self::calendarios()[$clave] ?? null;
        self::assertNotNull($c, "Falta el calendario «{$clave}» en config/.");

        return $c;
    }

    public function testEstanLosNueveCalendariosDelPanel(): void
    {
        $claves = array_keys(self::calendarios());
        sort($claves);

        self::assertSame([
            'pms_eventos_no_cancelados',
            'pms_eventos_no_cancelados_spa',
            'pms_eventos_ocupacion_spa',
            'pms_eventos_todos',
            'pms_eventos_todos_spa',
            'tarifa_rangos_compactados',
            'tarifa_rangos_compactados_spa',
            'tarifa_rangos_raw',
            'tarifa_rangos_raw_spa',
        ], $claves);
    }

    public function testCadaCalendarioCaeEnExactamenteElProviderQueNombra(): void
    {
        $providers = [];
        foreach ([DoctrineCalendarProvider::class, ...array_values(self::PROVIDERS)] as $clase) {
            // `supports()` sólo lee la configuración: no hace falta montar sus dependencias.
            $provider = (new \ReflectionClass($clase))->newInstanceWithoutConstructor();
            self::assertInstanceOf(CalendarProviderInterface::class, $provider);
            $providers[] = $provider;
        }
        $registro = new ProviderRegistry($providers);

        foreach (self::calendarios() as $clave => $config) {
            self::assertNotNull($config->provider, "«{$clave}» no declara provider");
            self::assertArrayHasKey($config->provider, self::PROVIDERS, "«{$clave}»: provider desconocido");
            // El registro lanza si son 0 o más de 1: aquí basta con que devuelva el correcto.
            self::assertInstanceOf(self::PROVIDERS[$config->provider], $registro->getProviderForConfig($config), $clave);
        }
    }

    public function testLosCalendariosDeTarifasTraenLoObligatorio(): void
    {
        foreach (self::calendarios() as $clave => $config) {
            if (!str_starts_with($clave, 'tarifa_')) {
                continue;
            }

            self::assertSame('App\Pms\Entity\PmsTarifaRango', $config->entidad, $clave);
            self::assertNotNull($config->campos, $clave);
            self::assertSame('fechaInicio', $config->campos->start, $clave);
            self::assertSame('fechaFin', $config->campos->end, $clave);
            self::assertSame('precio', $config->campos->price, $clave);
            // La hora visual del YAML, que NO es el valor por defecto (11:59:59).
            self::assertSame('12:00:00', $config->horas->inicio, $clave);
            self::assertSame('11:59:00', $config->horas->fin, $clave);
            // Nadie escribe `priceDecimals`: sale del valor por defecto.
            self::assertSame(2, $config->evento->decimalesPrecio, $clave);
        }
    }

    public function testTarifasSinCompactar(): void
    {
        $legacy = self::calendario('tarifa_rangos_raw');
        self::assertFalse($legacy->evento->incluirMoneda);
        self::assertSame('{price} | {minStay}', $legacy->evento->formatoTitulo);
        self::assertSame('unidad', $legacy->campos?->resourceRoot);
        self::assertSame('unidad.id', $legacy->campos?->resourceId);
        self::assertSame('moneda.codigo', $legacy->campos?->currency);
        self::assertFalse($legacy->filtros->soloActivos);
        self::assertSame('id', $legacy->evento->enlaces?->rutaId);
        $edit = $legacy->evento->enlaces?->enlace('edit');
        self::assertSame('panel_dashboard_pms_tarifa_rango_edit', $edit?->nombreRuta);
        self::assertSame('ROLE_RESERVAS_WRITE', $edit?->rol);
        self::assertSame(['tl' => 'es'], $edit?->parametros);

        $spa = self::calendario('tarifa_rangos_raw_spa');
        self::assertTrue($spa->evento->incluirMoneda);
        self::assertSame('{currency} {price} · {minStay}N', $spa->evento->formatoTitulo);
        self::assertSame('moneda.simbolo', $spa->campos?->currency);
        self::assertNull($spa->evento->enlaces);
        self::assertNull($spa->evento->tooltip, 'sin `event.tooltip`: el tooltip por defecto del provider');
    }

    public function testTarifasCompactadas(): void
    {
        foreach (['tarifa_rangos_compactados', 'tarifa_rangos_compactados_spa'] as $clave) {
            $c = self::calendario($clave);
            self::assertSame('unidad', $c->campos?->unit, $clave);
            self::assertSame('unidad.id', $c->campos?->unitId, $clave);
            self::assertSame('unidad.nombre', $c->campos?->unitTitle, $clave);
            self::assertSame('activo', $c->campos?->active, $clave);
            self::assertTrue($c->filtros->soloActivos, $clave);
            self::assertFalse($c->filtros->noEsMapa, $clave);
            self::assertNull($c->enlacesRaiz, $clave);
        }

        $legacy = self::calendario('tarifa_rangos_compactados');
        self::assertSame('ROLE_RESERVAS_SHOW', $legacy->evento->enlaces?->enlace('show')?->rol);
        self::assertTrue($legacy->evento->incluirMoneda, 'no lo escribe: vale el defecto');
    }

    public function testEstanciasFiltrosYCatalogo(): void
    {
        $sinCanceladas = self::calendario('pms_eventos_no_cancelados_spa');
        self::assertSame([], $sinCanceladas->filtros->estado->incluir);
        self::assertSame(['cancelada', 'extension'], $sinCanceladas->filtros->estado->excluir);
        self::assertSame([], $sinCanceladas->filtros->estadoPago->incluir);
        self::assertSame([], $sinCanceladas->filtros->estadoPago->excluir);
        self::assertTrue($sinCanceladas->recursos->mostrarTodos);
        self::assertSame('App\Pms\Entity\PmsUnidad', $sinCanceladas->recursos->entidad);
        self::assertSame('nombre', $sinCanceladas->recursos->campoTitulo);
        self::assertFalse($sinCanceladas->recursos->soloActivos);
        self::assertSame(['slug' => 'slug', 'establecimientoSlug' => 'establecimiento.slug'], $sinCanceladas->recursos->camposExtra);
        // Sin `eventTime`: los defectos.
        self::assertSame('12:00:00', $sinCanceladas->horas->inicio);
        self::assertSame('11:59:59', $sinCanceladas->horas->fin);

        self::assertSame(['extension'], self::calendario('pms_eventos_todos_spa')->filtros->estado->excluir);
        self::assertSame(['cancelada', 'extension'], self::calendario('pms_eventos_no_cancelados')->filtros->estado->excluir);

        // La ocupación de fondo del calendario de tarifas: la lista blanca es la constante del
        // maestro, por `!php/const`, y el catálogo de filas va apagado.
        $ocupacion = self::calendario('pms_eventos_ocupacion_spa');
        self::assertSame(PmsEventoEstado::OCUPAN_UNIDAD, $ocupacion->filtros->estado->incluir);
        self::assertSame([], $ocupacion->filtros->estado->excluir);
        self::assertFalse($ocupacion->recursos->mostrarTodos);
        self::assertSame('activo', $ocupacion->recursos->campoActivo, 'no lo escribe: vale el defecto');
    }

    public function testEstanciasLegacyEnlazanReservaOEvento(): void
    {
        $c = self::calendario('pms_eventos_todos');
        $enlaces = $c->evento->enlaces;
        self::assertNotNull($enlaces);

        foreach ([
            'reservaEdit' => ['panel_dashboard_pms_reserva_edit', 'ROLE_RESERVAS_WRITE'],
            'reservaShow' => ['panel_dashboard_pms_reserva_detail', 'ROLE_RESERVAS_SHOW'],
            'eventoCalendarioEdit' => ['panel_dashboard_pms_evento_calendario_edit', 'ROLE_RESERVAS_WRITE'],
            'eventoCalendarioShow' => ['panel_dashboard_pms_evento_calendario_detail', 'ROLE_RESERVAS_SHOW'],
        ] as $nombre => [$ruta, $rol]) {
            $enlace = $enlaces->enlace($nombre);
            self::assertNotNull($enlace, $nombre);
            self::assertSame($ruta, $enlace->nombreRuta, $nombre);
            self::assertSame($rol, $enlace->rol, $nombre);
            self::assertTrue($enlace->rolDeclarado, $nombre);
            self::assertSame(['tl' => 'es'], $enlace->parametros, $nombre);
        }
    }
}
