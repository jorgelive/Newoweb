<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Dto;

use App\Contract\VinculoComercial;
use App\Cotizacion\Dto\CuentaDelExpediente;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionPago;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Cotizacion\Enum\FileEstadoEnum;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La cuenta de un expediente: contra qué propuesta se mide el saldo, cómo cuenta un pago en
 * soles y qué no cuenta. Y el vínculo comercial que de ahí sale para el agente.
 */
#[CoversClass(CuentaDelExpediente::class)]
#[CoversClass(CotizacionPago::class)]
final class CuentaDelExpedienteTest extends TestCase
{
    public function testSinPropuestaQueMandeNoHaySaldoQueInventar(): void
    {
        $file = new CotizacionFile();
        $this->propuesta($file, 1, CotizacionEstadoEnum::ENVIADO, '800');
        $this->propuesta($file, 2, CotizacionEstadoEnum::ENVIADO, '900');
        $this->pago($file, '100', 'USD');

        $cuenta = CuentaDelExpediente::de($file);

        self::assertNull($cuenta->totalUsd);
        self::assertNull($cuenta->saldoUsd);
        self::assertSame(100.0, $cuenta->pagadoUsd);
    }

    public function testLaVendidaMandaSobreLaQueNombraElPago(): void
    {
        $file = new CotizacionFile();
        $this->propuesta($file, 1, CotizacionEstadoEnum::ENVIADO, '800');
        $this->propuesta($file, 2, CotizacionEstadoEnum::CONFIRMADO, '900');
        $this->pago($file, '450', 'USD', propuesta: 1);

        $cuenta = CuentaDelExpediente::de($file);

        self::assertSame(2, $cuenta->propuestaBase);
        self::assertSame(900.0, $cuenta->totalUsd);
        self::assertSame(450.0, $cuenta->saldoUsd);
        self::assertNull($cuenta->propuestaSinConfirmar, 'Ya hay una confirmada: no hay nada que avisar');
    }

    public function testSinVendidaMandaLaQueSePagaYSeAvisaDeConfirmarla(): void
    {
        $file = new CotizacionFile();
        $this->propuesta($file, 1, CotizacionEstadoEnum::ENVIADO, '800');
        $this->propuesta($file, 2, CotizacionEstadoEnum::ENVIADO, '900');
        $this->pago($file, '450', 'USD', propuesta: 2);

        $cuenta = CuentaDelExpediente::de($file);

        self::assertSame(2, $cuenta->propuestaBase);
        self::assertSame(450.0, $cuenta->saldoUsd);
        // Registrar el pago NO confirma: se avisa.
        self::assertSame(2, $cuenta->propuestaSinConfirmar);
    }

    public function testConUnaSolaPropuestaEsEsaAunqueElPagoNoLaNombre(): void
    {
        $file = new CotizacionFile();
        $this->propuesta($file, 1, CotizacionEstadoEnum::ENVIADO, '840');
        $this->propuesta($file, 1, CotizacionEstadoEnum::HISTORICO, '700'); // foto congelada: no cuenta
        $this->pago($file, '420', 'USD');

        self::assertSame(420.0, CuentaDelExpediente::de($file)->saldoUsd);
    }

    public function testUnPagoEnSolesCuentaPorSuEquivalenteYLosAnuladosNoCuentan(): void
    {
        $file = new CotizacionFile();
        $this->propuesta($file, 1, CotizacionEstadoEnum::CONFIRMADO, '1000');
        $this->pago($file, '1700', 'PEN', tipoCambio: '3.4000'); // 500 USD
        $this->pago($file, '300', 'USD')->anular('Duplicado', null, new DateTimeImmutable());

        $cuenta = CuentaDelExpediente::de($file);

        self::assertSame(500.0, $cuenta->pagadoUsd);
        self::assertSame(500.0, $cuenta->saldoUsd);
        self::assertSame(['PEN' => 1700.0], $cuenta->pagadoPorMoneda);
    }

    public function testSinBaseLoPagadoDeUnaPropuestaIncluyeLoQueNoNombraNinguna(): void
    {
        $file = new CotizacionFile();
        $this->propuesta($file, 1, CotizacionEstadoEnum::ENVIADO, '800');
        $this->propuesta($file, 2, CotizacionEstadoEnum::ENVIADO, '900');
        $this->pago($file, '100', 'USD', propuesta: 1);
        $this->pago($file, '50', 'USD');

        // La base es la 1 (la nombra el último pago con propuesta): cuenta todo.
        self::assertSame(150.0, CuentaDelExpediente::pagadoDePropuesta($file, 1)['usd']);
        // La hermana sólo lo que la nombra.
        self::assertSame(0.0, CuentaDelExpediente::pagadoDePropuesta($file, 2)['usd']);
    }

    /**
     * 🔥 El hallazgo A1 de la revisión: un pago a la v1, cancelada, bajaba el saldo de la v2 en util
     * y en pax la v2 seguía pidiendo el adelanto entero. Ahora las dos pantallas leen la misma regla.
     */
    public function testUtilYPaxDicenLoMismoDeLaPropuestaBase(): void
    {
        $file = new CotizacionFile();
        $this->propuesta($file, 1, CotizacionEstadoEnum::CANCELADO, '800');
        $this->propuesta($file, 2, CotizacionEstadoEnum::ENVIADO, '900');
        $this->pago($file, '450', 'USD', propuesta: 1);

        $cuenta = CuentaDelExpediente::de($file);

        self::assertSame(2, $cuenta->propuestaBase);
        self::assertSame($cuenta->pagadoUsd, CuentaDelExpediente::pagadoDePropuesta($file, 2)['usd']);
    }

