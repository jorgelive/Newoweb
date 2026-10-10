<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Dto\CuerpoDeClonacion;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCatalogo;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo que toda copia arregla (`Cotizacion::duplicar()`) y lo que cambia al cambiar de padre
 * (`reubicarEnExpediente()`, `reubicarEnCatalogo()`). Ver docs/Cotizaciones.md, «Copiar una
 * cotización».
 *
 * Los cinco fallos que fija se encontraron el 07/10/2026 revisando cómo llevar una cotización de
 * expediente a un catálogo, y los cuatro primeros ya estaban en el clonado entre expedientes.
 */
final class CotizacionCopiaTest extends TestCase
{
    private CotizacionFile $expediente;
    private CotizacionFileGrupo $subgrupo;

    protected function setUp(): void
    {
        $this->expediente = new CotizacionFile();
        $this->subgrupo = new CotizacionFileGrupo();
    }

    /** Dos servicios con un componente cada uno; el segundo componente acotado a un subgrupo. */
    private function cotizacion(): Cotizacion
    {
        $c = new Cotizacion();
        $c->setFile($this->expediente);

        foreach (['2027-10-04', '2027-10-06'] as $i => $fecha) {
            $servicio = new CotizacionCotservicio();
            $servicio->setFechaInicioAbsoluta(new DateTimeImmutable($fecha));
            $componente = new CotizacionCotcomponente();
            if ($i === 1) {
                $componente->addGrupo($this->subgrupo);
            }
            $servicio->addCotcomponente($componente);
            $c->addCotservicio($servicio);
        }

        return $c;
    }

    /** @return list<CotizacionCotcomponente> */
    private function componentes(Cotizacion $c): array
    {
        $salida = [];
        foreach ($c->getCotservicios() as $s) {
            foreach ($s->getCotcomponentes() as $k) {
                $salida[] = $k;
            }
        }
        return $salida;
    }

    private function id(CotizacionCotcomponente|CotizacionCotservicio $x): string
    {
        return $x->getId()?->toRfc4122() ?? self::fail('sin id');
    }

    #[Test]
    public function la_copia_nace_sin_publicar_y_con_fecha_de_creacion_propia(): void
    {
        $original = $this->cotizacion();
        $original->setPublicado(true);
        $original->setCreatedAt(new DateTimeImmutable('2026-07-11 15:33:30'));

        $copia = $original->duplicar();

        self::assertFalse($copia->isPublicado(), 'Una copia publicada sale en el enlace del cliente antes de tocarla.');
        self::assertNull($copia->getCreatedAt(), 'La pone el prePersist; heredada, los históricos se ordenan al azar.');
        self::assertTrue($original->isPublicado(), 'El original no se toca.');
    }

    #[Test]
    public function los_destacados_apuntan_a_los_componentes_de_la_copia(): void
    {
        $original = $this->cotizacion();
        [$primero, $segundo] = $this->componentes($original);
        // El orden de la lista es el de la cabecera: se conserva.
        $original->setDestacadosComponenteIds([$this->id($segundo), $this->id($primero), '00000000-0000-7000-8000-000000000000']);

        $copia = $original->duplicar();
        [$primeroCopia, $segundoCopia] = $this->componentes($copia);

        self::assertSame(
            [$this->id($segundoCopia), $this->id($primeroCopia)],
            $copia->getDestacadosComponenteIds(),
            'Un id que no es de este árbol se descarta: apuntaría a otra cotización.',
        );
    }

    #[Test]
    public function las_inclusiones_del_cliente_apuntan_al_arbol_nuevo(): void
    {
        $original = $this->cotizacion();
        $servicio = $original->getCotservicios()->getValues()[0];
        $componente = $this->componentes($original)[0];
        $original->setClasificacionFinancieraCliente([
            'inclusiones' => [[
                'servicioId' => $this->id($servicio),
                'incluidos' => [['componenteId' => $this->id($componente), 'texto' => 'Traslado']],
            ]],
            'totalVentaBruta' => '100.00',
        ]);

        $copia = $original->duplicar();
        $bloque = $copia->getClasificacionFinancieraCliente() ?? self::fail('sin bloque');
        $inclusiones = $bloque['inclusiones'];
        self::assertIsArray($inclusiones);
        $linea = $inclusiones[0];
        self::assertIsArray($linea);

        self::assertSame($this->id($copia->getCotservicios()->getValues()[0]), $linea['servicioId']);
        $incluidos = $linea['incluidos'];
        self::assertIsArray($incluidos);
        self::assertIsArray($incluidos[0]);
        self::assertSame($this->id($this->componentes($copia)[0]), $incluidos[0]['componenteId']);
        self::assertSame('100.00', $bloque['totalVentaBruta'], 'El resto del blob se hereda tal cual.');
    }

