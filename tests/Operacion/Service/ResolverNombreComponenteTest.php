<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Service;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Travel\Entity\TravelComponente;
use Doctrine\Persistence\ObjectRepository;
use App\Operacion\Service\BibliaSnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * De quién es el nombre que ve el tráfico: del operador, del catálogo o de la copia congelada.
 *
 * ── Por qué existe este test ────────────────────────────────────────────────
 * La precedencia estuvo invertida —el maestro por delante de lo escrito a mano— y **no lo detectó
 * nada**: ningún componente en producción tenía todavía el nombre manual relleno, así que el
 * backfill recalculó las 47 filas y cambió cero. El fallo era latente y habría mordido la primera
 * vez que alguien renombrara un componente a mano, enseñando el nombre de la plantilla en su
 * lugar. Y como ese nombre se lee perfectamente bien, nadie lo habría echado de menos.
 *
 * O sea: es una regla que los datos de hoy no pueden verificar. Por eso se fija aquí.
 *
 * ⚠️ El caso 1 pasa un EntityManager que **estalla si lo tocan**. No es rebuscado: es la única
 * forma de comprobar que lo escrito a mano gana de verdad y no «gana porque el maestro resultó
 * estar vacío». Si alguien reordena las ramas, este test falla en vez de pasar por casualidad.
 */
final class ResolverNombreComponenteTest extends TestCase
{
    private function servicio(EntityManagerInterface $em): BibliaSnapshotService
    {
        return new BibliaSnapshotService($em);
    }

    /** Un EntityManager que no debería usarse nunca en este camino. */
    private function emProhibido(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())
            ->method('getRepository');

        return $em;
    }

    public function testLoEscritoAManoGanaAlMaestro(): void
    {
        $componente = (new CotizacionCotcomponente())
            ->setNombreInternoSnapshot('Traslado a la Olla de Juanita')
            // Hay maestro, y aun así no debe consultarse: la decisión ya está tomada.
            ->setComponenteMaestroId('01a04375-6bd2-7b02-86f8-097e45cb37bd')
            ->setTituloSnapshot([['language' => 'es', 'content' => 'Transporte']]);

        self::assertSame(
            'Traslado a la Olla de Juanita',
            $this->servicio($this->emProhibido())->resolverNombreComponente($componente)
        );
    }

    public function testElManualEnBlancoNoCuentaComoDecision(): void
    {
        // Espacios sueltos son un campo vacío, no un nombre. Sin el `trim` la fila se quedaría
        // llamándose «   » y el maestro no llegaría a consultarse nunca.
        $componente = (new CotizacionCotcomponente())
            ->setNombreInternoSnapshot('   ')
            ->setTituloSnapshot([['language' => 'es', 'content' => 'Ticket aereo']]);

        self::assertSame(
            'Ticket aereo',
            $this->servicio($this->emProhibido())->resolverNombreComponente($componente)
        );
    }

    public function testSinManualNiMaestroCaeALaCopiaCongelada(): void
    {
        $componente = (new CotizacionCotcomponente())
            ->setTituloSnapshot([
                ['language' => 'en', 'content' => 'Flight'],
                ['language' => 'es', 'content' => 'Ticket aereo'],
            ]);

        self::assertSame(
            'Ticket aereo',
            $this->servicio($this->emProhibido())->resolverNombreComponente($componente)
        );
    }

    /**
     * Un EntityManager que devuelve ESE maestro para `TravelComponente`.
     *
     * @param TravelComponente|null $maestro null simula un maestro borrado del catálogo
     */
    private function emConMaestro(?TravelComponente $maestro): EntityManagerInterface
    {
        // La INTERFAZ y no `EntityRepository`: simular la clase concreta está deprecado en
        // PHPUnit 13, y aquí sólo hace falta `find()`. `getRepository()` no declara tipo de
        // retorno en `EntityManagerInterface`, así que la interfaz encaja.
        // `createStub` y no `createMock`: aquí no se verifica ninguna llamada, sólo se le da al
        // resolutor un catálogo del que tirar. PHPUnit 13 avisa —y con `failOnNotice` hace
        // FALLAR— si se usa un mock sin expectativas. `emProhibido()` sí es un mock, porque su
        // `expects($this->never())` es justamente la expectativa que da valor a aquel caso.
        $repo = $this->createStub(ObjectRepository::class);
        $repo->method('find')->willReturn($maestro);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return $em;
    }

    public function testSinManualMandaElNombreOperativoDelMaestro(): void
    {
        // ⚠️ Ésta es la rama que el renombrado de agosto tocó de verdad —`getNombre()` pasó a
        // `getNombreInterno()`— y era la única que ningún caso ejecutaba: todos pasaban un EM que
        // estallaba si lo tocaban. PHPStan la cubría porque el método existe; que devuelva el
        // valor correcto no lo comprobaba nadie.
        $maestro = (new TravelComponente())->setNombreInterno('Transporte Aeropuerto Cusco - Hotel Cusco');

        $componente = (new CotizacionCotcomponente())
            ->setComponenteMaestroId('01a04375-6bd2-7b02-86f8-097e45cb37bd')
            // El público es genérico: si ganara, la ficha diría «Transporte» a secas.
            ->setTituloSnapshot([['language' => 'es', 'content' => 'Transporte']]);

        self::assertSame(
            'Transporte Aeropuerto Cusco - Hotel Cusco',
            $this->servicio($this->emConMaestro($maestro))->resolverNombreComponente($componente)
        );
    }

    public function testMaestroBorradoCaeAlTituloYNoDejaLaFilaSinNombre(): void
    {
        $componente = (new CotizacionCotcomponente())
            ->setComponenteMaestroId('01a04375-6bd2-7b02-86f8-097e45cb37bd')
            ->setTituloSnapshot([['language' => 'es', 'content' => 'Ticket aereo']]);

        self::assertSame(
            'Ticket aereo',
            $this->servicio($this->emConMaestro(null))->resolverNombreComponente($componente)
        );
    }

    public function testMaestroSinNombreTampocoGana(): void
    {
        // Un maestro a medio crear no debe secuestrar la fila con una cadena vacía.
        $componente = (new CotizacionCotcomponente())
            ->setComponenteMaestroId('01a04375-6bd2-7b02-86f8-097e45cb37bd')
            ->setTituloSnapshot([['language' => 'es', 'content' => 'Ticket aereo']]);

        self::assertSame(
            'Ticket aereo',
            $this->servicio($this->emConMaestro(new TravelComponente()))->resolverNombreComponente($componente)
        );
    }

    public function testSinNadaDevuelveNullYNoInventa(): void
    {
        // Que devuelva null y no un genérico: quien llama decide qué poner —el tipo, en el cuadro—
        // y aquí inventarse un texto lo dejaría sin saber que no había nombre.
        self::assertNull(
            $this->servicio($this->emProhibido())->resolverNombreComponente(new CotizacionCotcomponente())
        );
    }
}
