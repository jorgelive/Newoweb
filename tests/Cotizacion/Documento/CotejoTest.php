<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Cotizacion\Documento\Cotejo;
use App\Cotizacion\Documento\DatosDeDocumento;
use App\Cotizacion\Documento\FichaGuardada;
use App\Cotizacion\Documento\Mrz;
use App\Cotizacion\Enum\ValidacionDocumentoEnum;
use App\Enum\DocumentoTipoEnum;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas del control. Es lo único del proceso que JUZGA, así que es lo que tiene que estar
 * cubierto: un criterio que cambia sin que nadie se entere convierte el sello verde en decoración.
 */
final class CotejoTest extends TestCase
{
    private const L1 = 'P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<';
    private const L2 = 'L898902C36UTO7408122F1204159ZE184226B<<<<<10';

    private function pasaporte(bool $conMrzBuena = true, array $avisos = []): DatosDeDocumento
    {
        $l2 = $conMrzBuena ? self::L2 : str_replace('L898902C36', 'L898902C86', self::L2);
        $mrz = Mrz::desde(self::L1, $l2);

        return new DatosDeDocumento(
            tipo: DocumentoTipoEnum::PASAPORTE,
            numero: 'L898902C3',
            nombres: 'ANNA MARIA',
            apellidos: 'ERIKSSON',
            vencimiento: new DateTimeImmutable('2036-04-15'),
            mrz: $mrz,
            avisos: $avisos,
        );
    }

    private function dni(string $numero = '12345678'): DatosDeDocumento
    {
        return new DatosDeDocumento(
            tipo: DocumentoTipoEnum::DNI,
            numero: $numero,
            nombres: 'ANNA MARIA',
            apellidos: 'ERIKSSON',
        );
    }

    #[Test]
    public function unPasaporteConMrzBuenaQueConcuerdaQuedaValidado(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(
            numero: 'L898902C3',
            tipo: 'PASAPORTE',
            nombreCompleto: 'Anna María Eriksson',
        ));

        self::assertSame(ValidacionDocumentoEnum::VALIDADO, $cotejo->estado);
        self::assertSame([], $cotejo->observaciones);
    }

    /**
     * 🔥 El caso que más importa: la lectura sale igual de completa y creíble, y sin MRZ **no está
     * comprobada**. Si esto pasara a validado, el sello verde no significaría nada.
     */
    #[Test]
    public function unPasaporteSinMrzComprobableNuncaLlegaAValidado(): void
    {
        $sinBanda = new DatosDeDocumento(
            tipo: DocumentoTipoEnum::PASAPORTE,
            numero: 'L898902C3',
            nombres: 'ANNA MARIA',
            apellidos: 'ERIKSSON',
        );

        $cotejo = Cotejo::de($sinBanda, new FichaGuardada(numero: 'L898902C3', tipo: 'PASAPORTE'));

        self::assertSame(ValidacionDocumentoEnum::OBSERVADO, $cotejo->estado);
        self::assertStringContainsString('banda MRZ', implode(' ', $cotejo->observaciones));
    }

    #[Test]
    public function unaMrzQueNoCuadraSeObserva(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(conMrzBuena: false), new FichaGuardada(numero: 'L898902C3'));

        self::assertSame(ValidacionDocumentoEnum::OBSERVADO, $cotejo->estado);
    }

    #[Test]
    public function unNumeroDistintoDelGuardadoSeObservaYSeDiceCual(): void
    {
        $cotejo = Cotejo::de($this->dni('12345678'), new FichaGuardada(numero: '87654321', tipo: 'DNI'));

        self::assertSame(ValidacionDocumentoEnum::OBSERVADO, $cotejo->estado);
        self::assertStringContainsString('87654321', implode(' ', $cotejo->observaciones));
    }

    #[Test]
    public function unDniQueCoincideConLoGuardadoQuedaValidado(): void
    {
        $cotejo = Cotejo::de($this->dni(), new FichaGuardada(
            numero: '12345678',
            tipo: 'DNI',
            nombreCompleto: 'Anna Maria Eriksson',
        ));

        self::assertSame(ValidacionDocumentoEnum::VALIDADO, $cotejo->estado);
    }

    /**
     * 🔥 **La regla que sería fácil saltarse.** Un DNI cuyo único respaldo sería el manifiesto, y
     * que acaba de CREAR ese manifiesto, se estaría comparando consigo mismo: saldría bien
     * siempre. Un sello verde puesto por el dato que había que comprobar.
     */
    #[Test]
    public function unDniSinNadaContraQueCotejarNoSeValidaSolo(): void
    {
        self::assertSame(ValidacionDocumentoEnum::OBSERVADO, Cotejo::de($this->dni(), null)->estado);
        self::assertSame(ValidacionDocumentoEnum::OBSERVADO, Cotejo::de($this->dni(), new FichaGuardada())->estado);
    }

    /** El pasaporte SÍ puede, porque su respaldo es la aritmética y no el manifiesto. */
    #[Test]
    public function unPasaporteConMrzSeSostieneSinManifiesto(): void
    {
        self::assertSame(ValidacionDocumentoEnum::VALIDADO, Cotejo::de($this->pasaporte(), null)->estado);
    }

    #[Test]
    public function sinNumeroLegibleNoEsObservadoSinoSinValidar(): void
    {
        $cotejo = Cotejo::de(new DatosDeDocumento(tipo: DocumentoTipoEnum::DNI), new FichaGuardada(numero: '1'));

        self::assertSame(ValidacionDocumentoEnum::NO_VALIDADO, $cotejo->estado);
    }

    /** Un hueco en el manifiesto no es un desacuerdo: no puede llenar la cola de trabajo. */
    #[Test]
    public function unVencimientoGuardadoEnBlancoNoEsUnaDiferencia(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(numero: 'L898902C3', vencimiento: null));

        self::assertSame(ValidacionDocumentoEnum::VALIDADO, $cotejo->estado);
    }

    #[Test]
    public function elNombreSeComparaFlojoPorqueEnUnPadronSeEscribeDeQuinceManeras(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(
            numero: 'L898902C3',
            // Orden cambiado, tilde, coma y una partícula de más: es la misma persona.
            nombreCompleto: 'ERIKSSON, Anna María de los',
        ));

        self::assertSame(ValidacionDocumentoEnum::VALIDADO, $cotejo->estado);
    }

    #[Test]
    public function unNombreDeOtraPersonaSiSeSenala(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(
            numero: 'L898902C3',
            nombreCompleto: 'Roberto Carlos Quispe Mamani',
        ));

        self::assertSame(ValidacionDocumentoEnum::OBSERVADO, $cotejo->estado);
        self::assertStringContainsString('no se parece', implode(' ', $cotejo->observaciones));
    }

    #[Test]
    public function unAvisoDelLectorArrastraAObservado(): void
    {
        $cotejo = Cotejo::de(
            $this->pasaporte(avisos: ['está VENCIDO desde el 15/04/2012']),
            new FichaGuardada(numero: 'L898902C3'),
        );

        self::assertSame(ValidacionDocumentoEnum::OBSERVADO, $cotejo->estado);
    }
}
