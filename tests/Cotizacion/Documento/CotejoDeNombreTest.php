<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Cotizacion\Documento\CotejoDeNombre;
use PHPUnit\Framework\TestCase;

/**
 * La detección de dedazos en el nombre de un trámite.
 *
 * Los dos primeros casos son reales: hubo que avisar a mano de un `Ascarsa` por `Ascarza` y un
 * `juaquin` por `joaquin`. Los de «no acusar» salen de los ocho primeros E-Ticket de producción,
 * donde una comparación ingenua habría acusado a dos.
 */
final class CotejoDeNombreTest extends TestCase
{
    public function testCazaElDedazoDelApellido(): void
    {
        $c = CotejoDeNombre::de('JOAQUIN ASCARSA VIVANCO', 'JOAQUIN ASCARZA VIVANCO');

        self::assertTrue($c->hayDedazo());
        self::assertSame('ASCARSA (debería ser ASCARZA)', $c->comoSeLee());
    }

    public function testCazaElDedazoDelNombre(): void
    {
        $c = CotejoDeNombre::de('JUAQUIN ASCARZA', 'JOAQUIN ASCARZA');

        self::assertTrue($c->hayDedazo());
        self::assertSame('JUAQUIN (debería ser JOAQUIN)', $c->comoSeLee());
    }

    /**
     * 🔥 Caso real: el formulario dominicano no admite la eñe. `ACUNA` y `ACUÑA` son la misma
     * persona y acusar de eso sería acusar a medio grupo peruano.
     */
    public function testLaEnyeNoEsUnDedazo(): void
    {
        $c = CotejoDeNombre::de('SANTIAGO ARIEL GOMEZ ACUNA', 'SANTIAGO ARIEL GOMEZ ACUÑA');

        self::assertFalse($c->hayDedazo());
        self::assertSame([], $c->sobran);
        self::assertSame([], $c->faltan);
    }

    /** Las tildes tampoco. */
    public function testLasTildesTampoco(): void
    {
        self::assertFalse(CotejoDeNombre::de('JOSE PEREZ NUNEZ', 'José Pérez Núñez')->hayDedazo());
    }

    /**
     * 🔥 Caso real: al MANIFIESTO le faltaba «ANTONIO» y el trámite lo traía bien. Un nombre de más
     * NO se acusa — tiene mil explicaciones inocentes y llenaría la hoja de ruido.
     */
    public function testUnNombreDeMasNoSeAcusa(): void
    {
        $c = CotejoDeNombre::de('MATHEO ANTONIO GAMARRA ZANABRIA', 'MATHEO GAMARRA ZANABRIA');

        self::assertFalse($c->hayDedazo());
        self::assertSame(['ANTONIO'], $c->sobran);
    }

    /** Y uno de menos tampoco: el formulario corta, o no usa el segundo nombre. */
    public function testUnNombreDeMenosTampocoSeAcusa(): void
    {
        $c = CotejoDeNombre::de('MATHEO GAMARRA ZANABRIA', 'MATHEO ANTONIO GAMARRA ZANABRIA');

        self::assertFalse($c->hayDedazo());
        self::assertSame(['ANTONIO'], $c->faltan);
    }

    /** El orden no importa: el formulario dominicano mete todo en un campo. */
    public function testElOrdenNoImporta(): void
    {
        self::assertFalse(CotejoDeNombre::de('PAZ RAMOS DAMARIS LUCIANA', 'DAMARIS LUCIANA PAZ RAMOS')->hayDedazo());
    }

    /**
     * ⚠️ Un apellido distinto de verdad no es un dedazo: se dice que sobra, no que se corrija una
     * letra. Acusar de «dedazo» algo que es otra persona manda a corregir lo que no se corrige.
     */
    public function testUnApellidoCompletamenteDistintoNoEsDedazo(): void
    {
        $c = CotejoDeNombre::de('JOAQUIN MENDOZA', 'JOAQUIN ASCARZA');

        self::assertFalse($c->hayDedazo());
        self::assertSame(['MENDOZA'], $c->sobran);
    }

    /** ⚠️ Palabras cortas: `ANA` y `ANO` están a un cambio y no son la misma persona. */
    public function testLasPalabrasCortasNoSeEmparejanPorUnaLetra(): void
    {
        self::assertFalse(CotejoDeNombre::de('ANA TORRES', 'ANO TORRES')->hayDedazo());
    }

    public function testSinNadaQueCompararNoSeDiceNada(): void
    {
        self::assertFalse(CotejoDeNombre::de(null, 'JOAQUIN ASCARZA')->hayDedazo());
        self::assertFalse(CotejoDeNombre::de('JOAQUIN ASCARZA', null)->hayDedazo());
    }
}
