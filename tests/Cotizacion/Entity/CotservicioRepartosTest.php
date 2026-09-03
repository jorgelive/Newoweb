<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El reparto de un servicio entre subgrupos: un original y sus copias.
 *
 * ⚠️ Esto es lo que un `esDuplicado` sí/no **no podía calcular**: dos copias en el mismo servicio
 * se saben copias, pero no de cuál, así que no hay conjunto que sumar. Y sin sumar no se ve el
 * fallo que importa — que las partes de un vuelo cubran 35 de 40 personas.
 */
final class CotservicioRepartosTest extends TestCase
{
    private function componente(string $nombre, int $cantidad, ?string $copiaDe = null): CotizacionCotcomponente
    {
        $c = new CotizacionCotcomponente();
        $c->setNombreInternoSnapshot($nombre);
        $c->setCantidad($cantidad);
        $c->setDuplicadoDe($copiaDe);

        // El id lo pone Doctrine; aquí hace falta para agrupar.
        (new \ReflectionProperty(CotizacionCotcomponente::class, 'id'))->setValue($c, Uuid::v4());

        return $c;
    }

    private function servicio(CotizacionCotcomponente ...$componentes): CotizacionCotservicio
    {
        $s = new CotizacionCotservicio();

        foreach ($componentes as $c) {
            $s->getCotcomponentes()->add($c);
        }

        return $s;
    }

    public function testUnServicioSinPartirNoEsUnReparto(): void
    {
        // Devolver «un reparto de uno» obligaría a todos los llamadores a filtrar lo mismo.
        self::assertSame([], $this->servicio($this->componente('Vuelo', 40))->repartos());
    }

    public function testUnOriginalConSuCopiaSumaLasDosPartes(): void
    {
        $original = $this->componente('Vuelo LIM-PUJ', 22);
        $copia = $this->componente('Vuelo LIM-PUJ', 18, $original->getId()?->toRfc4122());

        $repartos = $this->servicio($original, $copia)->repartos();

        self::assertCount(1, $repartos);
        self::assertSame(40, $repartos[0]['cantidad']);
        self::assertSame(2, $repartos[0]['partes']);
        self::assertSame('Vuelo LIM-PUJ', $repartos[0]['titulo']);
    }

    public function testDosCopiasDelMISMOOriginalSonUnSoloReparto(): void
    {
        $original = $this->componente('Vuelo', 10);
        $raiz = $original->getId()?->toRfc4122();

        $repartos = $this->servicio(
            $original,
            $this->componente('Vuelo', 12, $raiz),
            $this->componente('Vuelo', 18, $raiz),
        )->repartos();

        self::assertCount(1, $repartos);
        self::assertSame(40, $repartos[0]['cantidad']);
        self::assertSame(3, $repartos[0]['partes']);
    }

    public function testDosRepartosDISTINTOSNoSeMezclan(): void
    {
        // 🔥 Lo que un booleano no podía distinguir: cuatro componentes, todos «copias» menos dos,
        // y sin saber de cuál sale cada uno se sumarían los cuatro en un solo montón.
        $vuelo = $this->componente('Vuelo', 22);
        $bus = $this->componente('Bus', 5);

        $repartos = $this->servicio(
            $vuelo,
            $this->componente('Vuelo', 18, $vuelo->getId()?->toRfc4122()),
            $bus,
            $this->componente('Bus', 3, $bus->getId()?->toRfc4122()),
        )->repartos();

        self::assertCount(2, $repartos);
        self::assertSame([40, 8], array_column($repartos, 'cantidad'));
    }

    public function testClonarElSERVICIOReapuntaElVinculoALasCopiasNUEVAS(): void
    {
        $original = $this->componente('Vuelo', 22);
        $copia = $this->componente('Vuelo', 18, $original->getId()?->toRfc4122());
        $clon = $this->servicio($original, $copia)->duplicar();

        $comps = $clon->getCotcomponentes()->toArray();
        $ids = array_map(static fn (CotizacionCotcomponente $c): ?string => $c->getId()?->toRfc4122(), $comps);
        $vinculos = array_values(array_filter(array_map(
            static fn (CotizacionCotcomponente $c): ?string => $c->getDuplicadoDe(),
            $comps,
        )));

        // 🔥 Lo que vigila esta prueba: sin remapear, la copia del clon seguiría apuntando al
        // componente de la cotización ORIGINAL. Un vínculo que cruza cotizaciones no da ningún
        // error — sólo agrupa mal, en silencio, para siempre.
        self::assertCount(1, $vinculos);
        self::assertContains($vinculos[0], $ids, 'el vínculo apunta fuera del clon');
        self::assertNotSame($original->getId()?->toRfc4122(), $vinculos[0]);

        // Y el reparto sigue siendo UNO de dos partes, no dos sueltos.
        self::assertCount(1, $clon->repartos());
        self::assertSame(40, $clon->repartos()[0]['cantidad']);
    }

    public function testUnaCopiaHUERFANAcuentaComoSuPropioOriginal(): void
    {
        // Si el original desapareció, la copia no arrastra a nadie a un montón equivocado: forma
        // su propio grupo de uno, que por la regla de arriba no es un reparto.
        $copia = $this->componente('Vuelo', 18, Uuid::v4()->toRfc4122());

        self::assertSame([], $this->servicio($copia)->repartos());
    }
}