    #[Test]
    public function las_opciones_del_cliente_apuntan_al_arbol_nuevo(): void
    {
        // `pax` cruza la opción de un servicio opcional con el itinerario para poner su precio en
        // la marca: con los ids del original, toda copia la enseñaba sin precio.
        $original = $this->cotizacion();
        $servicio = $original->getCotservicios()->getValues()[0];
        $componente = $this->componentes($original)[0];
        $original->setClasificacionFinancieraCliente([
            'inclusiones' => [],
            'opcionesUpgrade' => [[
                'servicioId' => $this->id($servicio),
                'componenteId' => $this->id($componente),
                'deltaVentaTotal' => 40,
            ]],
        ]);

        $copia = $original->duplicar();
        $bloque = $copia->getClasificacionFinancieraCliente() ?? self::fail('sin bloque');
        $opciones = $bloque['opcionesUpgrade'];
        self::assertIsArray($opciones);
        self::assertIsArray($opciones[0]);

        self::assertSame($this->id($copia->getCotservicios()->getValues()[0]), $opciones[0]['servicioId']);
        self::assertSame($this->id($this->componentes($copia)[0]), $opciones[0]['componenteId']);
        self::assertSame(40, $opciones[0]['deltaVentaTotal']);
    }

    #[Test]
    public function en_el_mismo_expediente_los_subgrupos_se_quedan(): void
    {
        $copia = $this->cotizacion()->duplicar();
        $copia->reubicarEnExpediente($this->expediente);

        self::assertCount(1, $this->componentes($copia)[1]->getGrupos());
    }

    #[Test]
    public function en_otro_expediente_los_subgrupos_se_vacian(): void
    {
        $original = $this->cotizacion();
        $copia = $original->duplicar();
        $copia->reubicarEnExpediente(new CotizacionFile());

        self::assertCount(0, $this->componentes($copia)[1]->getGrupos(), 'Son los PNR del otro grupo.');
        self::assertCount(1, $this->componentes($original)[1]->getGrupos(), 'El original conserva los suyos.');
    }

    #[Test]
    public function a_un_catalogo_pasa_a_ser_propuesta_generica(): void
    {
        $original = $this->cotizacion();
        $original->setFechaExpiracion(new DateTimeImmutable('2026-11-30'));
        $catalogo = new CotizacionCatalogo();

        $copia = $original->duplicar();
        $copia->reubicarEnCatalogo($catalogo, 5);

        self::assertNull($copia->getFile());
        self::assertSame($catalogo, $copia->getCatalogo());
        self::assertCount(0, $this->componentes($copia)[1]->getGrupos());
        self::assertTrue($copia->isTotalesOcultos(), 'El total de «pax base» no es vendible.');
        self::assertNull($copia->getFechaExpiracion());
        self::assertSame(5, $copia->getOrden());
        self::assertFalse($copia->isPublicado());
    }

    #[Test]
    public function de_un_catalogo_a_un_expediente_suelta_lo_del_catalogo(): void
    {
        $tour = new Cotizacion();
        $tour->setCatalogo(new CotizacionCatalogo());
        $tour->setTotalesOcultos(true);
        $tour->setPreciosDesde([['valor' => '69', 'moneda' => 'PEN']]);
        $tour->setOrden(3);

        $copia = $tour->duplicar();
        $copia->reubicarEnExpediente($this->expediente);

        self::assertNull($copia->getCatalogo());
        self::assertSame($this->expediente, $copia->getFile());
        self::assertFalse($copia->isTotalesOcultos(), 'Un expediente es un grupo concreto: su total se vende.');
        self::assertSame([], $copia->getPreciosDesde());
        self::assertSame(0, $copia->getOrden());
    }

    #[Test]
    public function un_null_no_suelta_el_catalogo_de_un_tour(): void
    {
        // Lo que mandaba el editor al guardar un tour con el catálogo incrustado (07/10/2026).
        $catalogo = new CotizacionCatalogo();
        $tour = (new Cotizacion())->setCatalogo($catalogo);

        $tour->setCatalogo(null);

        self::assertSame($catalogo, $tour->getCatalogo(), 'Quitar el padre no es una intención del editor.');
    }

    #[Test]
    public function el_cuerpo_acepta_catalogo_como_iri_o_uuid(): void
    {
        $uuid = '01a10c57-3ff7-7783-9380-badd2877e025';

        self::assertSame($uuid, CuerpoDeClonacion::fromArray(['catalogo' => '/platform/sales/cotizacion_catalogos/' . $uuid])->catalogoId);
        self::assertSame($uuid, CuerpoDeClonacion::fromArray(['catalogo' => $uuid])->catalogoId);
        self::assertNull(CuerpoDeClonacion::fromArray([])->catalogoId, 'Un cuerpo vacío sigue siendo «clona como siempre».');
    }
}
