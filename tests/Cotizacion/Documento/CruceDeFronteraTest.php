<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Cotizacion\Documento\CruceDeFrontera;
use App\Cotizacion\Entity\CotizacionVuelo;
use App\Cotizacion\Enum\PaisDeControlEnum;
use PHPUnit\Framework\TestCase;

/**
 * La regla que decide contra QUÉ vuelo se coteja el trámite migratorio.
 *
 * Los vuelos de los casos son los del expediente real de Punta Cana, con sus tres formas de entrar
 * y sus dos de salir: es donde se ve que la pregunta no era trivial.
 */
final class CruceDeFronteraTest extends TestCase
{
    private function vuelo(string $numero, string $origen, string $destino, string $salida, string $llegada): CotizacionVuelo
    {
        return (new CotizacionVuelo())
            ->setNumero($numero)
            ->setOrigen($origen)
            ->setDestino($destino)
            ->setSalida(new \DateTimeImmutable($salida))
            ->setLlegada(new \DateTimeImmutable($llegada));
    }

    /** El subgrupo de Copa: entra por PTY→PUJ y sale por PUJ→PTY. */
    private function subgrupoCopa(): array
    {
        return [
            $this->vuelo('CM264', 'LIM', 'PTY', '2026-09-18 02:35', '2026-09-18 06:12'),
            $this->vuelo('CM177', 'PTY', 'PUJ', '2026-09-18 07:04', '2026-09-18 10:44'),
            $this->vuelo('CM749', 'PUJ', 'PTY', '2026-09-22 18:01', '2026-09-22 19:40'),
            $this->vuelo('CM337', 'PTY', 'LIM', '2026-09-22 21:20', '2026-09-23 00:55'),
        ];
    }

    /**
     * 🔑 Lo que hace útil todo esto: de cuatro vuelos, los dos correctos, **sin ninguna regla sobre
     * escalas**. Panamá se cae solo porque no es destino dominicano.
     */
    public function testElegirLaEntradaYLaSalidaEntreCuatroVuelos(): void
    {
        $cruce = CruceDeFrontera::de($this->subgrupoCopa(), PaisDeControlEnum::REPUBLICA_DOMINICANA);

        self::assertTrue($cruce->estaCompleto());
        self::assertSame('CM177', $cruce->entrada?->getNumero());
        self::assertSame('CM749', $cruce->salida?->getNumero());
        self::assertSame('', $cruce->porQueNoSePuede());
    }

    /** Un vuelo directo LIM→PUJ es la entrada igual: la regla no depende de que haya escala. */
    public function testElVueloDirectoTambienEsLaEntrada(): void
    {
        $cruce = CruceDeFrontera::de([
            $this->vuelo('DM6771', 'LIM', 'PUJ', '2026-09-18 00:30', '2026-09-18 06:49'),
            $this->vuelo('DM6770', 'PUJ', 'LIM', '2026-09-22 20:22', '2026-09-23 00:30'),
        ], PaisDeControlEnum::REPUBLICA_DOMINICANA);

        self::assertSame('DM6771', $cruce->entrada?->getNumero());
        self::assertSame('DM6770', $cruce->salida?->getNumero());
    }

    /** El orden de carga no manda: se ordena por hora antes de decidir. */
    public function testElOrdenEnQueSeCargaronNoImporta(): void
    {
        $alReves = array_reverse($this->subgrupoCopa());
        $cruce = CruceDeFrontera::de($alReves, PaisDeControlEnum::REPUBLICA_DOMINICANA);

        self::assertSame('CM177', $cruce->entrada?->getNumero());
        self::assertSame('CM749', $cruce->salida?->getNumero());
    }

    /**
     * ⚠️ La salida se busca DESPUÉS de la entrada. Sin eso, un `PUJ→LIM` de un viaje anterior
     * cargado en el mismo expediente se emparejaría con esta estancia y el control acusaría en
     * falso sobre una fecha correcta.
     */
    public function testUnaSalidaANTERIORALaEntradaNoCuenta(): void
    {
        $cruce = CruceDeFrontera::de([
            $this->vuelo('XX001', 'PUJ', 'LIM', '2026-08-01 10:00', '2026-08-01 15:00'),
            $this->vuelo('CM177', 'PTY', 'PUJ', '2026-09-18 07:04', '2026-09-18 10:44'),
            $this->vuelo('CM749', 'PUJ', 'PTY', '2026-09-22 18:01', '2026-09-22 19:40'),
        ], PaisDeControlEnum::REPUBLICA_DOMINICANA);

        self::assertSame('CM749', $cruce->salida?->getNumero());
    }

    /** Otro aeropuerto del país vale: no está cableado a Punta Cana. */
    public function testSeSalePorOtroAeropuertoDelPais(): void
    {
        $cruce = CruceDeFrontera::de([
            $this->vuelo('DM6771', 'LIM', 'PUJ', '2026-09-18 00:30', '2026-09-18 06:49'),
            $this->vuelo('ZZ999', 'SDQ', 'LIM', '2026-09-22 20:00', '2026-09-23 01:00'),
        ], PaisDeControlEnum::REPUBLICA_DOMINICANA);

        self::assertSame('ZZ999', $cruce->salida?->getNumero());
    }

    public function testSinVueloDeEntradaNoSePuedeJuzgar(): void
    {
        $cruce = CruceDeFrontera::de([
            $this->vuelo('JA7018', 'CUZ', 'LIM', '2026-09-17 07:15', '2026-09-17 08:55'),
        ], PaisDeControlEnum::REPUBLICA_DOMINICANA);

        self::assertFalse($cruce->estaCompleto());
        self::assertStringContainsString('no tiene ningún vuelo que entre', $cruce->porQueNoSePuede());
    }

    /** Entrar dos veces son dos trámites: se dice, no se juzga con el primero. */
    public function testDosEstanciasSeDenuncianEnVezDeAdivinar(): void
    {
        $cruce = CruceDeFrontera::de([
            $this->vuelo('A1', 'LIM', 'PUJ', '2026-09-18 00:30', '2026-09-18 06:49'),
            $this->vuelo('A2', 'PUJ', 'SJU', '2026-09-20 10:00', '2026-09-20 12:00'),
            $this->vuelo('A3', 'SJU', 'PUJ', '2026-09-21 10:00', '2026-09-21 12:00'),
            $this->vuelo('A4', 'PUJ', 'LIM', '2026-09-22 20:22', '2026-09-23 00:30'),
        ], PaisDeControlEnum::REPUBLICA_DOMINICANA);

        self::assertTrue($cruce->hayMasEntradas());
        self::assertStringContainsString('más de una vez', $cruce->porQueNoSePuede());
    }

    /** ⚠️ Un vuelo sin horas no puede decidir nada: se descarta en vez de colarse el primero. */
    public function testUnVueloSinHorasNoDecideLaEntrada(): void
    {
        $aMedias = (new CotizacionVuelo())->setNumero('SIN')->setOrigen('LIM')->setDestino('PUJ');

        $cruce = CruceDeFrontera::de([
            $aMedias,
            $this->vuelo('CM177', 'PTY', 'PUJ', '2026-09-18 07:04', '2026-09-18 10:44'),
            $this->vuelo('CM749', 'PUJ', 'PTY', '2026-09-22 18:01', '2026-09-22 19:40'),
        ], PaisDeControlEnum::REPUBLICA_DOMINICANA);

        self::assertSame('CM177', $cruce->entrada?->getNumero());
    }
}
