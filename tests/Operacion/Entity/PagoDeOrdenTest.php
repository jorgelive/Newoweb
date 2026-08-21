<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Entity;

use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Entity\Maestro\MaestroMoneda;
use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionPago;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\OperacionMedioPago;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Los pagos a cuenta de una orden de servicio.
 *
 * Dos reglas, y las dos existían **sólo en el panel** hasta ahora — o sea, no existían: un POST
 * directo se las saltaba, y lo que entra por ahí es dinero declarado como salido.
 */
final class PagoDeOrdenTest extends TestCase
{
    #[Test]
    public function no_se_paga_en_una_moneda_que_la_orden_no_tiene(): void
    {
        // El caso que abre el agujero: la orden se negoció en dólares y entra un abono en soles.
        // Como el saldo se agrupa por moneda, ese pago aparece como una fila con `pagado` y sin
        // `real` — dinero contra una deuda que no existe, y sin un solo error.
        $orden = $this->ordenEn('USD');
        $pago = $this->pago($orden, 'PEN');

        self::assertSame(
            ['moneda' => 'Esta orden se maneja en USD: no se le puede registrar un pago en PEN.'],
            $this->violaciones($pago)
        );
    }

    #[Test]
    public function en_la_moneda_de_la_orden_pasa(): void
    {
        $orden = $this->ordenEn('USD');

        self::assertSame([], $this->violaciones($this->pago($orden, 'USD')));
    }

    #[Test]
    public function una_orden_con_dos_monedas_admite_las_dos(): void
    {
        // Pasa de verdad: se cotiza en dólares y se cierra en soles. `getTotalesPorMoneda()` ya
        // reparte el importe entre las dos, así que las dos son monedas de la orden.
        $orden = new OperacionOrdenServicio();
        $orden->addOperacionServicio($this->servicio('USD', 'PEN'));

        self::assertSame([], $this->violaciones($this->pago($orden, 'PEN')));
        self::assertSame([], $this->violaciones($this->pago($orden, 'USD')));
    }

    #[Test]
    public function una_orden_sin_importes_no_acota_nada(): void
    {
        // Negarlo bloquearía el adelanto de una orden recién emitida, que es justo cuando más
        // se adelanta. Sin monedas no hay regla que cumplir.
        self::assertSame([], $this->violaciones($this->pago(new OperacionOrdenServicio(), 'PEN')));
    }

    #[Test]
    public function las_monedas_admitidas_NO_salen_de_los_pagos_ya_hechos(): void
    {
        // ⚠️ La trampa que evita `monedasDeLosServicios()`: si la lista se sacara de
        // `getTotalesPorMoneda()` —que incluye las monedas de los pagos, para no esconder
        // ninguno— el primer abono equivocado legitimaría al segundo.
        $orden = $this->ordenEn('USD');
        $orden->addPago($this->pago($orden, 'PEN'));   // uno colado antes de existir la regla

        self::assertSame(['USD'], $orden->monedasDeLosServicios());
        self::assertNotSame([], $this->violaciones($this->pago($orden, 'PEN')));
    }

    #[Test]
    public function saldada_es_falso_mientras_falte_pagar(): void
    {
        $orden = $this->ordenEn('USD', '100.00');
        $orden->addPago($this->pago($orden, 'USD', '40.00'));

        self::assertFalse($orden->isSaldada());
    }

    #[Test]
    public function saldada_cuando_no_queda_nada_en_ninguna_moneda(): void
    {
        $orden = $this->ordenEn('USD', '100.00');
        $orden->addPago($this->pago($orden, 'USD', '100.00'));

        self::assertTrue($orden->isSaldada());
    }

    #[Test]
    public function una_orden_SIN_importes_no_esta_saldada(): void
    {
        // Con todo a cero el saldo también da cero. Decir «saldada» ahí es afirmar algo sobre
        // un dinero que nadie ha escrito todavía: está sin cargar, no pagada.
        self::assertFalse((new OperacionOrdenServicio())->isSaldada());
        self::assertFalse($this->ordenEn('USD', '0.00')->isSaldada());
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function ordenEn(string $moneda, string $costo = '100.00'): OperacionOrdenServicio
    {
        $orden = new OperacionOrdenServicio();
        $orden->addOperacionServicio($this->servicio($moneda, $moneda, $costo));

        return $orden;
    }

    private function servicio(string $cotizada, string $negociada, string $costo = '100.00'): OperacionServicio
    {
        $servicio = new OperacionServicio();
        // Sin tarifa el servicio es «sólo referencia» y `addOperacionServicio()` lo rechaza:
        // una OS es una solicitud de COMPRA y no se le compra a nadie lo que no se cotizó. Se
        // le pone una en vez de esquivar la guarda por reflexión, para que el doble represente
        // un servicio que de verdad podría entrar en una orden.
        $servicio->setCotizacionTarifa(new CotizacionCottarifa());
        $servicio->setCostoCotizado($costo);
        $servicio->setMonedaCotizada($this->moneda($cotizada));
        $servicio->setCostoNegociado($costo);
        $servicio->setMonedaNegociada($this->moneda($negociada));

        return $servicio;
    }

    private function moneda(string $id): MaestroMoneda
    {
        return new MaestroMoneda($id, $id, $id);
    }

    private function pago(OperacionOrdenServicio $orden, string $moneda, string $monto = '50.00'): OperacionPago
    {
        return (new OperacionPago())
            ->setOrdenServicio($orden)
            ->setMoneda($this->moneda($moneda))
            ->setMonto($monto)
            ->setFecha(new \DateTimeImmutable('2026-08-21'))
            ->setMedioPago(OperacionMedioPago::TRANSFERENCIA_BANCARIA);
    }

    /**
     * Corre la validación de la moneda y devuelve `[campo => mensaje]`.
     *
     * Con dobles y no con el validador de Symfony: la regla es un `Assert\Callback` sobre la
     * entidad y lo que se prueba es SU decisión, no el cableado del componente.
     *
     * @return array<string, string>
     */
    private function violaciones(OperacionPago $pago): array
    {
        $recogidas = [];

        $constructor = $this->createStub(ConstraintViolationBuilderInterface::class);
        $constructor->method('atPath')->willReturnCallback(
            static function (string $campo) use (&$recogidas, $constructor): ConstraintViolationBuilderInterface {
                $recogidas['campo'] = $campo;

                return $constructor;
            }
        );
        $constructor->method('addViolation')->willReturnCallback(static function () use (&$recogidas): void {
            $recogidas['puesta'] = true;
        });

        $contexto = $this->createStub(ExecutionContextInterface::class);
        $contexto->method('buildViolation')->willReturnCallback(
            static function (string $mensaje) use (&$recogidas, $constructor): ConstraintViolationBuilderInterface {
                $recogidas['mensaje'] = $mensaje;

                return $constructor;
            }
        );

        $pago->validarMonedaDeLaOrden($contexto);

        return isset($recogidas['puesta'])
            ? [$recogidas['campo'] => $recogidas['mensaje']]
            : [];
    }
}
