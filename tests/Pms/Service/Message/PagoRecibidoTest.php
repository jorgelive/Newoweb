<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Message;

use App\Finanzas\Entity\FinEnlacePago;
use App\Pms\Service\Message\PagoRecibido;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Neto (lo que ve en su cuenta) + comisión = lo cobrado a la tarjeta (lo que ve en su banco). */
#[CoversClass(PagoRecibido::class)]
final class PagoRecibidoTest extends TestCase
{
    public function testElNetoMasLaComisionDanLoCobradoALaTarjeta(): void
    {
        $enlace = $this->createStub(FinEnlacePago::class);
        $enlace->method('getMonedaCodigo')->willReturn('USD');
        $enlace->method('getMontoNeto')->willReturn('51.32');
        $enlace->method('getMontoTotal')->willReturn('54.14');

        self::assertSame('USD 51.32', PagoRecibido::abonado($enlace));
        self::assertSame('USD 2.82', PagoRecibido::comision($enlace));
    }
}
