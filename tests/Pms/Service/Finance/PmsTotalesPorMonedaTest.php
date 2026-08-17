<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Finance;

use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use PHPUnit\Framework\TestCase;

/**
 * Las cifras de una reserva, sumadas por moneda y sin convertir.
 *
 * ── Por qué estos tests y no otros ──────────────────────────────────────────
 * Este objeto es el **espejo en PHP** del SQL de
 * `PmsInformacionFinancieraRecalculoService::recalcularPorMoneda()`. Si los dos dejan de decir lo
 * mismo, el panel enseñará un saldo y la decisión de `pago-total` tomará otro — y esa decisión
 * encadena hasta los códigos de acceso que recibe el huésped. Lo que se prueba aquí es cada regla
 * del SQL, una a una, y los cuatro casos reales que hay hoy en producción.
 *
 * Es además la **primera cobertura del módulo financiero**: hasta ahora todo se verificaba
 * ejecutando el flujo real, porque el resto del módulo necesita base de datos. Este objeto no,
 * y por eso se escribió como PHP puro.
 */
final class PmsTotalesPorMonedaTest extends TestCase
{
    private MaestroMoneda $usd;
    private MaestroMoneda $pen;

    protected function setUp(): void
    {
        $this->usd = $this->moneda('USD');
        $this->pen = $this->moneda('PEN');
    }

    // ── Lo básico ────────────────────────────────────────────────────────────

    public function testUnaSolaMonedaDaUnaSolaFila(): void
    {
        $info = $this->ficha();
        $this->cargo($info, '100.00', $this->usd);
        $this->pago($info, '40.00', $this->usd);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame(['USD'], $totales->monedas());
        self::assertSame('100.00', $totales->porMoneda['USD']['cargos']);
        self::assertSame('40.00', $totales->porMoneda['USD']['pagos']);
        self::assertSame('60.00', $totales->porMoneda['USD']['saldo']);
        self::assertFalse($totales->esMixta());
    }

    public function testCadaMonedaSeSumaPorSuLadoYNoSeConvierte(): void
    {
        $info = $this->ficha();
        $this->cargo($info, '100.00', $this->usd);
        $this->cargo($info, '50.00', $this->pen);
        $this->pago($info, '30.00', $this->pen);

        $totales = PmsTotalesPorMoneda::de($info);

        // Alfabético: es el mismo orden que el `ORDER BY moneda_id` de la lectura en lote.
        self::assertSame(['PEN', 'USD'], $totales->monedas());
        self::assertSame('20.00', $totales->porMoneda['PEN']['saldo']);
        self::assertSame('100.00', $totales->porMoneda['USD']['saldo']);
        self::assertTrue($totales->esMixta());
    }

    public function testUnRegistroSinMonedaCuentaComoDolares(): void
    {
        // Espejo de `COALESCE(moneda_id, 'USD')`: hay filas antiguas sin moneda y no pueden
        // desaparecer de la suma por eso.
        $info = $this->ficha();
        $this->cargo($info, '10.00', null);

        self::assertSame(['USD'], PmsTotalesPorMoneda::de($info)->monedas());
    }

    public function testUnaFilaEnCeroSeConserva(): void
    {
        // Una estancia directa nace con una línea a 0.00 y es donde el operador teclea el precio.
        // Si la fila desapareciera, el panel no tendría dónde enseñarla.
        $info = $this->ficha();
        $this->cargo($info, '0.00', $this->usd);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame(['USD'], $totales->monedas());
        self::assertFalse($totales->hayCargos(), 'una línea en cero no es «algo que cobrar»');
    }

    public function testElTotalLineaMandaSobreElMonto(): void
    {
        // Espejo de `COALESCE(c.total_linea, c.monto, 0)`.
        $info = $this->ficha();
        $cargo = $this->cargo($info, '10.00', $this->usd);
        $cargo->setTotalLinea('99.00');

        self::assertSame('99.00', PmsTotalesPorMoneda::de($info)->porMoneda['USD']['cargos']);
    }

    // ── La ficha anulada ─────────────────────────────────────────────────────

    public function testEnUnaFichaAnuladaSoloCuentaLaPenalizacion(): void
    {
        $info = $this->ficha(activa: false);
        $this->cargo($info, '300.00', $this->usd, PmsTipoCargo::ALOJAMIENTO);
        $this->cargo($info, '50.00', $this->usd, PmsTipoCargo::PENALIZACION);

        self::assertSame('50.00', PmsTotalesPorMoneda::de($info)->porMoneda['USD']['cargos']);
    }