    public function testLoPagadoPorMonedaEsLoQueSeTransfirio(): void
    {
        $file = new CotizacionFile();
        $this->propuesta($file, 1, CotizacionEstadoEnum::ENVIADO, '1000');
        $this->pago($file, '1700', 'PEN', tipoCambio: '3.4000');
        $this->pago($file, '100', 'USD');

        self::assertSame(['usd' => 600.0, 'porMoneda' => ['PEN' => 1700.0, 'USD' => 100.0]], CuentaDelExpediente::pagadoDePropuesta($file, 1));
    }

    public function testUnaOperativaTomaElTotalDeSuConfirmada(): void
    {
        $file = new CotizacionFile();
        // La confirmada no está en la colección del expediente (llega como proxy por `derivadaDe`).
        $operativa = $this->propuesta($file, 1, CotizacionEstadoEnum::OPERATIVA, '0');
        $operativa->setDerivadaDe((new Cotizacion())->setPropuesta(1)->setTotalVenta('950')->setEstado(CotizacionEstadoEnum::CONFIRMADO));

        self::assertSame(950.0, CuentaDelExpediente::de($file)->totalUsd);
    }

    /** El panel de enlaces cobra la fila VENDIDA de cada número, nunca su operativa ni una descartada. */
    public function testCadaPropuestaConSuFilaQueSeCobraYSuSaldo(): void
    {
        $file = new CotizacionFile();
        $this->propuesta($file, 1, CotizacionEstadoEnum::CERRADO, '700');
        $confirmada = $this->propuesta($file, 2, CotizacionEstadoEnum::CONFIRMADO, '900');
        $this->propuesta($file, 2, CotizacionEstadoEnum::OPERATIVA, '0');
        $this->propuesta($file, 3, CotizacionEstadoEnum::ENVIADO, '1200');
        $this->pago($file, '300', 'USD', propuesta: 2);

        $lista = CuentaDelExpediente::propuestas($file);

        self::assertSame([2, 3], array_map(static fn ($p) => $p->propuesta, $lista));
        self::assertSame((string) $confirmada->getId(), $lista[0]->cotizacionId);
        self::assertSame([900.0, 300.0, 600.0], [$lista[0]->totalUsd, $lista[0]->pagadoUsd, $lista[0]->saldoUsd]);
        // La hermana no se queda con lo pagado por la vendida.
        self::assertSame([0.0, 1200.0], [$lista[1]->pagadoUsd, $lista[1]->saldoUsd]);
    }

    public function testElSaldoDeUnEnlaceNuncaEsNegativo(): void
    {
        $file = new CotizacionFile();
        $cot = $this->propuesta($file, 1, CotizacionEstadoEnum::ENVIADO, '100');
        $this->pago($file, '150', 'USD', propuesta: 1);

        self::assertSame(0.0, \App\Cotizacion\Finanzas\CotizacionOrigenCobroResolver::saldoUsd($file, $cot));
    }

    public function testPagarHaceClienteYAnularLoDeshace(): void
    {
        $file = new CotizacionFile();
        self::assertSame(VinculoComercial::Interesado, $file->vinculoComercial());

        $pago = $this->pago($file, '100', 'USD');
        self::assertSame(VinculoComercial::Cliente, $file->vinculoComercial());

        $pago->anular('Se registró en otro expediente', null, new DateTimeImmutable());
        self::assertSame(VinculoComercial::Interesado, $file->vinculoComercial());

        $file->setEstado(FileEstadoEnum::ARCHIVADO);
        self::assertSame(VinculoComercial::Terminado, $file->vinculoComercial());
    }

    public function testUnPagoAnuladoNoVuelveYSinMotivoNoSeAnula(): void
    {
        $pago = $this->pago(new CotizacionFile(), '100', 'USD');

        try {
            $pago->anular('  ', null, new DateTimeImmutable());
            self::fail('Sin motivo no se anula');
        } catch (\DomainException) {
            self::assertFalse($pago->isAnulado());
        }

        $pago->anular('Error de importe', null, new DateTimeImmutable());
        $this->expectException(\DomainException::class);
        $pago->anular('Otra vez', null, new DateTimeImmutable());
    }

    private function propuesta(CotizacionFile $file, int $numero, CotizacionEstadoEnum $estado, string $total): Cotizacion
    {
        $cotizacion = (new Cotizacion())->setPropuesta($numero)->setTotalVenta($total)->setEstado($estado)->setPublicado(true);
        $file->addCotizacion($cotizacion);

        return $cotizacion;
    }

    private function pago(CotizacionFile $file, string $monto, string $moneda, ?int $propuesta = null, ?string $tipoCambio = null): CotizacionPago
    {
        $pago = (new CotizacionPago())->setFile($file)->setMonto($monto)->setMoneda($moneda)
            ->setTipoCambio($tipoCambio)->setPropuesta($propuesta);
        $file->getPagos()->add($pago);

        return $pago;
    }
}
