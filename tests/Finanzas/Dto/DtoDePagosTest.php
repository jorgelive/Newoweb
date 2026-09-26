<?php

declare(strict_types=1);

namespace App\Tests\Finanzas\Dto;

use App\Finanzas\Dto\AvisoDePasarela;
use App\Finanzas\Dto\CuerpoDeEnlace;
use App\Finanzas\Dto\RespuestaCulqi;
use App\Finanzas\Dto\TransaccionDePasarela;
use App\Finanzas\Enum\FinPasarela;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Los DTO de la frontera de pagos. Que leen lo mismo que el código de antes sobre los datos reales
 * lo comprueba `tools/pruebas/probar-dto-pagos.php`; esto fija las formas raras y las decisiones.
 */
#[CoversClass(RespuestaCulqi::class)]
#[CoversClass(AvisoDePasarela::class)]
#[CoversClass(TransaccionDePasarela::class)]
#[CoversClass(CuerpoDeEnlace::class)]
final class DtoDePagosTest extends TestCase
{
    /** Un cargo denegado también es un `charge`: el veredicto va aparte, en `outcome`. */
    public function testUnCargoDenegadoEsCargoPeroSuVeredictoNoEsVenta(): void
    {
        $r = RespuestaCulqi::fromArray(['object' => 'charge', 'amount' => 10550, 'currency_code' => 'PEN',
            'outcome' => ['type' => 'venta_denegada', 'code' => 'DNGE0116', 'decline_code' => 'authentication_required']]);

        self::assertTrue($r->esCargo());
        self::assertSame('venta_denegada', $r->resultadoTipo);
        self::assertSame('DNGE0116', $r->resultadoCodigo);
        self::assertNull($r->codigo, 'el código de la raíz es otro campo');
        self::assertSame(10550, $r->importeCentimos);
    }

    public function testUnCuerpoDeErrorTraeSusMotivosEnLaRaiz(): void
    {
        $r = RespuestaCulqi::fromArray(['object' => 'error', 'user_message' => 'Fondos insuficientes', 'code' => 'card_declined']);

        self::assertFalse($r->esCargo());
        self::assertSame('Fondos insuficientes', $r->detalle());
        self::assertSame('card_declined', $r->codigo);
    }

    /** Un array donde se esperaba el motivo no se convierte en «Array» dentro de una excepción. */
    public function testUnMotivoQueNoEsTextoCaeAlSiguiente(): void
    {
        $r = RespuestaCulqi::fromArray(['user_message' => ['raro'], 'merchant_message' => 'Rechazado']);

        self::assertSame('Rechazado', $r->detalle());
    }

    public function testElAvisoDeCulqiTomaElPrimerCargoYElEnlaceDeSusDosSitios(): void
    {
        $a = AvisoDePasarela::deCulqi(['id' => 'evt_1', 'data' => ['id' => 'chr_9', 'metadata' => ['ordenId' => 'OP-1']], 'metadata' => ['enlaceId' => 'abc']]);

        self::assertSame('chr_9', $a->idCargo, 'el id del evento (evt_) no es un cargo');
        self::assertSame('abc', $a->enlaceId, 'sin enlace en data.metadata, el de la raíz');
        self::assertSame('OP-1', $a->ordenId);
    }

    public function testLaTransaccionNormalizadaSeLeeUnaVez(): void
    {
        $t = TransaccionDePasarela::primeraDe(['transactions' => [['uuid' => 'u1', 'transactionDetails' => ['cardDetails' => [
            'effectiveBrand' => 'VISA', 'pan' => '****1111', 'authorizationResponse' => ['authorizationNumber' => '123456']]]]]]);

        self::assertSame(['u1', '123456', 'VISA', '****1111'], [$t->uuid, $t->autorizacion, $t->marca, $t->pan]);
        self::assertNull(TransaccionDePasarela::primeraDe(['transactions' => 'nada'])->uuid);
    }

    /**
     * ⚠️ Era `(bool) ($datos['conRecargo'] ?? true)`, y `(bool) "false"` es `true`: un «false» en
     * texto emitía el cobro CON recargo.
     */
    public function testConRecargoEsUnBooleanoDeVerdad(): void
    {
        self::assertFalse(CuerpoDeEnlace::fromArray(['conRecargo' => 'false'])->conRecargo);
        self::assertFalse(CuerpoDeEnlace::fromArray(['conRecargo' => false])->conRecargo);
        self::assertTrue(CuerpoDeEnlace::fromArray([])->conRecargo, 'sin decirlo, con recargo: el valor de siempre');
    }

    public function testElImporteViajaComoTextoYLaPasarelaDesconocidaEsNull(): void
    {
        $c = CuerpoDeEnlace::fromArray(['monto' => 120.5, 'pasarela' => 'paypal', 'moneda' => ' USD ']);

        self::assertSame('120.5', $c->monto);
        self::assertNull($c->pasarela);
        self::assertSame('USD', $c->moneda);
        self::assertSame(FinPasarela::CULQI, CuerpoDeEnlace::fromArray(['pasarela' => 'culqi'])->pasarela);
    }
}