    public function testEnUnaFichaAnuladaLosCOBROSSIGUENCONTANDO(): void
    {
        // ⚠️ La asimetría es deliberada y es la trampa más cara del rediseño: el dinero entró
        // igual y ocultarlo sería mentir. Antes el neteo lo escondía; ahora sale como saldo
        // negativo, y está bien que se vea. Lo que NO puede pasar es que «ninguna moneda debe» se
        // lea como «pagada» — de eso se ocupa `hayCargos()`.
        $info = $this->ficha(activa: false);
        $this->cargo($info, '300.00', $this->usd, PmsTipoCargo::ALOJAMIENTO);
        $this->pago($info, '120.00', $this->usd);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame('0.00', $totales->porMoneda['USD']['cargos']);
        self::assertSame('120.00', $totales->porMoneda['USD']['pagos']);
        self::assertSame('-120.00', $totales->porMoneda['USD']['saldo']);
        self::assertFalse($totales->quedaAlgoPorCobrar());
        self::assertFalse($totales->hayCargos(), 'sin cargos NO se puede leer como saldada');
    }

    // ── La imputación entre monedas ──────────────────────────────────────────

    public function testUnCobroImputadoAbonaLaDeudaDeLaOtraMoneda(): void
    {
        // El caso GASUNN, con sus cifras reales: 65.97 × 3.391 = 223.70 exacto.
        $info = $this->ficha();
        $this->cargo($info, '65.97', $this->usd);
        $pago = $this->pago($info, '223.70', $this->pen, tipoCambio: '3.391');
        $pago->setMonedaSaldada($this->usd);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame(['USD'], $totales->monedas(), 'la fila en soles desaparece: ese cobro ya no es suyo');
        self::assertSame('65.97', $totales->porMoneda['USD']['pagos']);
        self::assertSame('0.00', $totales->porMoneda['USD']['saldo']);
        self::assertFalse($totales->quedaAlgoPorCobrar());
    }

    public function testElRedondeoVaPorFilaYNoAlFinal(): void
    {
        // 223.70 / 3.391 = 65.9687…; sin `round()` por fila el saldo se queda en 0.0013 y no
        // llega nunca a cero exacto, que es lo que decide si la reserva está pagada.
        $info = $this->ficha();
        $this->cargo($info, '65.97', $this->usd);
        $pago = $this->pago($info, '223.70', $this->pen, tipoCambio: '3.391');
        $pago->setMonedaSaldada($this->usd);

        self::assertSame('0.00', PmsTotalesPorMoneda::de($info)->porMoneda['USD']['saldo']);
    }

    public function testUnCobroImputadoSINTipoDeCambioNoSePierde(): void
    {
        // Es el fallo que venimos a matar: en el modelo viejo una fila sin TC aportaba 0 y
        // desaparecía en silencio. Aquí cae en su propia moneda y sigue estando.
        $info = $this->ficha();
        $this->cargo($info, '65.97', $this->usd);
        $pago = $this->pago($info, '223.70', $this->pen, tipoCambio: null);
        $pago->setMonedaSaldada($this->usd);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame(['PEN', 'USD'], $totales->monedas());
        self::assertSame('223.70', $totales->porMoneda['PEN']['pagos']);
        self::assertSame('65.97', $totales->porMoneda['USD']['saldo']);
    }

    public function testImputarASuPropiaMonedaNoHaceNada(): void
    {
        // El setter normaliza a null: «salda lo suyo» es exactamente lo que significa null, y
        // guardarlo explícito obligaría a una rama de más en el SQL y aquí.
        $info = $this->ficha();
        $pago = $this->pago($info, '50.00', $this->usd, tipoCambio: '3.400');
        $pago->setMonedaSaldada($this->usd);

        self::assertNull($pago->getMonedaSaldada());
        self::assertFalse($pago->imputaAOtraMoneda());
        self::assertSame('50.00', PmsTotalesPorMoneda::de($info)->porMoneda['USD']['pagos']);
    }

    // ── El cuadre ────────────────────────────────────────────────────────────

    public function testConUnaSolaMonedaElCuadreEsElSaldo(): void
    {
        $info = $this->ficha(tipoCambio: '3.400');
        $this->cargo($info, '100.00', $this->usd);
        $this->pago($info, '100.00', $this->usd);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame('0.00', $totales->cuadre);
        self::assertTrue($totales->cuadra());
    }

    public function testConUnaSolaMonedaElCuadreEsESTRICTO(): void
    {
        // Sin cambio de por medio no hay residuo que justificar: 0.50 pendientes son 0.50
        // pendientes, y el umbral no aplica.
        $info = $this->ficha(tipoCambio: '3.400');
        $this->cargo($info, '100.50', $this->usd);
        $this->pago($info, '100.00', $this->usd);

        self::assertFalse(PmsTotalesPorMoneda::de($info)->cuadra());
    }

