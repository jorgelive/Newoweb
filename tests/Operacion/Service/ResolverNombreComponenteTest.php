<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Service;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Operacion\Service\BibliaSnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * De dónde sale el nombre que ve el tráfico. **Del snapshot, y de ningún otro sitio.**
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * El nombre operativo del componente se resolvía **en vivo** contra el catálogo, en el mismo lote
 * que las etiquetas de lugar — un lote cuyo `catch` lo vacía entero porque «los badges son
 * decoración». Cuando esa petición fallaba, la ficha caía al respaldo y **enseñaba el nombre del
 * itinerario como si fuera el servicio**. No dejaba un hueco: dejaba otro nombre, y se leía
 * plausible, que es la peor forma de perder un dato.
 *
 * Desde el 27/08/2026 el operativo se copia al snapshot al añadir el componente —igual que ya
 * hacían servicio y tarifa— y aquí sólo se lee. Estos casos fijan las dos mitades de eso: qué gana
 * a qué, y que **el catálogo no se toca**.
 *
 * ⚠️ Todos pasan un EntityManager que **estalla si alguien lo usa**. No es adorno: es la única
 * forma de comprobar que la ruta es de verdad única. Sin él, reintroducir una consulta al maestro
 * pasaría los tests sin despeinarse.
 */
final class ResolverNombreComponenteTest extends TestCase
{
    private function servicio(): BibliaSnapshotService
    {
        return new BibliaSnapshotService($this->emProhibido());
    }

    /**
     * Un EntityManager que no debe usarse: la resolución no consulta el catálogo.
     *
     * Se vetan las CUATRO puertas, no sólo `getRepository()`. Con una sola vetada, reintroducir la
     * consulta por `find()` o por `createQueryBuilder()` devolvería `null` del mock y los cuatro
     * casos seguirían en verde — la garantía se vería intacta sin serlo.
     */
    private function emProhibido(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);

        foreach (['getRepository', 'find', 'createQueryBuilder', 'createQuery'] as $puerta) {
            $em->expects($this->never())->method($puerta);
        }

        return $em;
    }

    public function testMandaElNombreOperativoDelSnapshot(): void
    {
        $componente = (new CotizacionCotcomponente())
            ->setNombreInternoSnapshot('Transporte Aeropuerto Cusco - Hotel Cusco')
            // Tiene maestro, y da igual: no se va a preguntar.
            ->setComponenteMaestroId('01a04375-6bd2-7b02-86f8-097e45cb37bd')
            // Y el público es genérico: si ganara, la ficha diría «Transporte» a secas.
            ->setTituloSnapshot([['language' => 'es', 'content' => 'Transporte']]);

        self::assertSame(
            'Transporte Aeropuerto Cusco - Hotel Cusco',
            $this->servicio()->resolverNombreComponente($componente)
        );
    }

    public function testSinOperativoCaeAlTituloYNoDejaLaFilaSinNombre(): void
    {
        $componente = (new CotizacionCotcomponente())
            ->setComponenteMaestroId('01a04375-6bd2-7b02-86f8-097e45cb37bd')
            ->setTituloSnapshot([
                ['language' => 'en', 'content' => 'Flight'],
                ['language' => 'es', 'content' => 'Ticket aereo'],
            ]);

        self::assertSame('Ticket aereo', $this->servicio()->resolverNombreComponente($componente));
    }

    public function testElOperativoEnBlancoNoCuentaComoDecision(): void
    {
        // Espacios sueltos son un campo vacío, no un nombre. Sin el `trim` la fila se quedaría
        // llamándose «   » y el título no llegaría a mirarse nunca.
        $componente = (new CotizacionCotcomponente())
            ->setNombreInternoSnapshot('   ')
            ->setTituloSnapshot([['language' => 'es', 'content' => 'Ticket aereo']]);

        self::assertSame('Ticket aereo', $this->servicio()->resolverNombreComponente($componente));
    }

    public function testSinNadaDevuelveNullYNoInventa(): void
    {
        // Null y no un genérico: quien llama decide qué poner —el tipo, en el cuadro— y aquí
        // inventarse un texto lo dejaría sin saber que no había nombre.
        self::assertNull($this->servicio()->resolverNombreComponente(new CotizacionCotcomponente()));
    }
}
