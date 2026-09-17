<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Cotizacion\Documento\CotejoDeEticket;
use App\Cotizacion\Documento\CruceDeFrontera;
use App\Cotizacion\Documento\DatosDeEticket;
use App\Cotizacion\Documento\PasajeroDelTramite;
use App\Cotizacion\Documento\ReferenciaDeIdentidad;
use App\Cotizacion\Entity\CotizacionVuelo;
use App\Cotizacion\Enum\PaisDeControlEnum;
use App\Cotizacion\Enum\ValidacionIdentificacionEnum;
use PHPUnit\Framework\TestCase;

/** El veredicto del trámite migratorio, sobre los vuelos reales del expediente de Punta Cana. */
final class CotejoDeEticketTest extends TestCase
{
    private function vuelo(string $n, string $o, string $d, string $sal, string $lle): CotizacionVuelo
    {
        return (new CotizacionVuelo())->setNumero($n)->setOrigen($o)->setDestino($d)
            ->setSalida(new \DateTimeImmutable($sal))->setLlegada(new \DateTimeImmutable($lle));
    }

    /** Copa: entra CM177 el 18, sale CM749 el 22. */
    private function cruce(): CruceDeFrontera
    {
        return CruceDeFrontera::de([
            $this->vuelo('CM264', 'LIM', 'PTY', '2026-09-18 02:35', '2026-09-18 06:12'),
            $this->vuelo('CM177', 'PTY', 'PUJ', '2026-09-18 07:04', '2026-09-18 10:44'),
            $this->vuelo('CM749', 'PUJ', 'PTY', '2026-09-22 18:01', '2026-09-22 19:40'),
            $this->vuelo('CM337', 'PTY', 'LIM', '2026-09-22 21:20', '2026-09-23 00:55'),
        ], PaisDeControlEnum::REPUBLICA_DOMINICANA);
    }

    /** Lo normal: hay escaneo del pasaporte y es lo que manda. */
    private function identidad(string $pasaporte = 'P1234567', string $nombre = 'DAMARIS LUCIANA PAZ RAMOS'): ReferenciaDeIdentidad
    {
        return ReferenciaDeIdentidad::delManifiesto($pasaporte, $nombre);
    }

    private function bueno(): DatosDeEticket
    {
        return new DatosDeEticket(
            codigo: 'ABC123', pasajeros: [new PasajeroDelTramite(pasaporte: 'P1234567')],
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
            fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
            traeEntrada: true, traeSalida: true,
        );
    }

