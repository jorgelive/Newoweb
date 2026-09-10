<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Cotizacion\Documento\Cotejo;
use App\Cotizacion\Documento\DatosDeDocumento;
use App\Cotizacion\Documento\FichaGuardada;
use App\Cotizacion\Documento\Mrz;
use App\Cotizacion\Enum\ValidacionIdentificacionEnum;
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
            // Como en el lector real: con MRZ coherente, nacimiento y vencimiento salen de ella.
            nacimiento: $mrz?->nacimiento,
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

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, $cotejo->estado);
        self::assertSame([], $cotejo->discrepancias);
        self::assertSame([], $cotejo->notas);
    }

    private function pasaporteSinBanda(): DatosDeDocumento
    {
        return new DatosDeDocumento(
            tipo: DocumentoTipoEnum::PASAPORTE,
            numero: 'L898902C3',
            nombres: 'ANNA MARIA',
            apellidos: 'ERIKSSON',
        );
    }

    /**
     * Sin banda legible el pasaporte NO se queda sin salida: cae al cotejo, como un DNI. La banda
     * no siempre entra en el escaneo, y negarle la validación a un pasaporte cuyo número y nombre
     * concuerdan sería tratar «no pude por el camino bueno» como «no se puede».
     */
    #[Test]
    public function unPasaporteSinMrzSeValidaCotejandoNumeroYNombre(): void
    {
        $cotejo = Cotejo::de($this->pasaporteSinBanda(), new FichaGuardada(
            numero: 'L898902C3',
            tipo: 'PASAPORTE',
            nombreCompleto: 'Anna María Eriksson',
        ));

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_OCR, $cotejo->estado);
    }

    /** Pero se deja dicho por dónde se validó: no es lo mismo la aritmética que un parecido. */
    #[Test]
    public function seDiceQueSeValidoSinBanda(): void
    {
        $cotejo = Cotejo::de($this->pasaporteSinBanda(), new FichaGuardada(numero: 'L898902C3'));

        // Sin nombre guardado no hay cotejo posible, así que además se explica la banda.
        self::assertStringContainsString('sin banda MRZ', $cotejo->resumen());
    }

    /**
     * 🔥 **El cotejo exige las DOS cosas.** Con el número igual y el nombre de otra persona no se
     * valida: un número tecleado igual en dos fichas de la misma familia es el error que se busca.
     */
    #[Test]
    public function sinMrzElNumeroSoloNoBasta(): void
    {
        $cotejo = Cotejo::de($this->pasaporteSinBanda(), new FichaGuardada(
            numero: 'L898902C3',
            nombreCompleto: 'Roberto Carlos Quispe Mamani',
        ));

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
    }

    /** Y sin nombre guardado no hay segunda fuente, aunque el número case. */
    #[Test]
    public function sinMrzYSinNombreGuardadoNoHayContraQueCotejar(): void
    {
        $cotejo = Cotejo::de($this->pasaporteSinBanda(), new FichaGuardada(numero: 'L898902C3'));

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
        self::assertStringContainsString('no hay contra qué cotejar', $cotejo->resumen());
    }

    #[Test]
    public function unaMrzQueNoCuadraSeObserva(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(conMrzBuena: false), new FichaGuardada(numero: 'L898902C3'));

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
    }

    #[Test]
    public function unNumeroDistintoDelGuardadoSeObservaYSeDiceCual(): void
    {
        $cotejo = Cotejo::de($this->dni('12345678'), new FichaGuardada(numero: '87654321', tipo: 'DNI'));

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
        // Estructurada: la pantalla la pinta al lado del campo, con los dos valores a la vista.
        self::assertSame('número', $cotejo->discrepancias[0]->campo);
        self::assertSame('12345678', $cotejo->discrepancias[0]->documento);
        self::assertSame('87654321', $cotejo->discrepancias[0]->manifiesto);
    }

    #[Test]
    public function unDniQueCoincideConLoGuardadoQuedaValidado(): void
    {
        $cotejo = Cotejo::de($this->dni(), new FichaGuardada(
            numero: '12345678',
            tipo: 'DNI',
            nombreCompleto: 'Anna Maria Eriksson',
        ));

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_OCR, $cotejo->estado);
    }

    /**
     * 🔥 **La regla que sería fácil saltarse.** Un DNI cuyo único respaldo sería el manifiesto, y
     * que acaba de CREAR ese manifiesto, se estaría comparando consigo mismo: saldría bien
     * siempre. Un sello verde puesto por el dato que había que comprobar.
     */
    #[Test]
    public function unDniSinNadaContraQueCotejarNoSeValidaSolo(): void
    {
        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, Cotejo::de($this->dni(), null)->estado);
        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, Cotejo::de($this->dni(), new FichaGuardada())->estado);
    }

    /** El pasaporte SÍ puede, porque su respaldo es la aritmética y no el manifiesto. */
    #[Test]
    public function unPasaporteConMrzSeSostieneSinManifiesto(): void
    {
        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, Cotejo::de($this->pasaporte(), null)->estado);
    }

    #[Test]
    public function sinNumeroLegibleNoEsObservadoSinoSinValidar(): void
    {
        $cotejo = Cotejo::de(new DatosDeDocumento(tipo: DocumentoTipoEnum::DNI), new FichaGuardada(numero: '1'));

        self::assertSame(ValidacionIdentificacionEnum::NO_VALIDADO, $cotejo->estado);
    }

    /** Un hueco en el manifiesto no es un desacuerdo: no puede llenar la cola de trabajo. */
    #[Test]
    public function unVencimientoGuardadoEnBlancoNoEsUnaDiferencia(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(numero: 'L898902C3', vencimiento: null, nombreCompleto: 'Anna Maria Eriksson'));

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, $cotejo->estado);
    }

    #[Test]
    public function elNombreSeComparaFlojoPorqueEnUnPadronSeEscribeDeQuinceManeras(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(
            numero: 'L898902C3',
            // Orden cambiado, tilde, coma y una partícula de más: es la misma persona.
            nombreCompleto: 'ERIKSSON, Anna María de los',
        ));

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, $cotejo->estado);
    }

    #[Test]
    public function unNombreDeOtraPersonaSiSeSenala(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(
            numero: 'L898902C3',
            nombreCompleto: 'Roberto Carlos Quispe Mamani',
        ));

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
        self::assertSame('nombre', $cotejo->discrepancias[0]->campo);
    }

    #[Test]
    public function unAvisoDelLectorArrastraAObservado(): void
    {
        $cotejo = Cotejo::de(
            $this->pasaporte(avisos: ['está VENCIDO desde el 15/04/2012']),
            new FichaGuardada(numero: 'L898902C3'),
        );

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
    }

    /** Los dos sellos verdes NO son el mismo: cuál fue decide cuánto vale. */
    #[Test]
    public function distingueValidadoPorMrzDeValidadoCotejando(): void
    {
        $ficha = new FichaGuardada(numero: 'L898902C3', nombreCompleto: 'Anna Maria Eriksson');

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, Cotejo::de($this->pasaporte(), $ficha)->estado);
        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_OCR, Cotejo::de($this->pasaporteSinBanda(), $ficha)->estado);
    }

    /**
     * 🔥 **Este test decía «un DNI NUNCA puede llegar a MRZ» y la creencia era falsa.**
     *
     * Se daba por hecho que el DNI peruano no lleva banda. El nuevo la lleva en el **anverso**, en
     * formato TD1, así que un DNI también puede quedar respaldado por aritmética. Falló en rojo el
     * día que se corrigió, que es exactamente para lo que estaba escrito.
     *
     * Sin banda legible sigue quedándose en OCR — que no es peor lectura, es que no hay dígitos
     * que comprobar.
     */
    #[Test]
    public function unDniSinBandaSeQuedaEnOcrPeroPodriaLlegarAMrz(): void
    {
        $estado = Cotejo::de($this->dni(), new FichaGuardada(numero: '12345678', nombreCompleto: 'Anna Maria Eriksson'))->estado;

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_OCR, $estado);
        self::assertTrue(ValidacionIdentificacionEnum::VALIDADO_MRZ->aplicaA(DocumentoTipoEnum::DNI));
    }

    /** Los que de verdad no tienen banda de ninguna clase sí quedan excluidos. */
    #[Test]
    public function unCarneDeExtranjeriaNoPuedeLlegarAMrz(): void
    {
        self::assertFalse(ValidacionIdentificacionEnum::VALIDADO_MRZ->aplicaA(DocumentoTipoEnum::CE));
        self::assertFalse(ValidacionIdentificacionEnum::VALIDADO_MRZ->aplicaA(DocumentoTipoEnum::RUC));
    }

    #[Test]
    public function unaFechaDeNacimientoDistintaEsUnaDiscrepanciaDeSuCampo(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(
            numero: 'L898902C3',
            nombreCompleto: 'Anna Maria Eriksson',
            nacimiento: new DateTimeImmutable('1974-08-21'),
        ));

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
        self::assertSame('nacimiento', $cotejo->discrepancias[0]->campo);
        self::assertSame('1974-08-12', $cotejo->discrepancias[0]->documento);
    }

    /**
     * 🔥 La nacionalidad se compara **ya traducida**. El documento habla ISO-3 y el manifiesto
     * ISO-2: comparar `PER` con `PE` no falla, **siempre difiere**, y sacaría una discrepancia
     * falsa en cada documento hasta dejar la cola inservible.
     */
    #[Test]
    public function laNacionalidadSeComparaEnIso2YNoEnIso3(): void
    {
        $ficha = new FichaGuardada(numero: 'X1', nombreCompleto: 'Juan Perez', nacionalidad: 'PE');
        $base = ['numero' => 'X1', 'nombres' => 'JUAN', 'apellidos' => 'PEREZ'];

        $peruano = new DatosDeDocumento(...$base, nacionalidadIso2: 'PE');
        $chileno = new DatosDeDocumento(...$base, nacionalidadIso2: 'CL');

        self::assertSame([], Cotejo::de($peruano, $ficha)->discrepancias);
        self::assertSame('nacionalidad', Cotejo::de($chileno, $ficha)->discrepancias[0]->campo);
    }

    /** Un código que no existe en ISO no es «no coincide»: es «no se pudo comprobar». */
    #[Test]
    public function unaNacionalidadIntraducibleNoInventaUnaDiscrepancia(): void
    {
        $sinTraducir = new DatosDeDocumento(numero: 'X1', nombres: 'JUAN', apellidos: 'PEREZ', nacionalidadIso2: null);

        self::assertSame([], Cotejo::de($sinTraducir, new FichaGuardada(numero: 'X1', nombreCompleto: 'Juan Perez', nacionalidad: 'PE'))->discrepancias);
    }

    /**
     * 🔥 Un escaneo girado con la MRZ cuadrando está PERFECTAMENTE leído: girarlo es cosmética.
     * Si esto bajara a `observado`, cada foto torcida llenaría la cola de cosas que no hay que
     * decidir — y la cola se deja de mirar entera.
     */
    #[Test]
    public function unEscaneoGiradoAvisaPeroNoBajaElSello(): void
    {
        $girado = new DatosDeDocumento(
            tipo: DocumentoTipoEnum::PASAPORTE,
            numero: 'L898902C3',
            nombres: 'ANNA MARIA',
            apellidos: 'ERIKSSON',
            nacimiento: Mrz::desde(self::L1, self::L2)?->nacimiento,
            rotacion: 90,
            mrz: Mrz::desde(self::L1, self::L2),
        );

        $cotejo = Cotejo::de($girado, new FichaGuardada(numero: 'L898902C3', nombreCompleto: 'Anna Maria Eriksson'));

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, $cotejo->estado);
        self::assertStringContainsString('girado 90', $cotejo->resumen());
    }

    /** Y un escaneo derecho no dice nada: un aviso que sale siempre deja de leerse. */
    #[Test]
    public function unEscaneoDerechoNoDiceNada(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(numero: 'L898902C3', nombreCompleto: 'Anna Maria Eriksson'));

        self::assertSame([], $cotejo->notas);
    }

    /**
     * 🔥 El dígito verificador del DNI: 8 de las 10 discrepancias de número del expediente real
     * eran falsas por esto, y **en las dos direcciones**.
     */
    #[Test]
    public function elDigitoVerificadorNoEsUnaDiferencia(): void
    {
        // El escaneo lo trae y el padrón no — 6 casos reales.
        self::assertSame([], Cotejo::de($this->dni('73716768-8'), new FichaGuardada(
            numero: '73716768', nombreCompleto: 'Anna Maria Eriksson',
        ))->discrepancias);

        // Y al revés: el padrón lo trae de más — 2 casos reales.
        self::assertSame([], Cotejo::de($this->dni('122298834'), new FichaGuardada(
            numero: '1222988343', nombreCompleto: 'Anna Maria Eriksson',
        ))->discrepancias);
    }

    /** Pero dos números distintos siguen siéndolo: son las que sí hay que mirar. */
    #[Test]
    public function dosNumerosDistintosSiguenSiendoUnaDiferencia(): void
    {
        $cotejo = Cotejo::de($this->dni('125853071'), new FichaGuardada(
            numero: '61859757', nombreCompleto: 'Anna Maria Eriksson',
        ));

        self::assertSame('número', $cotejo->discrepancias[0]->campo);
    }

    /** Y dos caracteres de más ya NO es una convención: es un número mal tecleado. */
    #[Test]
    public function dosDigitosDeMasSiSeSenalan(): void
    {
        $cotejo = Cotejo::de($this->dni('12229883'), new FichaGuardada(
            numero: '1222988343', nombreCompleto: 'Anna Maria Eriksson',
        ));

        self::assertSame('número', $cotejo->discrepancias[0]->campo);
    }
}
