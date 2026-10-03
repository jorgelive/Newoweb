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
    public function testElNetoMasLaComisionEnElIdiomaDelHuesped(): void
    {
        self::assertSame('USD 51.32 + USD 2.82 de comisión de la pasarela de pago', PagoRecibido::detalle($this->enlace('51.32', '54.14'), 'es'));
        self::assertSame('USD 51.32 + USD 2.82 de frais de plateforme de paiement', PagoRecibido::detalle($this->enlace('51.32', '54.14'), 'fr'));
    }

    public function testSinRecargoSoloElNeto(): void
    {
        self::assertSame('USD 120.00', PagoRecibido::detalle($this->enlace('120.00', '120.00'), 'es'));
    }

    public function testUnIdiomaQueNoTraducimosSaleEnIngles(): void
    {
        self::assertSame('USD 51.32 + a USD 2.82 payment gateway fee', PagoRecibido::detalle($this->enlace('51.32', '54.14'), 'ja'));
    }

    private function enlace(string $neto, string $total): FinEnlacePago
    {
        $enlace = $this->createStub(FinEnlacePago::class);
        $enlace->method('getMonedaCodigo')->willReturn('USD');
        $enlace->method('getMontoNeto')->willReturn($neto);
        $enlace->method('getMontoTotal')->willReturn($total);

        return $enlace;
    }
}
