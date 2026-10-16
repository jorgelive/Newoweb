<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPago;
use DateTimeImmutable;
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

    /** Hallazgo A2: `'abc' > 0` es `true` en PHP 8, y esto llegaba a MySQL como un 500. */
    public function testUnImporteQueNoEsUnNumeroNoPasa(): void
    {
        foreach (['abc', '12,50', ' 5', '1e3', '0x1A', '10.123'] as $monto) {
            self::assertContains('monto', $this->rutasConError($this->pago()->setMonto($monto)), $monto);
        }
    }

    public function testUnTipoDeCambioConComaOAbsurdoNoPasa(): void
    {
        foreach (['3,40', '0.0001', '35', 'abc'] as $tc) {
            self::assertSame(['tipoCambio'], $this->rutasConError($this->pago()->setMoneda('PEN')->setTipoCambio($tc)), $tc);
        }
        self::assertSame(29.41, $this->pago()->setMoneda('PEN')->setMonto('100')->setTipoCambio('3.4')->equivalenteUsd());
    }

    public function testNiFechaFuturaNiPropuestaQueNoExiste(): void
    {
        self::assertSame(['fechaPago'], $this->rutasConError($this->pago()->setFechaPago(new DateTimeImmutable('tomorrow'))));
        self::assertSame(['propuesta'], $this->rutasConError($this->pago()->setPropuesta(99)));

        $file = new CotizacionFile();
        $file->addCotizacion((new Cotizacion())->setPropuesta(2));
        self::assertSame([], $this->rutasConError($this->pago()->setFile($file)->setPropuesta(2)));
    }

    public function testElPasajeroTieneQueSerDelExpediente(): void
    {
        $otro = (new CotizacionFilepasajero())->setFile(new CotizacionFile());
        self::assertSame(['pasajero'], $this->rutasConError($this->pago()->setPasajero($otro)));
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
