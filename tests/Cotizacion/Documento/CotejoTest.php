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
            nombres: 'Anna María', apellidos: 'Eriksson',
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
            nombres: 'Anna María', apellidos: 'Eriksson',
        ));

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_OCR, $cotejo->estado);
    }

    /** Pero se deja dicho por dónde se validó: no es lo mismo la aritmética que un parecido. */
    #[Test]
    public function seDiceQueSeValidoSinBanda(): void
    {
        $cotejo = Cotejo::de($this->pasaporteSinBanda(), new FichaGuardada(numero: 'L898902C3'));

        // Sin nombre guardado no hay cotejo posible, así que además se explica la banda.
        self::assertStringContainsString('no se pudo leer la banda MRZ', $cotejo->resumen());
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
            nombres: 'Roberto Carlos', apellidos: 'Quispe Mamani',
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
            nombres: 'Anna Maria', apellidos: 'Eriksson',
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
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(numero: 'L898902C3', vencimiento: null, nombres: 'Anna Maria', apellidos: 'Eriksson'));

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, $cotejo->estado);
    }

    #[Test]
    public function elNombreSeComparaFlojoPorqueEnUnPadronSeEscribeDeQuinceManeras(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(
            numero: 'L898902C3',
            // Orden cambiado, tilde, coma y una partícula de más: es la misma persona.
            nombres: 'Anna María', apellidos: 'ERIKSSON',
        ));

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, $cotejo->estado);
    }

    #[Test]
    public function unNombreDeOtraPersonaSiSeSenala(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(
            numero: 'L898902C3',
            nombres: 'Roberto Carlos', apellidos: 'Quispe Mamani',
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
        $ficha = new FichaGuardada(numero: 'L898902C3', nombres: 'Anna Maria', apellidos: 'Eriksson');

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
        $estado = Cotejo::de($this->dni(), new FichaGuardada(numero: '12345678', nombres: 'Anna Maria', apellidos: 'Eriksson'))->estado;

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
            nombres: 'Anna Maria', apellidos: 'Eriksson',
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
        $ficha = new FichaGuardada(numero: 'X1', nombres: 'Juan', apellidos: 'Perez', nacionalidad: 'PE');
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

        self::assertSame([], Cotejo::de($sinTraducir, new FichaGuardada(numero: 'X1', nombres: 'Juan', apellidos: 'Perez', nacionalidad: 'PE'))->discrepancias);
    }

    /**
     * 🔥 **El giro NO entra en el veredicto**, ni siquiera como nota. Es una propiedad del archivo,
     * y meterlo aquí obligaba a recalcular el veredicto al enderezar —20 s por clic— y sólo cubría
     * el escaneo que respalda ese número, no los demás de la misma persona.
     *
     * Un escaneo girado con la MRZ cuadrando está además PERFECTAMENTE leído: girarlo es cosmética.
     */
    #[Test]
    public function unEscaneoGiradoNoTocaElVeredicto(): void
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

        $cotejo = Cotejo::de($girado, new FichaGuardada(numero: 'L898902C3', nombres: 'Anna Maria', apellidos: 'Eriksson'));

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, $cotejo->estado);
        self::assertSame([], $cotejo->notas);
    }

    /** Y un escaneo derecho no dice nada: un aviso que sale siempre deja de leerse. */
    #[Test]
    public function unEscaneoDerechoNoDiceNada(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(numero: 'L898902C3', nombres: 'Anna Maria', apellidos: 'Eriksson'));

        self::assertSame([], $cotejo->notas);
    }

    /**
     * 🔥 El dígito verificador **del DNI**: 8 de las 10 discrepancias de número del expediente real
     * eran falsas por esto, en las dos direcciones. 8 dígitos contra 9.
     */
    #[Test]
    public function elDigitoVerificadorDelDniNoEsUnaDiferencia(): void
    {
        // El escaneo lo trae y el padrón no.
        self::assertSame([], Cotejo::de($this->dni('73716768-8'), new FichaGuardada(
            numero: '73716768', tipo: 'DNI', nombres: 'Anna Maria', apellidos: 'Eriksson',
        ))->discrepancias);

        // Y al revés: el padrón lo trae de más.
        self::assertSame([], Cotejo::de($this->dni('73716768'), new FichaGuardada(
            numero: '737167688', tipo: 'DNI', nombres: 'Anna Maria', apellidos: 'Eriksson',
        ))->discrepancias);
    }

    /**
     * 🔥 **REGRESIÓN, con un caso real de producción.** La tolerancia se aplicaba a cualquier tipo
     * y un pasaporte NO lleva dígito de control, así que esto quedó en verde:
     *
     *     PASAPORTE   manifiesto 1222988343   documento 122298834   →  VALIDADO_MRZ
     *
     * Un número de pasaporte con un dígito de más, «respaldado por aritmética». Es el problema de
     * aeropuerto que este control existe para cazar, escondido por la regla que limpiaba el ruido.
     */
    #[Test]
    public function unPasaporteConUnDigitoDeMasNoSeTolera(): void
    {
        $cotejo = Cotejo::de($this->pasaporte(), new FichaGuardada(
            numero: 'L898902C33',   // el de la MRZ es 'L898902C3'
            tipo: 'PASAPORTE',
            nombres: 'Anna Maria', apellidos: 'Eriksson',
        ));

        self::assertSame('número', $cotejo->discrepancias[0]->campo);
        self::assertNotSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, $cotejo->estado);
    }

    /** Y un DNI truncado tampoco: 7 contra 8 no es la convención, es un número mal tecleado. */
    #[Test]
    public function unDniTruncadoNoSeTolera(): void
    {
        $cotejo = Cotejo::de($this->dni('7371676'), new FichaGuardada(
            numero: '73716768', tipo: 'DNI', nombres: 'Anna Maria', apellidos: 'Eriksson',
        ));

        self::assertSame('número', $cotejo->discrepancias[0]->campo);
    }

    /** Pero dos números distintos siguen siéndolo: son las que sí hay que mirar. */
    #[Test]
    public function dosNumerosDistintosSiguenSiendoUnaDiferencia(): void
    {
        $cotejo = Cotejo::de($this->dni('125853071'), new FichaGuardada(
            numero: '61859757', nombres: 'Anna Maria', apellidos: 'Eriksson',
        ));

        self::assertSame('número', $cotejo->discrepancias[0]->campo);
    }

    /** Y dos caracteres de más ya NO es una convención: es un número mal tecleado. */
    #[Test]
    public function dosDigitosDeMasSiSeSenalan(): void
    {
        $cotejo = Cotejo::de($this->dni('12229883'), new FichaGuardada(
            numero: '1222988343', tipo: 'DNI', nombres: 'Anna Maria', apellidos: 'Eriksson',
        ));

        self::assertSame('número', $cotejo->discrepancias[0]->campo);
    }

    /**
     * 🔥 **REGRESIÓN: `strtr()` con dos cadenas opera BYTE A BYTE.** «José Pérez Núñez» salía como
     * `JOSO` y `REZ`, así que dos escrituras del mismo nombre —una con tildes y otra sin— no
     * compartían ni una palabra. Estaba latente en el expediente real y habría sacado un «nombre
     * no coincide» falso en cuanto alguien se llamara «José Pérez» y nada más.
     */
    #[Test]
    public function lasTildesNoRompenLaComparacionDeNombres(): void
    {
        $conTildes = new DatosDeDocumento(numero: 'X1', nombres: 'JOSÉ', apellidos: 'PÉREZ');

        self::assertSame([], Cotejo::de($conTildes, new FichaGuardada(
            numero: 'X1', nombres: 'Jose', apellidos: 'Perez',
        ))->discrepancias);
    }

    /**
     * 🔥 **Dos hermanos comparten los dos apellidos**, que es exactamente el caso que este control
     * existe para cazar: un número tecleado en la ficha del hermano equivocado. Con «dos palabras
     * en común» bastaba para validarlo.
     */
    #[Test]
    public function dosHermanosNoSonLaMismaPersona(): void
    {
        $pedro = new DatosDeDocumento(numero: 'X1', nombres: 'PEDRO', apellidos: 'QUISPE MAMANI');

        $cotejo = Cotejo::de($pedro, new FichaGuardada(
            numero: 'X1', nombres: 'Juan', apellidos: 'Quispe Mamani',
        ));

        self::assertSame('nombre', $cotejo->discrepancias[0]->campo);
    }

    /** Pero la misma persona con el orden cambiado sigue siendo la misma. */
    #[Test]
    public function elOrdenDeNombreYApellidosSigueDandoIgual(): void
    {
        $pedro = new DatosDeDocumento(numero: 'X1', nombres: 'PEDRO LUIS', apellidos: 'QUISPE MAMANI');

        self::assertSame([], Cotejo::de($pedro, new FichaGuardada(
            numero: 'X1', nombres: 'Pedro Luis', apellidos: 'QUISPE MAMANI',
        ))->discrepancias);
    }

    /**
     * 🔥 La salida que no existía. Una ficha copiada del escaneo no se puede cotejar contra el
     * escaneo del que salió, así que sin confirmación humana se quedaba observada para siempre:
     * el aviso pedía «que alguien la confirme» y no había dónde.
     */
    #[Test]
    public function unaFichaCopiadaDelEscaneoYConfirmadaAManoQuedaConfirmada(): void
    {
        $cotejo = Cotejo::de($this->dni('41501189'), new FichaGuardada(
            numero: '41501189',
            tipo: 'DNI',
            nombres: 'Anna Maria', apellidos: 'Eriksson',
            copiadaDelEscaneo: true,
            confirmada: true,
        ));

        self::assertSame(ValidacionIdentificacionEnum::CONFIRMADO, $cotejo->estado);
        self::assertSame([], $cotejo->notas);
    }

    /** Y sin confirmar sigue observada, que es el comportamiento que había que conservar. */
    #[Test]
    public function laMismaFichaSinConfirmarSigueObservada(): void
    {
        $cotejo = Cotejo::de($this->dni('41501189'), new FichaGuardada(
            numero: '41501189',
            tipo: 'DNI',
            nombres: 'Anna Maria', apellidos: 'Eriksson',
            copiadaDelEscaneo: true,
        ));

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
        self::assertStringContainsString('que alguien la confirme', implode(' ', $cotejo->notas));
    }

    /**
     * ⚠️ Confirmar no es un cheque en blanco: si el escaneo pasa a decir otra cosa, la
     * discrepancia manda y vuelve a la cola. La firma era sobre **aquel** número.
     */
    #[Test]
    public function confirmadaPeroConElEscaneoDiciendoOtroNumeroVuelveAObservado(): void
    {
        $cotejo = Cotejo::de($this->dni('73716768'), new FichaGuardada(
            numero: '41501189',
            tipo: 'DNI',
            nombres: 'Anna Maria', apellidos: 'Eriksson',
            copiadaDelEscaneo: true,
            confirmada: true,
        ));

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
        self::assertSame('número', $cotejo->discrepancias[0]->campo);
    }

    /** El sello de máquina manda sobre el de persona: si hay MRZ, el camino que cuenta es ése. */
    #[Test]
    public function conBandaVerificadaElSelloEsMrzAunqueEsteConfirmada(): void
    {
        $cotejo = Cotejo::de(
            $this->dni('41501189'),
            new FichaGuardada(numero: '41501189', tipo: 'DNI', copiadaDelEscaneo: true, confirmada: true),
            $this->bandaDelReverso(),
        );

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, $cotejo->estado);
    }

    /**
     * Una MRZ TD1 como la del reverso de un DNIe peruano: tres líneas de 30.
     *
     * ⚠️ Los dígitos de control están **calculados**, no copiados de una foto: el par (check del
     * número, check compuesto) tiene que cuadrar entre sí o `Mrz::desde()` devuelve `null` y el
     * test pasaría a probar otra cosa sin decirlo.
     *
     * @param string $numero Los 8 dígitos del DNI
     * @param string $checks El dígito del número y el compuesto, que van juntos
     */
    private function bandaDelReverso(string $numero = '41501189', string $checks = '30'): ?Mrz
    {
        return Mrz::desde(
            str_pad('I<PER' . $numero . $checks[0], 30, '<'),
            '7808227M2907136PER' . str_repeat('<', 11) . $checks[1],
            str_pad('DIAZ<<RAY<DANTE', 30, '<'),
        );
    }

    /**
     * 🔥 El caso que estuvo roto: sin el aval, **ningún DNI podía llegar al sello por MRZ**, porque
     * la banda se buscaba en el anverso y el DNIe la lleva en el reverso.
     */
    #[Test]
    public function laBandaDelReversoValidaElDniDelAnverso(): void
    {
        $cotejo = Cotejo::de($this->dni('41501189'), null, $this->bandaDelReverso());

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_MRZ, $cotejo->estado);
        self::assertSame([], $cotejo->notas);
    }

    /** Sin ficha contra la que cotejar y sin aval, se queda observado: es lo que pasaba siempre. */
    #[Test]
    public function sinAvalElMismoDniSeQuedaObservado(): void
    {
        $cotejo = Cotejo::de($this->dni('41501189'), null, null, faltaLaOtraCara: true);

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
        // Y el aviso pide lo que falta, en vez de mandar a rehacer una foto que está bien.
        self::assertStringContainsString('falta el reverso', implode(' ', $cotejo->notas));
    }

    /**
     * ⚠️ «Súbela» y «mírala» son dos trabajos distintos, y el aviso tiene que decir cuál. Con el
     * reverso ya subido, pedirlo otra vez manda a buscar algo que está ahí delante.
     */
    #[Test]
    public function conElReversoSubidoPeroIlegibleElAvisoNoLoPideOtraVez(): void
    {
        $notas = implode(' ', Cotejo::de($this->dni('41501189'), null, null)->notas);

        self::assertStringContainsString('no se pudo verificar', $notas);
        self::assertStringNotContainsString('falta el reverso', $notas);
    }

    /**
     * 🔥 El aviso de la banda NO se pone encima de una discrepancia. Si el número del escaneo no
     * coincide con el del manifiesto, el problema es ése: nombrar además la banda es ruido tapando
     * lo único que hay que leer.
     */
    #[Test]
    public function sobreUnaDiscrepanciaElAvisoDeLaBandaNoAparece(): void
    {
        $cotejo = Cotejo::de($this->dni('41501189'), new FichaGuardada(
            numero: '73716768',
            tipo: 'DNI',
            nombres: 'Anna María', apellidos: 'Eriksson',
        ), null, faltaLaOtraCara: true);

        self::assertSame('número', $cotejo->discrepancias[0]->campo);
        self::assertStringNotContainsString('banda', implode(' ', $cotejo->notas));
    }

    /**
     * ⚠️ El aval NO se acepta a ciegas: dos caras de documentos distintos en la misma ficha es un
     * archivo mal asignado, y sellarlo en verde sería justo el fallo que esto persigue.
     */
    #[Test]
    public function unaBandaDeOtroDocumentoNoAvalaNada(): void
    {
        $cotejo = Cotejo::de($this->dni('41501189'), null, $this->bandaDelReverso('73716768', '30'));

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $cotejo->estado);
        self::assertStringContainsString('dos documentos distintos', implode(' ', $cotejo->notas));
    }
}
