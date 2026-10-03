<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Message;

use App\Finanzas\Entity\FinEnlacePago;
use App\Pms\Service\Message\PagoRecibido;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** El importe de la confirmación: el TOTAL cobrado a la tarjeta, no el neto que abona la reserva. */
#[CoversClass(PagoRecibido::class)]
final class PagoRecibidoTest extends TestCase
{
    public function testElImporteEsLoQueSeCobroALaTarjeta(): void
    {
        $enlace = $this->createStub(FinEnlacePago::class);
        $enlace->method('getMonedaCodigo')->willReturn('USD');
        $enlace->method('getMontoNeto')->willReturn('51.32');
        $enlace->method('getMontoTotal')->willReturn('54.14');

        self::assertSame('USD 54.14', PagoRecibido::importe($enlace));
    }
}
