<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Cotizacion\Documento\DatosDeDocumento;
use App\Cotizacion\Documento\DatosDeEticket;
use App\Cotizacion\Documento\Mrz;
use App\Cotizacion\Documento\QueLePedimosAlPasajero as Pedir;
use App\Cotizacion\Enum\ArchivoTipoEnum as T;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Qué se le pide al pasajero al subir. La regla: sólo lo que ÉL puede arreglar con otra foto.
 */
final class QueLePedimosAlPasajeroTest extends TestCase
{
    private function hoy(): DateTimeImmutable { return new DateTimeImmutable('2026-09-17'); }

    /** El pasaporte real de hoy: MRZ coherente, vigente hasta 2036. */
    private function mrzBuena(): Mrz
    {
        $m = Mrz::desde('P<PERSAMANEZ<CUBA<<CARLOS<ENRIQUE<<<<<<<<<<<', '1258542904PER7601277M3606171<<<<<<<<<<<<<<<<');
        self::assertNotNull($m);

        return $m;
    }

    private function pasaporte(array $encima = []): DatosDeDocumento
    {
        return new DatosDeDocumento(...[
            'numero' => '125854290',
            'vencimiento' => new DateTimeImmutable('2036-06-17'),
            'mrz' => $this->mrzBuena(),
            ...$encima,
        ]);
    }

    public function testUnPasaporteBuenoNoPideNada(): void
    {
        self::assertSame([], Pedir::delDocumento(T::PASAPORTE, $this->pasaporte(), $this->hoy()));
    }

    public function testIlegiblePideOtraFoto(): void
    {
        self::assertStringContainsString('No conseguimos leer', Pedir::delDocumento(T::PASAPORTE, null, $this->hoy())[0]);
        self::assertStringContainsString('No conseguimos leer', Pedir::delDocumento(T::DNI_ANVERSO, new DatosDeDocumento(), $this->hoy())[0]);
    }

    /** 🔑 El caso que lo motivó: «tomaron la foto de muy cerca». */
    public function testLaBandaCortadaPideAlejarElMovil(): void
    {
        $cortada = $this->pasaporte(['avisosDeLectura' => ['la banda no cuadra entera']]);

        self::assertStringContainsString('Aleja un poco el móvil', Pedir::delDocumento(T::PASAPORTE, $cortada, $this->hoy())[0]);
    }

    public function testUnPasaporteConLaBandaVaciaLaPide(): void
    {
        $sinBanda = $this->pasaporte(['mrz' => null, 'bandaVacia' => true]);

        self::assertStringContainsString('banda de letras', Pedir::delDocumento(T::PASAPORTE, $sinBanda, $this->hoy())[0]);
    }

    /**
     * 🔥 **Banda visible pero mal transcrita: NO se le pide nada.** Pasaba con 26 de 29 pasaportes
     * «sin MRZ» en producción. Otra foto no lo arregla, y decirle «no se ve la banda» a quien la
     * tiene delante es culparle de nuestra lectura.
     */
    public function testUnaBandaMalTranscritaNoEsCulpaDelPasajero(): void
    {
        $malLeida = $this->pasaporte(['mrz' => null, 'bandaVacia' => false]);

        self::assertSame([], Pedir::delDocumento(T::PASAPORTE, $malLeida, $this->hoy()));
    }

    /** ⚠️ El anverso del DNI no lleva banda: pedírsela sería pedir algo que no existe. */
    public function testAlAnversoDelDniNoSeLePideBanda(): void
    {
        $anverso = new DatosDeDocumento(numero: '23985272', vencimiento: new DateTimeImmutable('2031-04-17'));

        self::assertSame([], Pedir::delDocumento(T::DNI_ANVERSO, $anverso, $this->hoy()));
    }

    public function testElReversoDelDniSiLaPide(): void
    {
        $reverso = new DatosDeDocumento(numero: '23985272', vencimiento: new DateTimeImmutable('2031-04-17'), bandaVacia: true);

        self::assertStringContainsString('parte de atrás', Pedir::delDocumento(T::DNI_REVERSO, $reverso, $this->hoy())[0]);
    }

    /** ⚠️ Se pide, no se sentencia: la fecha puede estar mal leída. El texto deja las dos salidas. */
    public function testVencidoLoDiceConLaFechaYDejaLaSalidaDeRepetirLaFoto(): void
    {
        $vencido = new DatosDeDocumento(numero: '23985272', vencimiento: new DateTimeImmutable('2026-08-01'));
        $pedido = Pedir::delDocumento(T::DNI_ANVERSO, $vencido, $this->hoy());

        self::assertStringContainsString('01/08/2026', $pedido[0]);
        self::assertStringContainsString('Si no está vencido', $pedido[0]);
    }

