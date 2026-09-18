<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Qué ve el pasajero: sin configurar manda el tipo, configurado manda el expediente.
 *
 * Ver `docs/Cotizaciones.md` — la exposición por expediente.
 */
#[CoversClass(CotizacionFile::class)]
final class DocumentosParaPasajeroTest extends TestCase
{
    /** Los 300 expedientes que ya existen no cambian de comportamiento: `null` = lo que diga el tipo. */
    public function testSinConfigurarMandaElValorPorDefectoDelTipo(): void
    {
        $file = new CotizacionFile();

        self::assertNull($file->getDocumentosParaPasajero());
        self::assertTrue($file->exponeAlPasajero(ArchivoTipoEnum::TICKET_AEREO));
        self::assertTrue($file->exponeAlPasajero(ArchivoTipoEnum::ETICKET));
        self::assertFalse($file->exponeAlPasajero(ArchivoTipoEnum::PASAPORTE));
    }

    public function testLoConfiguradoSustituyeAlValorPorDefecto(): void
    {
        $file = (new CotizacionFile())->setDocumentosParaPasajero([ArchivoTipoEnum::PASAPORTE->value]);

        // Lo marcado entra, aunque el tipo diga que no…
        self::assertTrue($file->exponeAlPasajero(ArchivoTipoEnum::PASAPORTE));
        // …y lo que no está marcado sale, aunque el tipo diga que sí.
        self::assertFalse($file->exponeAlPasajero(ArchivoTipoEnum::TICKET_AEREO));
    }

    /**
     * 🔥 **Vacío y sin configurar NO son lo mismo.** Si `[]` cayera en el default, guardar sin
     * marcar nada devolvería las tarjetas de embarque: justo lo contrario de lo que se acaba de
     * pedir, y sin un solo error.
     */
    public function testLaListaVaciaEsUnaDecision(): void
    {
        $file = (new CotizacionFile())->setDocumentosParaPasajero([]);

        self::assertSame([], $file->getDocumentosParaPasajero());
        foreach (ArchivoTipoEnum::cases() as $tipo) {
            self::assertFalse($file->exponeAlPasajero($tipo), $tipo->value . ' no debería exponerse');
        }
    }

    /** Se normaliza al entrar: el selector manda lo que tenga marcado y un `json` guarda tal cual. */
    public function testSeQuitanLosRepetidosYSeReindexa(): void
    {
        $file = (new CotizacionFile())->setDocumentosParaPasajero(['reserva', 'reserva', 'otros']);

        self::assertSame(['reserva', 'otros'], $file->getDocumentosParaPasajero());
    }

    /** Restablecer es volver a `null`, que es distinto de desmarcar todo. */
    public function testRestablecerVuelveAlValorPorDefecto(): void
    {
        $file = (new CotizacionFile())->setDocumentosParaPasajero([]);
        $file->setDocumentosParaPasajero(null);

        self::assertNull($file->getDocumentosParaPasajero());
        self::assertTrue($file->exponeAlPasajero(ArchivoTipoEnum::TICKET_AEREO));
    }

    /**
     * La factura queda fuera de lo que se puede marcar: es el documento fiscal del titular, y en un
     * grupo el titular no es quien se identifica.
     */
    public function testLaFacturaNoEsExponible(): void
    {
        $exponibles = ArchivoTipoEnum::exponibles();

        self::assertNotContains(ArchivoTipoEnum::FACTURA->value, $exponibles);
        self::assertContains(ArchivoTipoEnum::TICKET_INGRESO->value, $exponibles);
        self::assertContains(ArchivoTipoEnum::PASAPORTE->value, $exponibles);
    }

    /** Los de identidad se pueden marcar, pero la UI tiene que avisar: hay menores. */
    public function testSoloLosEscaneosDeIdentidadSonSensibles(): void
    {
        foreach (ArchivoTipoEnum::cases() as $tipo) {
            self::assertSame(
                $tipo->esEscaneoDeIdentidad(),
                $tipo->exponerEsSensible(),
                $tipo->value . ': sensible y escaneo de identidad tienen que ir juntos',
            );
        }
    }

    /**
     * ⚠️ El tipo partido conserva lo que hacía el `boleto`: los tres tickets se le devuelven al
     * pasajero por defecto. Si alguno se cayera, un grupo entero se quedaría sin su tarjeta de
     * embarque en el aeropuerto y nadie lo vería hasta el mostrador.
     */
    public function testLosTresTicketsSiguenSiendoDevolvibles(): void
    {
        foreach ([ArchivoTipoEnum::TICKET_AEREO, ArchivoTipoEnum::TICKET_INGRESO, ArchivoTipoEnum::TICKET_TRANSPORTE] as $tipo) {
            self::assertTrue($tipo->esDevolvibleAlPasajero(), $tipo->value);
            self::assertTrue($tipo->esPublico(), $tipo->value);
            self::assertFalse($tipo->esValidable(), $tipo->value);
            self::assertFalse($tipo->loSubeElPasajero(), $tipo->value);
        }
    }
}