    /**
     * Sin tipo de cambio en la ficha, «cuadra» tiene que ser NO — aunque el número dé cero.
     *
     * Es el hueco que apareció al revisar el 16/08/2026. La ficha nueva nacía sin cambio de
     * cuadre (la migración sólo rellenó las 317 que ya existían), y sin él la moneda que no es la
     * base se descarta del cuadre en vez de convertirse. Una ficha en soles con los soles saldados
     * y dólares pendientes daba cuadre 0.00 → `pago-total` → `confirmarPorPago()` → los códigos de
     * acceso de la casa abiertos a quien todavía debe.
     *
     * Que dé cero no significa que esté pagada: significa que no se pudo mirar.
     */
    public function testSinTipoDeCambioNoCuadraAunqueElNumeroDeCero(): void
    {
        $info = $this->ficha(base: $this->pen, tipoCambio: null);
        $this->cargo($info, '200.00', $this->pen);
        $this->pago($info, '200.00', $this->pen);   // soles saldados
        $this->cargo($info, '65.97', $this->usd);   // …y dólares pendientes

        $totales = PmsTotalesPorMoneda::de($info);

        // El cuadre sólo ve los soles: los dólares no se pudieron traer y NO se inventan.
        self::assertSame('0.00', $totales->cuadre);
        self::assertTrue($totales->hayMonedaSinConvertir());
        self::assertFalse($totales->cuadra(), 'Falta una deuda entera dentro de esa cifra.');
        self::assertTrue($totales->quedaAlgoPorCobrar(), 'Los 65.97 siguen debiéndose.');
    }

    public function testConUnaSolaMonedaLaFaltaDeTipoDeCambioDaIgual(): void
    {
        // No hay nada que convertir, así que el campo no pinta nada y no puede estorbar: una
        // ficha en dólares saldada está saldada, tenga o no cambio de cuadre.
        $info = $this->ficha(tipoCambio: null);
        $this->cargo($info, '80.00', $this->usd);
        $this->pago($info, '80.00', $this->usd);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertFalse($totales->hayMonedaSinConvertir());
        self::assertTrue($totales->cuadra());
    }

    public function testElCuadreDeUnCruceRealCaeDentroDelUmbral(): void
    {
        // XTHRMQ: base soles, debe 176.90 en soles y tiene 52.00 dólares a favor, al 3.400.
        // 176.90 − (52.00 × 3.400) = +0.10 — el redondeo del cambio.
        $info = $this->ficha(base: $this->pen, tipoCambio: '3.400');
        $this->cargo($info, '226.90', $this->pen);
        $this->pago($info, '50.00', $this->pen);
        $this->pago($info, '52.00', $this->usd);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame('0.10', $totales->cuadre);
        self::assertTrue($totales->cuadra());
        self::assertTrue($totales->hayCruceDeMonedas());
        self::assertTrue($totales->sugiereImputacion());
    }

    public function testLaToleranciaCreceConLoQueSeConvirtio(): void
    {
        // El punto entero: el cambio del mostrador nunca es el de la ficha, y ese error es
        // proporcional. Con una constante de 1.00, una reserva de 750 dólares pagada en soles se
        // quedaría colgada por medio dólar que no es error de nadie.
        //
        // Deuda de 750 USD, pagada con S/ 2551.50 al 3.400 → 750.44 USD imputados. Sobran 0.44.
        $info = $this->ficha(tipoCambio: '3.400');
        $this->cargo($info, '750.00', $this->usd);
        $this->pago($info, '2551.50', $this->pen);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame('-0.44', $totales->cuadre);
        self::assertSame('1.00', $totales->tolerancia, 'el suelo manda hasta que la proporción lo supera');
        self::assertTrue($totales->cuadra());
    }

    public function testEnImportesGrandesMandaLaProporcion(): void
    {
        // 5.000 USD convertidos: 0.1 % son 5.00, muy por encima del suelo de 1.00.
        $info = $this->ficha(tipoCambio: '3.400');
        $this->cargo($info, '5000.00', $this->usd);
        $this->pago($info, '17000.00', $this->pen);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame('5.00', $totales->tolerancia);
        self::assertTrue($totales->cuadra());
    }

    public function testSinConversionNoHayNingunaTolerancia(): void
    {
        // La holgura la concede haber pasado por una tasa, no el tamaño de la reserva: en una
        // sola moneda, deber 0.50 sobre 5.000 sigue siendo deber 0.50.
        $info = $this->ficha(tipoCambio: '3.400');
        $this->cargo($info, '5000.50', $this->usd);
        $this->pago($info, '5000.00', $this->usd);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame('0.00', $totales->tolerancia);
        self::assertFalse($totales->cuadra());
    }

    public function testUnSobrepagoGrandeSIGUECONTANDOComoPagada(): void
    {
        // `<=` y no `abs()`: quien paga de más está pagado. Con valor absoluto se quedaría en
        // «parcial» para siempre.
        $info = $this->ficha(tipoCambio: '3.400');
        $this->cargo($info, '100.00', $this->usd);
        $this->pago($info, '1000.00', $this->pen);

        self::assertTrue(PmsTotalesPorMoneda::de($info)->cuadra());
    }