    public function testElBilleteEnVezDelEticketLoDice(): void
    {
        self::assertStringContainsString('tarjeta de embarque', Pedir::delEticket(new DatosDeEticket(noEsElTramite: true))[0]);
    }

    public function testUnEticketSinDatosPideElPdf(): void
    {
        self::assertStringContainsString('No conseguimos leer', Pedir::delEticket(null)[0]);
        self::assertStringContainsString('No conseguimos leer', Pedir::delEticket(new DatosDeEticket(traeEntrada: true))[0]);
    }

    public function testSoloLaEntradaPideLaSalida(): void
    {
        $soloEntrada = new DatosDeEticket(vueloEntrada: 'CM177', traeEntrada: true, traeSalida: false);

        self::assertStringContainsString('sólo tiene la ENTRADA', Pedir::delEticket($soloEntrada)[0]);
    }

    /**
     * 🔥 **Lo que NO se le pide nunca: que corrija un vuelo o una fecha.** Esos datos se comparan
     * contra nuestros subgrupos, y once de los primeros treinta y dos observados eran fallo nuestro.
     * Esta clase ni siquiera recibe el cotejo: no puede pedirlo aunque quiera.
     */
    public function testUnEticketCompletoNoPideNadaAunqueElVueloNoCuadre(): void
    {
        $completo = new DatosDeEticket(vueloEntrada: 'XX999', vueloSalida: 'XX998', traeEntrada: true, traeSalida: true);

        self::assertSame([], Pedir::delEticket($completo));
    }

    /**
     * 🔥 **El agujero que encontró quien opera: un DNI por el hueco del pasaporte.** El reverso del DNI
     * trae una banda TD1 que cuadra perfectamente, así que ninguna otra regla lo veía. 65 de 69
     * reversos reales habrían pasado.
     */
    public function testUnDniSubidoComoPasaporteSePide(): void
    {
        $reversoDeDni = new DatosDeDocumento(
            tipo: \App\Enum\DocumentoTipoEnum::DNI,
            numero: '23985272',
            vencimiento: new DateTimeImmutable('2031-04-17'),
        );

        $pedido = Pedir::delDocumento(T::PASAPORTE, $reversoDeDni, $this->hoy());

        self::assertCount(1, $pedido, 'sólo esto: nada sobre la banda ni el vencimiento de un documento que no es');
        self::assertStringContainsString('parece un DNI, no un pasaporte', $pedido[0]);
    }

    public function testUnPasaporteSubidoComoDniSePide(): void
    {
        $pasaporte = $this->pasaporte(['tipo' => \App\Enum\DocumentoTipoEnum::PASAPORTE]);

        self::assertStringContainsString('parece un pasaporte, no tu DNI', Pedir::delDocumento(T::DNI_ANVERSO, $pasaporte, $this->hoy())[0]);
        self::assertStringContainsString('parte de atrás', Pedir::delDocumento(T::DNI_REVERSO, $pasaporte, $this->hoy())[0]);
    }

    /** ⚠️ Un carné de extranjería por el hueco del DNI NO se pide: quien no tiene DNI no tiene otra cosa. */
    public function testUnCarneDeExtranjeriaEnElHuecoDelDniNoSePide(): void
    {
        $carne = new DatosDeDocumento(tipo: \App\Enum\DocumentoTipoEnum::CE, numero: '001234567', vencimiento: new DateTimeImmutable('2030-01-01'));

        self::assertSame([], Pedir::delDocumento(T::DNI_ANVERSO, $carne, $this->hoy()));
    }

    public function testElPasaporteBienEtiquetadoSigueSinPedirNada(): void
    {
        self::assertSame([], Pedir::delDocumento(T::PASAPORTE, $this->pasaporte(['tipo' => \App\Enum\DocumentoTipoEnum::PASAPORTE]), $this->hoy()));
    }

    /** El reverso sólo tiene número en la banda: si la banda se vio pero se leyó mal, no es culpa suya. */
    public function testUnReversoConLaBandaMalTranscritaNoPideNada(): void
    {
        $malLeido = new DatosDeDocumento(tipo: \App\Enum\DocumentoTipoEnum::DNI, bandaVacia: false);

        self::assertSame([], Pedir::delDocumento(T::DNI_REVERSO, $malLeido, $this->hoy()));
    }

    public function testUnReversoSinBandaNiNumeroSiPideOtraFoto(): void
    {
        $vacio = new DatosDeDocumento(tipo: \App\Enum\DocumentoTipoEnum::DNI, bandaVacia: true);

        self::assertStringContainsString('No conseguimos leer', Pedir::delDocumento(T::DNI_REVERSO, $vacio, $this->hoy())[0]);
    }
}
