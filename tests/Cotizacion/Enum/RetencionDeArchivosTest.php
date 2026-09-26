<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Enum;

use App\Cotizacion\Enum\ArchivoTipoEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Cuánto se guarda cada adjunto tras el retorno del grupo.
 *
 * Existe para que cambiar el plazo sea una DECISIÓN y no un efecto secundario: el número lo lee el
 * cron de purga cada madrugada, y un plazo que baja sin querer borra documentos que ya no se pueden
 * recuperar. Ver `docs/Cotizaciones.md` — caducidad de los adjuntos.
 */
#[CoversClass(ArchivoTipoEnum::class)]
final class RetencionDeArchivosTest extends TestCase
{
    /** Seis meses desde el 26/09/2026 (era uno), decidido al cerrar el grupo 5SRAJV. */
    public function testLosDocumentosDelViajeSeGuardanSeisMeses(): void
    {
        foreach ([
            ArchivoTipoEnum::PASAPORTE,
            ArchivoTipoEnum::DNI_ANVERSO,
            ArchivoTipoEnum::DNI_REVERSO,
            ArchivoTipoEnum::AUTORIZACION,
            ArchivoTipoEnum::ETICKET,
            ArchivoTipoEnum::TICKET_AEREO,
            ArchivoTipoEnum::TICKET_INGRESO,
            ArchivoTipoEnum::TICKET_TRANSPORTE,
        ] as $tipo) {
            self::assertSame(6, $tipo->mesesDeRetencion(), $tipo->value);
        }
    }

    /**
     * Lo que sostiene el expediente meses después no caduca, y `OTROS` tampoco: no sabemos qué hay
     * dentro, y borrar por defecto lo no clasificado es cómo se pierde el único ejemplar de algo.
     */
    public function testLoQueNoCaduca(): void
    {
        foreach ([ArchivoTipoEnum::FACTURA, ArchivoTipoEnum::RESERVA, ArchivoTipoEnum::OTROS] as $tipo) {
            self::assertNull($tipo->mesesDeRetencion(), $tipo->value);
        }
    }
}
