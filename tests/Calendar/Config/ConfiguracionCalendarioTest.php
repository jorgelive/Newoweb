<?php

declare(strict_types=1);

namespace App\Tests\Calendar\Config;

use App\Calendar\Config\CamposDeTarifa;
use App\Calendar\Config\ConfiguracionCalendario;
use App\Calendar\Config\Enlace;
use App\Calendar\Config\EnlacesDeEvento;
use App\Calendar\Config\FiltroDeIds;
use App\Calendar\Config\FiltrosDeCalendario;
use App\Calendar\Config\HorasDeEvento;
use App\Calendar\Config\OpcionesDeCatalogo;
use App\Calendar\Config\OpcionesDeEvento;
use App\Calendar\Config\OpcionesDoctrineLegacy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La configuración del calendario se lee UNA vez y con los valores por defecto que ya aplicaba
 * cada provider. Aquí van los defaults y los casos raros; la configuración real del repo la
 * recorre `ConfiguracionRealTest`. Ver `docs/Calendar_architecture.md` §3.
 */
#[CoversClass(ConfiguracionCalendario::class)]
#[CoversClass(CamposDeTarifa::class)]
#[CoversClass(Enlace::class)]
#[CoversClass(EnlacesDeEvento::class)]
#[CoversClass(FiltroDeIds::class)]
#[CoversClass(FiltrosDeCalendario::class)]
#[CoversClass(HorasDeEvento::class)]
#[CoversClass(OpcionesDeCatalogo::class)]
#[CoversClass(OpcionesDeEvento::class)]
#[CoversClass(OpcionesDoctrineLegacy::class)]
final class ConfiguracionCalendarioTest extends TestCase
{
    public function testUnaConfiguracionVaciaTomaLosDefectosDeLosProviders(): void
    {
        $c = ConfiguracionCalendario::fromArray([]);

        self::assertSame([], $c->claves);
        self::assertNull($c->provider);
        self::assertFalse($c->declaraProvider);
        self::assertNull($c->entidad);
        self::assertNull($c->retorno);
        self::assertNull($c->campos, 'sin `fields` no hay campos: el provider rechaza con su mensaje');
        self::assertNull($c->enlacesRaiz);

        // `event`
        self::assertTrue($c->evento->incluirMoneda);
        self::assertNull($c->evento->formatoTitulo, 'el formato por defecto lo pone cada provider');
        self::assertSame(2, $c->evento->decimalesPrecio);
        self::assertNull($c->evento->tooltip);
        self::assertNull($c->evento->enlaces);

        // `eventTime`
        self::assertSame('12:00:00', $c->horas->inicio);
        self::assertSame('11:59:59', $c->horas->fin);

        // `filters`
        self::assertFalse($c->filtros->soloActivos);
        self::assertFalse($c->filtros->mostrarInactivos);
        self::assertFalse($c->filtros->noEsMapa);
        self::assertSame([], $c->filtros->estado->incluir);
        self::assertSame([], $c->filtros->estado->excluir);

        // `resources`: sin bloque, el catálogo lista TODO (es el motivo de que exista).
        self::assertTrue($c->recursos->mostrarTodos);
        self::assertNull($c->recursos->entidad);
        self::assertSame('', $c->recursos->campoTitulo);
        self::assertSame('activo', $c->recursos->campoActivo);
        self::assertFalse($c->recursos->soloActivos);
        self::assertSame('establecimiento', $c->recursos->campoEstablecimiento);
        self::assertSame([], $c->recursos->establecimientoIds);
        self::assertSame([], $c->recursos->camposExtra);

        // Doctrine legacy
        self::assertNull($c->doctrine->metodoRepositorio);
        self::assertSame('start', $c->doctrine->inicio);
        self::assertSame('end', $c->doctrine->fin);
        self::assertSame('id', $c->doctrine->id);
        self::assertSame('title', $c->doctrine->titulo);
        self::assertFalse($c->doctrine->conRecurso);
        self::assertSame('id', $c->doctrine->recursoId);
        self::assertSame('title', $c->doctrine->recursoTitulo);
    }

    public function testProviderNuloTambienApartaAlProviderDoctrine(): void
    {
        // `array_key_exists`, como antes: la clave basta aunque valga null.
        $c = ConfiguracionCalendario::fromArray(['provider' => null, 'entity' => 'X']);

        self::assertNull($c->provider);
        self::assertTrue($c->declaraProvider);
    }