    public function testUnTramiteCorrectoNoTieneNadaQueObjetar(): void
    {
        $c = CotejoDeEticket::de($this->bueno(), $this->cruce(), $this->identidad());

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_OCR, $c->estado);
        self::assertSame([], $c->discrepancias);
    }

    public function testElVueloEquivocadoSale(): void
    {
        $leido = new DatosDeEticket(
            pasajeros: [new PasajeroDelTramite(pasaporte: 'P1234567')],
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'DM6771',
            fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
            traeEntrada: true, traeSalida: true,
        );

        $c = CotejoDeEticket::de($leido, $this->cruce(), $this->identidad());

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $c->estado);
        self::assertSame('vuelo de entrada', $c->discrepancias[0]->campo);
        self::assertSame('DM6771', $c->discrepancias[0]->documento);
        self::assertSame('CM177', $c->discrepancias[0]->manifiesto);
    }

    /**
     * 🔥 La asimetría: se sale el día que se DESPEGA. El DM6770 despega de Punta Cana el 22 y
     * aterriza en Lima el 23; contra la llegada, un trámite correcto saldría con fecha equivocada
     * —y en un vuelo de vuelta nocturno, todo el grupo a la vez—.
     */
    public function testLaSalidaSeMideDesdeElDespegueYNoDesdeLaLlegada(): void
    {
        $cruce = CruceDeFrontera::de([
            $this->vuelo('DM6771', 'LIM', 'PUJ', '2026-09-18 00:30', '2026-09-18 06:49'),
            $this->vuelo('DM6770', 'PUJ', 'LIM', '2026-09-22 20:22', '2026-09-23 00:30'),
        ], PaisDeControlEnum::REPUBLICA_DOMINICANA);

        $leido = new DatosDeEticket(
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'DM6771',
            fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'DM6770',
            traeEntrada: true, traeSalida: true,
        );

        $c = CotejoDeEticket::de($leido, $cruce, ReferenciaDeIdentidad::vacia());

        self::assertSame([], $c->discrepancias, $c->resumen());
    }

    /** El fallo más común: sólo la entrada. Es rehacerlo, no una discrepancia. */
    public function testSoloLaEntradaQuedaObservado(): void
    {
        $leido = new DatosDeEticket(
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
            traeEntrada: true, traeSalida: false,
            avisos: ['sólo trae la ENTRADA: falta rellenar la salida'],
        );

        $c = CotejoDeEticket::de($leido, $this->cruce(), ReferenciaDeIdentidad::vacia());

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $c->estado);
        self::assertContains('sólo trae la ENTRADA: falta rellenar la salida', $c->notas);
    }

    public function testElPasaporteDeOtroSale(): void
    {
        $c = CotejoDeEticket::de($this->bueno(), $this->cruce(), $this->identidad('P9999999'));

        self::assertSame('pasaporte', $c->discrepancias[0]->campo);
    }

    /** `H2 5002` y `H25002` son el mismo vuelo: el espacio lo pone quien escribe. */
    public function testElEspacioDelNumeroDeVueloNoEsUnaDiscrepancia(): void
    {
        $cruce = CruceDeFrontera::de([
            $this->vuelo('H2 5002', 'LIM', 'PUJ', '2026-09-18 00:30', '2026-09-18 06:49'),
            $this->vuelo('H2 5003', 'PUJ', 'LIM', '2026-09-22 20:22', '2026-09-23 00:30'),
        ], PaisDeControlEnum::REPUBLICA_DOMINICANA);

        $leido = new DatosDeEticket(
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'H25002',
            fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'h2-5003',
            traeEntrada: true, traeSalida: true,
        );

        self::assertSame([], CotejoDeEticket::de($leido, $cruce, ReferenciaDeIdentidad::vacia())->discrepancias);
    }

    /** ⚠️ Sin subgrupo aéreo NO se acusa: se dice que no se puede comprobar, y por qué. */
    public function testSinVuelosNoSeAcusaANadie(): void
    {
        $sinCruce = CruceDeFrontera::de([], PaisDeControlEnum::REPUBLICA_DOMINICANA);

        $c = CotejoDeEticket::de($this->bueno(), $sinCruce, $this->identidad());

        self::assertSame(ValidacionIdentificacionEnum::NO_VALIDADO, $c->estado);
        self::assertSame([], $c->discrepancias);
        self::assertStringContainsString('no se puede cotejar', $c->notas[0]);
    }

    /** Una fecha que no se leyó no es una fecha que no coincide. */
    public function testUnaFechaIlegibleNoEsUnaDiscrepancia(): void
    {
        $leido = new DatosDeEticket(
            pasajeros: [new PasajeroDelTramite(pasaporte: 'P1234567')], vueloEntrada: 'CM177', vueloSalida: 'CM749',
            traeEntrada: true, traeSalida: true,
        );

        self::assertSame([], CotejoDeEticket::de($leido, $this->cruce(), $this->identidad())->discrepancias);
    }

    /**
     * 🔥 El nombre mal tecleado, que es lo que de verdad pasa y no lo cazaba nada.
     *
     * Caso real: hubo que avisar a mano de un `Ascarsa` por `Ascarza`.
     */
    public function testUnDedazoEnElNombreSeAcusa(): void
    {
        $leido = new DatosDeEticket(
            pasajeros: [new PasajeroDelTramite('JOAQUIN ASCARSA VIVANCO', 'P1234567')],
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
            fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
            traeEntrada: true, traeSalida: true,
        );

        $c = CotejoDeEticket::de($leido, $this->cruce(), $this->identidad('P1234567', 'JOAQUIN ASCARZA VIVANCO'));

        self::assertSame('nombre', $c->discrepancias[0]->campo);
        self::assertSame('ASCARSA (debería ser ASCARZA)', $c->discrepancias[0]->documento);
    }

    /**
     * ⚠️ Y el caso REAL que obligó a cambiar contra qué se coteja: al manifiesto le faltaba un
     * nombre y el trámite lo traía bien. Contra el manifiesto, el bueno salía acusado.
     */
    public function testUnNombreQueElManifiestoNoTraeNoSeAcusa(): void
    {
        $leido = new DatosDeEticket(
            pasajeros: [new PasajeroDelTramite('MATHEO ANTONIO GAMARRA ZANABRIA', 'P1234567')],
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
            fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
            traeEntrada: true, traeSalida: true,
        );

        $c = CotejoDeEticket::de($leido, $this->cruce(), $this->identidad('P1234567', 'MATHEO GAMARRA ZANABRIA'));

        self::assertSame([], $c->discrepancias);
        self::assertNotSame([], $c->notas, 'Que conste en las notas, sin acusar.');
    }

    /** ⚠️ Cuando la referencia también es de fiar a medias, se dice contra qué se comparó. */
    public function testSeDiceCuandoLaReferenciaEsElManifiesto(): void
    {
        $c = CotejoDeEticket::de($this->bueno(), $this->cruce(), $this->identidad('P9999999'));

        self::assertStringContainsString('el manifiesto (tecleado a mano)', implode(' ', $c->notas));
    }

    /**
     * 🔥 **El formulario compartido, medido en producción el 16/09/2026.**
     *
     * Herbert y Yusi rellenaron un solo E-Ticket —el propio documento dice que un QR vale para
     * todos los que figuren en él— y cada uno lo subió a su ficha. Cotejando siempre contra la
     * primera fila, el trámite de Herbert, que estaba **perfecto**, salía con el pasaporte de Yusi
     * y ocho notas de palabras que sobran y faltan.
     */
    public function testUnTramiteCompartidoSeCotejaContraLaFilaDeCadaUno(): void
    {
        $compartido = new DatosDeEticket(
            codigo: 'IFZYNE',
            pasajeros: [
                new PasajeroDelTramite('YUSI BETSI CRUZ ALVAREZ', '125995393', 'PER'),
                new PasajeroDelTramite('HERBERT JESUS ZEVALLOS GUZMAN', '125995436', 'PER'),
            ],
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
            fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
            traeEntrada: true, traeSalida: true,
        );

        $herbert = CotejoDeEticket::de(
            $compartido,
            $this->cruce(),
            $this->identidad('125995436', 'HERBERT JESUS ZEVALLOS GUZMAN'),
        );

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_OCR, $herbert->estado);
        self::assertSame([], $herbert->discrepancias);

        $yusi = CotejoDeEticket::de(
            $compartido,
            $this->cruce(),
            $this->identidad('125995393', 'YUSI BETSI CRUZ ALVAREZ'),
        );

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_OCR, $yusi->estado);
        self::assertSame([], $yusi->discrepancias);
    }

    /** Y que conste que es compartido: si no, el acierto se lee como que el control no se enteró. */
    public function testSeAvisaDeQueElTramiteEsDeVarios(): void
    {
        $c = CotejoDeEticket::de(
            new DatosDeEticket(
                pasajeros: [
                    new PasajeroDelTramite('YUSI BETSI CRUZ ALVAREZ', '125995393'),
                    new PasajeroDelTramite('HERBERT JESUS ZEVALLOS GUZMAN', '125995436'),
                ],
                fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
                fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
                traeEntrada: true, traeSalida: true,
            ),
            $this->cruce(),
            $this->identidad('125995436', 'HERBERT JESUS ZEVALLOS GUZMAN'),
        );

        self::assertStringContainsString('compartido: figuran 2 personas', implode(' ', $c->notas));
        self::assertStringContainsString('HERBERT', implode(' ', $c->notas));
    }

    /**
     * ⚠️ Un trámite que lista a gente y a ésta no: es «es de otro», no un dedazo, y se enseña la
     * lista entera para que quien lo mire vea a nombre de quién está.
     */
    public function testUnTramiteDeOtrasPersonasLoDiceConLosNombres(): void
    {
        $c = CotejoDeEticket::de(
            new DatosDeEticket(
                pasajeros: [
                    new PasajeroDelTramite('YUSI BETSI CRUZ ALVAREZ', '125995393'),
                    new PasajeroDelTramite('MARIA LOPEZ TORRES', '125995111'),
                ],
                fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
                fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
                traeEntrada: true, traeSalida: true,
            ),
            $this->cruce(),
            $this->identidad('125995436', 'HERBERT JESUS ZEVALLOS GUZMAN'),
        );

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $c->estado);
        self::assertSame('a nombre de', $c->discrepancias[0]->campo);
        self::assertStringContainsString('MARIA LOPEZ TORRES', $c->discrepancias[0]->documento);
    }

    /**
     * ⚠️ Con UNA sola persona el pasaporte distinto sigue siendo una discrepancia de pasaporte, no
     * un «el trámite es de otro»: lo que hay que hacer es corregir el número, y decirlo del otro
     * modo mandaría a rehacer el trámite entero.
     */
    public function testConUnSoloPasajeroElPasaporteDistintoSigueSiendoDiscrepanciaDePasaporte(): void
    {
        $c = CotejoDeEticket::de(
            new DatosDeEticket(
                pasajeros: [new PasajeroDelTramite('DAMARIS LUCIANA PAZ RAMOS', 'P7654321')],
                fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
                fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
                traeEntrada: true, traeSalida: true,
            ),
            $this->cruce(),
            $this->identidad(),
        );

        self::assertSame('pasaporte', $c->discrepancias[0]->campo);
    }

    /** ⚠️ `P 1234567` y `P1234567` son el mismo pasaporte: lo escribe quien rellena el formulario. */
    public function testElEspacioEnElPasaporteNoEsUnaDiscrepancia(): void
    {
        $leido = new DatosDeEticket(
            pasajeros: [new PasajeroDelTramite(pasaporte: 'P 123-4567')],
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
            fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
            traeEntrada: true, traeSalida: true,
        );

        self::assertSame([], CotejoDeEticket::de($leido, $this->cruce(), $this->identidad('P1234567'))->discrepancias);
    }

    /**
     * 🔥 Sin ninguna sección reconocida, esto daba «Observado» con cero discrepancias y NINGUNA
     * nota: una celda ámbar vacía en la hoja y un chip sin explicación en pantalla.
     */
    public function testSinNingunaSeccionSeDiceElPorQue(): void
    {
        $leido = new DatosDeEticket(
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
            traeEntrada: false, traeSalida: false,
        );

        $c = CotejoDeEticket::de($leido, $this->cruce(), ReferenciaDeIdentidad::vacia());

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $c->estado);
        self::assertNotSame([], $c->notas);
        self::assertStringContainsString('ninguna de las dos secciones', implode(' ', $c->notas));
    }

    /**
     * 🔥 Un billete de avión es el error MÁS común y es accionable: hay que escribirle a esa
     * persona. En `no_validado` se confundía con «nunca se ha mirado», que es lo contrario.
     */
    public function testUnBilleteDeAvionQuedaObservadoYNoSinValidar(): void
    {
        $leido = new DatosDeEticket(avisos: ['esto no parece un E-Ticket migratorio'], noEsElTramite: true);

        $c = CotejoDeEticket::de($leido, $this->cruce(), $this->identidad());

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $c->estado);
        self::assertNotSame([], $c->notas);
    }

    /** Un fallo de lectura es un veredicto con motivo, no un silencio. */
    public function testUnDocumentoIlegibleDejaElMotivoEscrito(): void
    {
        $c = CotejoDeEticket::ilegible('el fichero no está en disco');

        self::assertSame(ValidacionIdentificacionEnum::NO_VALIDADO, $c->estado);
        self::assertSame(['el fichero no está en disco'], $c->notas);
    }
}