    public function testUnaDeudaRealEnDosMonedasNOCuadraYNoSugiereNada(): void
    {
        // XK6FV4: dólares saldados y S/ 50 pendientes. No es un residuo, es dinero.
        $info = $this->ficha(tipoCambio: '3.400');
        $this->cargo($info, '420.86', $this->usd);
        $this->pago($info, '420.86', $this->usd);
        $this->cargo($info, '50.00', $this->pen);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame('14.71', $totales->cuadre);
        self::assertFalse($totales->cuadra());
        self::assertFalse($totales->hayCruceDeMonedas(), 'los soles tienen cargo propio: no es un cruce');
        self::assertFalse($totales->sugiereImputacion());
    }

    public function testUnSobrepagoEstaPAGADOPeroSeSenala(): void
    {
        // ZHX76S: dólares saldados y S/ 10 de más. Dos afirmaciones que hay que sostener a la vez
        // y que es fácil confundir:
        //   · CUADRA: no se le puede exigir nada al huésped, así que la estancia queda pagada —
        //     es lo mismo que hacía el modelo viejo con `saldo -2.97 <= 0`.
        //   · Y AUN ASÍ hay que avisar: son S/ 10 suyos que están en nuestra caja, y 2.94 está muy
        //     por encima de lo que explica el redondeo del cambio.
        $info = $this->ficha(tipoCambio: '3.400');
        $this->cargo($info, '129.18', $this->usd);
        $this->pago($info, '129.18', $this->usd);
        $this->pago($info, '10.00', $this->pen);

        $totales = PmsTotalesPorMoneda::de($info);

        self::assertSame('-2.94', $totales->cuadre);
        self::assertTrue($totales->cuadra(), 'quien paga de más está pagado');
        self::assertTrue($totales->haySaldoAFavor(), '2.94 no lo explica el redondeo: hay dinero suyo');
        self::assertTrue($totales->hayCruceDeMonedas());
    }

    public function testUnResiduoDeCambioNOEsSaldoAFavor(): void
    {
        // GASUNN imputado: cuadre exacto. No hay nada que devolverle a nadie.
        $info = $this->ficha();
        $this->cargo($info, '65.97', $this->usd);
        $pago = $this->pago($info, '223.70', $this->pen, tipoCambio: '3.391');
        $pago->setMonedaSaldada($this->usd);

        self::assertFalse(PmsTotalesPorMoneda::de($info)->haySaldoAFavor());
    }

    public function testSinTipoDeCambioElCuadreNoSeInventa(): void
    {
        // Se devuelve el saldo de la moneda de cuadre y las demás quedan fuera. Preferible a dar
        // una cifra que nadie pactó — y con el sello automático del TC no debería pasar nunca.
        $info = $this->ficha(tipoCambio: null);
        $this->cargo($info, '100.00', $this->usd);
        $this->cargo($info, '50.00', $this->pen);

        self::assertSame('100.00', PmsTotalesPorMoneda::de($info)->cuadre);
    }

    // ── Fábricas ─────────────────────────────────────────────────────────────

    private function moneda(string $id): MaestroMoneda
    {
        return new MaestroMoneda($id, $id === 'PEN' ? 'Soles' : 'Dólares', $id === 'PEN' ? 'S/.' : 'US$');
    }

    private function ficha(bool $activa = true, ?MaestroMoneda $base = null, ?string $tipoCambio = '3.400'): PmsInformacionFinanciera
    {
        $info = new PmsInformacionFinanciera();
        $info->setActiva($activa);
        $info->setMoneda($base ?? $this->usd);
        $info->setTipoCambio($tipoCambio);

        return $info;
    }

    private function cargo(
        PmsInformacionFinanciera $info,
        string $monto,
        ?MaestroMoneda $moneda,
        PmsTipoCargo $tipo = PmsTipoCargo::OTRO,
    ): PmsCargoFinanciero {
        $cargo = new PmsCargoFinanciero();
        $cargo->setTipoCargo($tipo);
        $cargo->setMonto($monto);
        $cargo->setMoneda($moneda);
        $info->addCargo($cargo);

        return $cargo;
    }

    private function pago(
        PmsInformacionFinanciera $info,
        string $monto,
        ?MaestroMoneda $moneda,
        ?string $tipoCambio = '3.400',
    ): PmsPagoFinanciero {
        $pago = new PmsPagoFinanciero();
        $pago->setMonto($monto);
        $pago->setMoneda($moneda);
        $pago->setTipoCambio($tipoCambio);
        $pago->setMedioPago(PmsMedioPago::EFECTIVO);
        $info->addPago($pago);

        return $pago;
    }
}