    public function testLaEntidadVaciaSeConserva(): void
    {
        // `''` pasa `supports()` y la rechaza `assertConfig()` con su mensaje; `null` cambiaría el
        // error por «no hay provider».
        self::assertSame('', ConfiguracionCalendario::fromArray(['entity' => ''])->entidad);
        self::assertNull(ConfiguracionCalendario::fromArray(['entity' => ['x']])->entidad);
    }

    public function testConRetornoCopiaYAnadeLaClaveUnaSolaVez(): void
    {
        $base = ConfiguracionCalendario::fromArray(['provider' => 'x', 'fields' => ['start' => 'a']]);
        $conRetorno = $base->conRetorno('cGFnaW5h');

        self::assertNull($base->retorno, 'la configuración guardada no se toca');
        self::assertSame('cGFnaW5h', $conRetorno->retorno);
        self::assertSame(['provider', 'fields', 'runtime_returnTo'], $conRetorno->claves);
        self::assertSame($base->campos, $conRetorno->campos);
        self::assertSame(['provider', 'fields', 'runtime_returnTo'], $conRetorno->conRetorno('otra')->claves);
    }

    public function testElRetornoDelYamlSeLeeComoAntes(): void
    {
        self::assertSame('abc', ConfiguracionCalendario::fromArray(['runtime_returnTo' => 'abc'])->retorno);
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function rutas(): iterable
    {
        yield 'texto' => ['unidad.nombre', 'unidad.nombre'];
        yield 'vacía' => ['', null];
        yield 'número' => [5, null];
        yield 'lista' => [['a'], null];
        yield 'ausente' => [null, null];
    }

    #[DataProvider('rutas')]
    public function testUnaRutaDeCampoEsTextoNoVacioONada(mixed $valor, ?string $esperado): void
    {
        self::assertSame($esperado, CamposDeTarifa::fromArray(['start' => $valor])->start);
    }

    public function testCamposLeeLasDosFamilias(): void
    {
        $campos = CamposDeTarifa::fromArray([
            'resourceRoot' => 'unidad', 'resourceId' => 'unidad.id', 'resourceTitle' => 'unidad.nombre',
            'unit' => 'unidad', 'unitId' => 'unidad.id', 'unitTitle' => 'unidad.nombre',
            'start' => 'fechaInicio', 'end' => 'fechaFin', 'price' => 'precio', 'minStay' => 'minStay',
            'currency' => 'moneda.simbolo', 'active' => 'activo', 'important' => 'importante',
            'weight' => 'prioridad', 'id' => 'id',
        ]);

        self::assertSame('unidad', $campos->resourceRoot);
        self::assertSame('unidad.id', $campos->resourceId);
        self::assertSame('unidad.nombre', $campos->resourceTitle);
        self::assertSame('unidad', $campos->unit);
        self::assertSame('unidad.id', $campos->unitId);
        self::assertSame('unidad.nombre', $campos->unitTitle);
        self::assertSame('fechaInicio', $campos->start);
        self::assertSame('fechaFin', $campos->end);
        self::assertSame('precio', $campos->price);
        self::assertSame('minStay', $campos->minStay);
        self::assertSame('moneda.simbolo', $campos->currency);
        self::assertSame('activo', $campos->active);
        self::assertSame('importante', $campos->important);
        self::assertSame('prioridad', $campos->weight);
        self::assertSame('id', $campos->id);
    }

    /** @return iterable<string, array{mixed, list<string>, list<string>}> */
    public static function filtrosDeIds(): iterable
    {
        yield 'estructurado' => [['in' => ['confirmada'], 'not_in' => ['cancelada', 'extension']], ['confirmada'], ['cancelada', 'extension']];
        // Lo que hay en casi todos los calendarios: listas vacías NO filtran.
        yield 'estructurado vacío' => [['in' => [], 'not_in' => []], [], []];
        yield 'sólo not_in' => [['not_in' => ['extension']], [], ['extension']];
        yield 'not_in suelto' => [['in' => ['a'], 'not_in' => 'b'], ['a'], ['b']];
        yield 'lista plana = in' => [['confirmada', 'bloqueo'], ['confirmada', 'bloqueo'], []];
        yield 'un id suelto = in' => ['cancelada', ['cancelada'], []];
        yield 'ausente' => [null, [], []];
        yield 'vacío' => ['', [], []];
        yield '«0» como el empty() de antes' => ['0', [], []];
    }

    /**
     * @param list<string> $incluir
     * @param list<string> $excluir
     */
    #[DataProvider('filtrosDeIds')]
    public function testFiltroDeIdsAceptaLasFormasDelProvider(mixed $valor, array $incluir, array $excluir): void
    {
        $filtro = FiltroDeIds::desde($valor);

        self::assertSame($incluir, $filtro->incluir);
        self::assertSame($excluir, $filtro->excluir);
    }

    public function testFiltrosQueNoSonUnMapaSeMarcanParaQuienLosRechaza(): void
    {
        self::assertTrue(FiltrosDeCalendario::desde('x')->noEsMapa);
        self::assertFalse(FiltrosDeCalendario::desde(null)->noEsMapa);
        self::assertFalse(FiltrosDeCalendario::desde([])->noEsMapa);
    }

    public function testFiltrosDeTarifas(): void
    {
        $f = FiltrosDeCalendario::desde(['activeOnly' => true, 'showInactive' => true]);

        self::assertTrue($f->soloActivos);
        self::assertTrue($f->mostrarInactivos);
    }

    public function testElRolDeclaradoPeroIlegibleNoEsUnRolQueNoEsta(): void
    {
        // En estancias el rol es opcional: sin él, enlace para todos. Uno ilegible tiene que
        // seguir denegando, como hacía `isGranted()` al recibir la lista cruda.
        $ilegible = Enlace::fromArray(['route' => 'r', 'role' => ['ROLE_A', 'ROLE_B']]);
        self::assertNull($ilegible->rol);
        self::assertTrue($ilegible->rolDeclarado);

        $sinRol = Enlace::fromArray(['route' => 'r']);
        self::assertNull($sinRol->rol);
        self::assertFalse($sinRol->rolDeclarado);

        $nulo = Enlace::fromArray(['route' => 'r', 'role' => null]);
        self::assertFalse($nulo->rolDeclarado, '`isset()`, como el provider: un null no es un rol');
    }

    public function testEnlaceLeeRutaRolYParametros(): void
    {
        $e = Enlace::fromArray(['route' => 'panel_x', 'role' => 'ROLE_X', 'params' => ['tl' => 'es']]);

        self::assertSame('panel_x', $e->nombreRuta);
        self::assertSame('ROLE_X', $e->rol);
        self::assertSame(['tl' => 'es'], $e->parametros);
        self::assertSame([], Enlace::fromArray(['params' => 'x'])->parametros);
    }

    public function testEnlacesDeEventoGuardaSoloLosBloques(): void
    {
        $enlaces = EnlacesDeEvento::fromArray([
            'id' => 'unidad.id',
            'edit' => ['route' => 'e', 'role' => 'R'],
            'show' => 'no es un bloque',
        ]);

        self::assertSame('unidad.id', $enlaces->rutaId);
        self::assertSame('e', $enlaces->enlace('edit')?->nombreRuta);
        self::assertNull($enlaces->enlace('show'));
        self::assertNull($enlaces->enlace('reservaEdit'));
        self::assertNull(EnlacesDeEvento::fromArray([])->rutaId);
    }

    public function testElUrlDeLaRaizYElDelEventoSonDistintos(): void
    {
        $c = ConfiguracionCalendario::fromArray(['url' => ['show' => ['route' => 's']]]);

        self::assertNull($c->evento->enlaces);
        self::assertSame('s', $c->enlacesRaiz?->enlace('show')?->nombreRuta);
    }

    public function testOpcionesDeEvento(): void
    {
        $e = OpcionesDeEvento::desde([
            'includeCurrency' => false,
            'titleFormat' => '{price}',
            'priceDecimals' => 0,
            'tooltip' => ['unidad.nombre', 'precio'],
        ]);

        self::assertFalse($e->incluirMoneda);
        self::assertSame('{price}', $e->formatoTitulo);
        self::assertSame(0, $e->decimalesPrecio, 'un 0 explícito no es «ausente»');
        self::assertSame(['unidad.nombre', 'precio'], $e->tooltip);

        // Una lista vacía de líneas es «sin configurar», como el `!empty()` de antes.
        self::assertNull(OpcionesDeEvento::desde(['tooltip' => []])->tooltip);
        self::assertNull(OpcionesDeEvento::desde(['tooltip' => 'unidad.nombre'])->tooltip);
    }

    public function testHorasDeEvento(): void
    {
        $h = HorasDeEvento::desde(['start' => '14:30', 'end' => '10:00:00']);
        self::assertSame('14:30', $h->inicio);
        self::assertSame('10:00:00', $h->fin);

        // Sin `end`, el defecto es 11:59:59 aunque el YAML real ponga 11:59:00.
        self::assertSame('11:59:59', HorasDeEvento::desde(['start' => '12:00:00'])->fin);
        self::assertSame('12:00:00', HorasDeEvento::desde('x')->inicio);
    }

    public function testOpcionesDeCatalogo(): void
    {
        $o = OpcionesDeCatalogo::desde([
            'showAll' => false,
            'entity' => 'App\Pms\Entity\PmsUnidad',
            'titleField' => 'nombre',
            'activeField' => 'habilitado',
            'activeOnly' => true,
            'establecimientoField' => 'sede',
            'establecimientoId' => 'abc',
            'extraFields' => ['slug' => 'slug', 'raro' => ['x']],
        ]);

        self::assertFalse($o->mostrarTodos);
        self::assertSame('App\Pms\Entity\PmsUnidad', $o->entidad);
        self::assertSame('nombre', $o->campoTitulo);
        self::assertSame('habilitado', $o->campoActivo);
        self::assertTrue($o->soloActivos);
        self::assertSame('sede', $o->campoEstablecimiento);
        self::assertSame(['abc'], $o->establecimientoIds, 'un id suelto es una lista de uno, como el `(array)`');
        // Una ruta ilegible deja la clave (el front la ve a null), como el `(string)` de antes.
        self::assertSame(['slug' => 'slug', 'raro' => ''], $o->camposExtra);

        self::assertSame(['a', 'b'], OpcionesDeCatalogo::desde(['establecimientoId' => ['a', 'b']])->establecimientoIds);
        self::assertSame([], OpcionesDeCatalogo::desde(['establecimientoId' => null])->establecimientoIds);
    }

    public function testOpcionesDoctrineLegacy(): void
    {
        $d = OpcionesDoctrineLegacy::fromArray([
            'repositorymethod' => 'findParaCalendario',
            'parameters' => [
                'start' => 'inicio', 'end' => 'fin', 'id' => '', 'title' => 'descripcion',
                'textColor' => 'estado.nombre', 'backgroundColor' => 'estado.color',
                'borderColor' => 'a', 'color' => 'b', 'classNames' => 'c',
                'tooltip' => ['descripcion', 'referenciaCanal'],
                'url' => ['edit' => ['route' => 'e', 'role' => 'R']],
            ],
            'resource' => ['root' => 'pmsUnidad', 'title' => 'nombre'],
        ]);

        self::assertSame('findParaCalendario', $d->metodoRepositorio);
        self::assertSame('inicio', $d->inicio);
        self::assertSame('fin', $d->fin);
        self::assertSame('id', $d->id, 'un id vacío cae al de por defecto (el `?:` de antes)');
        self::assertSame('descripcion', $d->titulo);
        self::assertSame('estado.nombre', $d->colorTexto);
        self::assertSame('estado.color', $d->colorFondo);
        self::assertSame('a', $d->colorBorde);
        self::assertSame('b', $d->color);
        self::assertSame('c', $d->clases);
        self::assertSame(['descripcion', 'referenciaCanal'], $d->tooltip);
        self::assertSame('e', $d->enlaces?->enlace('edit')?->nombreRuta);
        self::assertTrue($d->conRecurso);
        self::assertSame('pmsUnidad', $d->recursoRaiz);
        self::assertSame('id', $d->recursoId);
        self::assertSame('nombre', $d->recursoTitulo);

        self::assertSame('referenciaCanal', OpcionesDoctrineLegacy::fromArray(['parameters' => ['tooltip' => 'referenciaCanal']])->tooltip);
        self::assertFalse(OpcionesDoctrineLegacy::fromArray(['resource' => []])->conRecurso, 'un bloque vacío es «sin recurso»');
        self::assertNull(OpcionesDoctrineLegacy::fromArray(['repositorymethod' => '0'])->metodoRepositorio);
    }
}
