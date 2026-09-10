<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Cotizacion\Documento\Mrz;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La MRZ es la única parte de la extracción que se puede comprobar SIN pedirle nada a nadie, así
 * que es la única que puede tener tests de verdad en una suite sin base de datos ni red.
 *
 * El ejemplar es el de la especificación ICAO 9303 (Utopía / ERIKSSON), que trae los dígitos de
 * control ya calculados: si la cuenta de aquí no reproduce los suyos, la cuenta está mal.
 */
final class MrzTest extends TestCase
{
    private const L1 = 'P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<';
    private const L2 = 'L898902C36UTO7408122F1204159ZE184226B<<<<<10';

    #[Test]
    public function leeLosCamposDeUnPasaporteReal(): void
    {
        $mrz = Mrz::desde(self::L1, self::L2);

        self::assertNotNull($mrz);
        self::assertSame('L898902C3', $mrz->numero);
        self::assertSame('UTO', $mrz->paisEmisor);
        self::assertSame('UTO', $mrz->nacionalidad);
        self::assertSame('ERIKSSON', $mrz->apellidos);
        self::assertSame('ANNA MARIA', $mrz->nombres);
        self::assertSame('F', $mrz->sexo);
        self::assertSame('1974-08-12', $mrz->nacimiento?->format('Y-m-d'));
        self::assertSame('2012-04-15', $mrz->vencimiento?->format('Y-m-d'));
    }

    #[Test]
    public function elEjemplarDeLaEspecificacionCuadraEntero(): void
    {
        self::assertTrue(Mrz::desde(self::L1, self::L2)?->esCoherente());
    }

    /**
     * El caso que justifica todo esto: un dígito mal leído se caza con aritmética.
     *
     * `L898902C3` → `L898902C8`. El número sigue teniendo la forma de un número de pasaporte y
     * ningún ojo humano lo notaría comparándolo con el escaneo.
     */
    #[Test]
    public function unDigitoMalLeidoEnElNumeroNoCuadra(): void
    {
        $mrz = Mrz::desde(self::L1, str_replace('L898902C36', 'L898902C86', self::L2));

        self::assertNotNull($mrz);
        self::assertFalse($mrz->esCoherente());
        self::assertContains('número de documento', $mrz->problemas);
    }

    #[Test]
    public function unaFechaDeVencimientoMalLeidaNoCuadra(): void
    {
        // 120415 → 120416: un día de diferencia, y el dígito de control lo delata.
        $mrz = Mrz::desde(self::L1, str_replace('1204159', '1204169', self::L2));

        self::assertNotNull($mrz);
        self::assertContains('fecha de vencimiento', $mrz->problemas);
    }

    /** Un nacimiento de dos dígitos cae en el siglo pasado, no en el que viene. */
    #[Test]
    public function elAnoDeNacimientoNoSeVaAlFuturo(): void
    {
        $mrz = Mrz::desde(self::L1, self::L2);

        self::assertSame(1974, (int) $mrz?->nacimiento?->format('Y'));
    }

    /** Una fecha imposible es NADA, no una fecha corrida tres días. */
    #[Test]
    public function unaFechaImposibleNoSeCorrigeSola(): void
    {
        // 740812 → 740230: el 30 de febrero. PHP lo movería al 2 de marzo sin decir nada.
        $mrz = Mrz::desde(self::L1, str_replace('7408122F', '7402302F', self::L2));

        self::assertNotNull($mrz);
        self::assertNull($mrz->nacimiento);
    }

    /** Un TD1 se rechaza en vez de leerse por las posiciones equivocadas. */
    #[Test]
    public function noSeInventaNadaConUnFormatoQueNoEsTd3(): void
    {
        self::assertNull(Mrz::desde('I<UTOD231458907<<<<<<<<<<<<<<<', '7408122F1204159UTO<<<<<<<<<<<6'));
    }

    #[Test]
    public function toleraLosEspaciosYLosSimbolosQueMeteUnOcr(): void
    {
        $mrz = Mrz::desde(self::L1, 'L898902C3 6UTO7408122F1204159ZE184226B«««««10');

        self::assertTrue($mrz?->esCoherente());
    }

    /* ══ TD1: el DNI peruano nuevo, que SÍ lleva banda en el anverso ══════════ */

    // Tomado de un escaneo real del expediente (Rodrigo Gonzalo Cuellar Farfán).
    private const D1 = 'I<PER73724061<6<<<<<<<<<<<<<<<';
    private const D2 = '0908302M2608307PER<<<<<<<<<<<8';
    private const D3 = 'CUELLAR<<RODRIGO<GONZALO<<<<<<';

    #[Test]
    public function leeUnDniPeruanoDeTresLineas(): void
    {
        $mrz = Mrz::desde(self::D1, self::D2, self::D3);

        self::assertNotNull($mrz);
        self::assertSame('73724061', $mrz->numero);
        self::assertSame('PER', $mrz->paisEmisor);
        self::assertSame('PER', $mrz->nacionalidad);
        self::assertSame('CUELLAR', $mrz->apellidos);
        self::assertSame('RODRIGO GONZALO', $mrz->nombres);
        self::assertSame('M', $mrz->sexo);
        self::assertSame('2009-08-30', $mrz->nacimiento?->format('Y-m-d'));
        self::assertSame('2026-08-30', $mrz->vencimiento?->format('Y-m-d'));
    }

    /** Los dígitos del número y de las dos fechas tienen que cuadrar, como en el pasaporte. */
    #[Test]
    public function losDigitosDelDniCuadran(): void
    {
        $mrz = Mrz::desde(self::D1, self::D2, self::D3);

        self::assertNotContains('número de documento', $mrz?->problemas ?? ['x']);
        self::assertNotContains('fecha de nacimiento', $mrz?->problemas ?? ['x']);
        self::assertNotContains('fecha de vencimiento', $mrz?->problemas ?? ['x']);
    }

    /** Y un dígito mal leído en el número del DNI se caza igual que en un pasaporte. */
    #[Test]
    public function unDigitoMalLeidoEnElDniNoCuadra(): void
    {
        $mrz = Mrz::desde(str_replace('73724061<6', '73724061<9', self::D1), self::D2, self::D3);

        self::assertContains('número de documento', $mrz?->problemas ?? []);
    }

    /** El formato se decide por la LONGITUD: dos líneas de 30 no son ni un TD1 ni un TD3. */
    #[Test]
    public function dosLineasDeTreintaNoSonNingunFormato(): void
    {
        self::assertNull(Mrz::desde(self::D1, self::D2));
    }

    /**
     * Y un pasaporte con una tercera línea de sobra se sigue leyendo como pasaporte.
     *
     * ⚠️ Esto empezó siendo una expectativa mía equivocada —esperaba `null`— y el código tenía
     * razón: al modelo se le pide SIEMPRE la tercera línea, así que en un pasaporte llega vacía o
     * con lo que haya pillado. Rechazar el TD3 por eso tiraría una banda perfectamente buena.
     */
    #[Test]
    public function unaTerceraLineaDeSobraNoEstropeaUnPasaporte(): void
    {
        $mrz = Mrz::desde(self::L1, self::L2, self::D3);

        self::assertSame('L898902C3', $mrz?->numero);
        self::assertTrue($mrz?->esCoherente());
    }
}
