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

    protected function setUp(): void
    {
        $this->nacional = (new CotizacionFileGrupo())->setId(Uuid::v4())->setNombre('Vuelo Nacional');
        $this->internacional = (new CotizacionFileGrupo())->setId(Uuid::v4())->setNombre('Vuelo Internacional');

        $this->servicio = new CotizacionCotservicio();
        $this->servicio->setCotizacion(new Cotizacion());

        foreach ([['LIM-PUJ nacional', $this->nacional], ['LIM-PUJ internacional', $this->internacional], ['Hotel Tambo', null]] as [$nombre, $grupo]) {
            $comp = (new CotizacionCotcomponente())->setGrupo($grupo);
            $comp->setNombreInternoSnapshot($nombre);
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
