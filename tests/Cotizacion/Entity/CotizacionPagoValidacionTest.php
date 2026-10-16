<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionPago;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Lo que un pago no puede ser: sin importe, en otra moneda o en soles sin tipo de cambio. */
#[CoversClass(CotizacionPago::class)]
final class CotizacionPagoValidacionTest extends TestCase
{
    public function testUnPagoEnSolesSinTipoDeCambioNoSePuedeDescontar(): void
    {
        $pago = $this->pago()->setMoneda('PEN');

        self::assertSame(['tipoCambio'], $this->rutasConError($pago));
        self::assertSame(0.0, $pago->equivalenteUsd(), 'Sin tipo de cambio no se inventa uno');

        $pago->setTipoCambio('3.75');
        self::assertSame([], $this->rutasConError($pago));
    }

    public function testImporteYMoneda(): void
    {
        self::assertSame(['monto'], $this->rutasConError($this->pago()->setMonto('0')));
        self::assertSame(['moneda'], $this->rutasConError($this->pago()->setMoneda('EUR')));
    }

    private function pago(): CotizacionPago
    {
        return (new CotizacionPago())->setFile(new CotizacionFile())->setMonto('100')->setMoneda('USD');
    }

    /** @return list<string> */
    private function rutasConError(CotizacionPago $pago): array
    {
        $rutas = [];
        foreach ($this->validador()->validate($pago) as $violacion) {
            $rutas[] = $violacion->getPropertyPath();
        }

        return array_values(array_unique($rutas));
    }

    private function validador(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
