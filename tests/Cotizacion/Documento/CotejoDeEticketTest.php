<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Cotizacion\Documento\CotejoDeEticket;
use App\Cotizacion\Documento\CruceDeFrontera;
use App\Cotizacion\Documento\DatosDeEticket;
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

    private function bueno(): DatosDeEticket
    {
        return new DatosDeEticket(
            codigo: 'ABC123', pasaporte: 'P1234567',
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'CM177',
            fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
            traeEntrada: true, traeSalida: true,
        );
    }

    public function testUnTramiteCorrectoNoTieneNadaQueObjetar(): void
    {
        $c = CotejoDeEticket::de($this->bueno(), $this->cruce(), 'P1234567');

        self::assertSame(ValidacionIdentificacionEnum::VALIDADO_OCR, $c->estado);
        self::assertSame([], $c->discrepancias);
    }

    public function testElVueloEquivocadoSale(): void
    {
        $leido = new DatosDeEticket(
            pasaporte: 'P1234567',
            fechaEntrada: new \DateTimeImmutable('2026-09-18'), vueloEntrada: 'DM6771',
            fechaSalida: new \DateTimeImmutable('2026-09-22'), vueloSalida: 'CM749',
            traeEntrada: true, traeSalida: true,
        );

        $c = CotejoDeEticket::de($leido, $this->cruce(), 'P1234567');

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

        $c = CotejoDeEticket::de($leido, $cruce, null);

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

        $c = CotejoDeEticket::de($leido, $this->cruce(), null);

        self::assertSame(ValidacionIdentificacionEnum::OBSERVADO, $c->estado);
        self::assertContains('sólo trae la ENTRADA: falta rellenar la salida', $c->notas);
    }

    public function testElPasaporteDeOtroSale(): void
    {
        $c = CotejoDeEticket::de($this->bueno(), $this->cruce(), 'P9999999');

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

        self::assertSame([], CotejoDeEticket::de($leido, $cruce, null)->discrepancias);
    }

    /** ⚠️ Sin subgrupo aéreo NO se acusa: se dice que no se puede comprobar, y por qué. */
    public function testSinVuelosNoSeAcusaANadie(): void
    {
        $sinCruce = CruceDeFrontera::de([], PaisDeControlEnum::REPUBLICA_DOMINICANA);

        $c = CotejoDeEticket::de($this->bueno(), $sinCruce, 'P1234567');

        self::assertSame(ValidacionIdentificacionEnum::NO_VALIDADO, $c->estado);
        self::assertSame([], $c->discrepancias);
        self::assertStringContainsString('no se puede cotejar', $c->notas[0]);
    }

    /** Una fecha que no se leyó no es una fecha que no coincide. */
    public function testUnaFechaIlegibleNoEsUnaDiscrepancia(): void
    {
        $leido = new DatosDeEticket(
            pasaporte: 'P1234567', vueloEntrada: 'CM177', vueloSalida: 'CM749',
            traeEntrada: true, traeSalida: true,
        );

        self::assertSame([], CotejoDeEticket::de($leido, $this->cruce(), 'P1234567')->discrepancias);
    }
}
