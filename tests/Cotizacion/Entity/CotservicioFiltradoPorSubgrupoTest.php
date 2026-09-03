<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Cada uno ve lo suyo — y el resto no sale del servidor.
 */
final class CotservicioFiltradoPorSubgrupoTest extends TestCase
{
    private CotizacionFileGrupo $nacional;
    private CotizacionFileGrupo $internacional;
    private CotizacionCotservicio $servicio;

    private \App\Cotizacion\Entity\CotizacionSegmento $segNacional;
    private \App\Cotizacion\Entity\CotizacionSegmento $segHotel;

    protected function setUp(): void
    {
        $this->nacional = (new CotizacionFileGrupo())->setId(Uuid::v4())->setNombre('Vuelo Nacional');
        $this->internacional = (new CotizacionFileGrupo())->setId(Uuid::v4())->setNombre('Vuelo Internacional');

        $this->servicio = new CotizacionCotservicio();
        $this->servicio->setCotizacion(new Cotizacion());

        // Dos segmentos: el del vuelo —que se parte en dos componentes— y el del hotel.
        $this->segNacional = new \App\Cotizacion\Entity\CotizacionSegmento();
        $this->segHotel = new \App\Cotizacion\Entity\CotizacionSegmento();
        $this->servicio->getCotsegmentos()->add($this->segNacional);
        $this->servicio->getCotsegmentos()->add($this->segHotel);

        foreach ([
            ['LIM-PUJ nacional', $this->nacional, $this->segNacional],
            ['LIM-PUJ internacional', $this->internacional, $this->segNacional],
            ['Hotel Tambo', null, $this->segHotel],
        ] as [$nombre, $grupo, $segmento]) {
            $comp = new CotizacionCotcomponente();

            if ($grupo !== null) {
                $comp->addGrupo($grupo);
            }
            $comp->setNombreInternoSnapshot($nombre);
            $comp->setCotsegmento($segmento);
            $this->servicio->getCotcomponentes()->add($comp);
        }
    }

    /** @return list<string|null> */
    private function nombresServidos(): array
    {
        return array_values(array_map(
            static fn (CotizacionCotcomponente $c): ?string => $c->getNombreInternoSnapshot(),
            $this->servicio->getCotcomponentesParaCliente()->toArray(),
        ));
    }

    public function testSinFiltroSeSirveTodo(): void
    {
        // `null` = no se preguntó: el operador, un catálogo, un expediente individual.
        self::assertNull($this->servicio->getCotizacion()?->getFiltroSubgrupos());
        self::assertCount(3, $this->nombresServidos());
    }

    public function testSoloLoSuyoMasLoDeTodos(): void
    {
        $this->servicio->getCotizacion()?->setFiltroSubgrupos([$this->nacional->getId()?->toRfc4122() ?? '']);

        self::assertSame(['LIM-PUJ nacional', 'Hotel Tambo'], $this->nombresServidos());
    }

    public function testListaVaciaNoEsLoMismoQueNull(): void
    {
        // Se filtró y esta persona no está en ningún subgrupo: ve lo general y nada acotado.
        // Confundirla con `null` le enseñaría el expediente entero.
        $this->servicio->getCotizacion()?->setFiltroSubgrupos([]);

        self::assertSame(['Hotel Tambo'], $this->nombresServidos());
    }

    public function testUnSegmentoQueSeQuedaSinComponentesNoSeSirve(): void
    {
        // 🔥 El relato vive en el SEGMENTO, y `componerItinerario()` pinta un bloque por segmento
        // aunque no le quede ningún componente. Sin esto, quien no va en ningún vuelo leía el
        // relato completo del vuelo: no es una fuga, es peor — le dice que hace algo que no hace.
        $this->servicio->getCotizacion()?->setFiltroSubgrupos([]);

        $servidos = $this->servicio->getCotsegmentosParaCliente();

        self::assertCount(1, $servidos, 'el segmento del vuelo debía caerse: no le queda nada suyo');
        self::assertSame($this->segHotel, $servidos->first());
    }

    public function testUnSegmentoQueNUNCATuvoComponentesSeQueda(): void
    {
        // ⚠️ Es legítimo —así se trabaja en el editor mientras se arma— y esconderlo cambiaría lo
        // que ve el cliente sin que nadie lo haya filtrado. Sólo se cae el que TENÍA y perdió.
        $vacio = new \App\Cotizacion\Entity\CotizacionSegmento();
        $this->servicio->getCotsegmentos()->add($vacio);

        $this->servicio->getCotizacion()?->setFiltroSubgrupos([]);

        self::assertTrue($this->servicio->getCotsegmentosParaCliente()->contains($vacio));
    }

    public function testSinFiltroSeSirvenTodosLosSegmentos(): void
    {
        self::assertCount(2, $this->servicio->getCotsegmentosParaCliente());
    }

    public function testBastaConPertenecerAUNODeLosSubgruposDelComponente(): void
    {
        // 🔥 El caso que trajo el plural: el vuelo JA7018 lleva 7 PNRs. Un componente acotado a
        // varios lo ve quien esté en CUALQUIERA de ellos — la lista dice «a quiénes aplica», no
        // «a quién hay que pertenecer entero». Exigir todos lo escondería de todo el mundo.
        $comp = new CotizacionCotcomponente();
        $comp->setNombreInternoSnapshot('Vuelo compartido');
        $comp->addGrupo($this->nacional);
        $comp->addGrupo($this->internacional);
        $comp->setCotsegmento($this->segNacional);
        $this->servicio->getCotcomponentes()->add($comp);

        $this->servicio->getCotizacion()?->setFiltroSubgrupos([$this->internacional->getId()?->toRfc4122() ?? '']);

        self::assertContains('Vuelo compartido', $this->nombresServidos());
    }

    public function testFiltrarNoVaciaLaColeccionORIGINAL(): void
    {
        $this->servicio->getCotizacion()?->setFiltroSubgrupos([]);
        $this->servicio->getCotcomponentesParaCliente();

        // 🔥 Lo que de verdad vigila esta prueba. `$cotcomponentes` tiene `orphanRemoval: true`:
        // si el filtrado quitara elementos de la colección real en vez de devolver una nueva, el
        // siguiente flush BORRARÍA esos componentes. El cliente miraría su itinerario y la
        // cotización perdería la mitad. No daría error: borraría.
        self::assertCount(3, $this->servicio->getCotcomponentes());
    }
}
