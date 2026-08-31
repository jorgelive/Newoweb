<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Enum\PmsTipoCargo;

/**
 * Lo que se debe y lo que se ha cobrado en cada moneda de una ficha, calculado en memoria.
 *
 * ── Por qué existe, teniendo la tabla ───────────────────────────────────────
 * `pms_finanzas_total_moneda` la escribe SQL crudo en `postFlush`, así que **dentro de la misma
 * petición que acaba de añadir un cobro puede ir un paso por detrás**. Ese desfase ya causó un
 * bug latente: `RegistrarPagoSkill` y `RegistrarCargoSkill` releen `$info->getSaldo()` tras el
 * flush con el comentario «lo recalcula el listener», y no es cierto — el rollup no pasa por el
 * `UnitOfWork` y la entidad conserva los valores viejos.
 *
 * Este objeto suma desde `$info->getCargos()` y `$info->getPagos()`, que son las colecciones que
 * el ORM sí tiene al día. Quien tenga la ficha en la mano usa esto; quien lea decenas de fichas
 * de golpe —el calendario— usa la tabla.
 *
 * ⚠️ **Es un ESPEJO del SQL de `PmsInformacionFinancieraRecalculoService::recalcularPorMoneda()`.**
 * Las dos fórmulas tienen que decir lo mismo o el panel y la decisión de `pago-total` discreparán.
 * Si cambia una, cambia la otra. Es el único espejo que queda en el módulo, y sustituye a uno
 * peor: el de `expresionConvertida()` ↔ `aMonedaBase()`, que convertía y por tanto podía
 * desincronizarse en silencio.
 *
 * ── Qué NO hace ─────────────────────────────────────────────────────────────
 * No convierte. Los importes se suman por moneda tal como se pactaron. La única excepción es un
 * cobro que declara saldar la deuda de otra moneda ({@see PmsPagoFinanciero::$monedaSaldada}), y
 * sólo porque ahí el dinero de verdad cruzó.
 */
final readonly class PmsTotalesPorMoneda
{
    /**
     * Suelo de la tolerancia del cuadre, en la moneda de la ficha.
     *
     * Cubre lo que no escala con el importe: redondear el vuelto al sol o al dólar entero. En una
     * reserva pequeña es toda la tolerancia que hay.
     */
    public const string UMBRAL_CUADRE_MINIMO = '1.00';

    /**
     * Parte proporcional de la tolerancia: 0.1 % del importe que cruzó de moneda.
     *
     * ── De dónde sale, y por qué no es una constante ────────────────────────────
     * El residuo **no lo genera nuestro redondeo**: convertir con `round(…, 2)` produce como mucho
     * medio céntimo. Lo genera que **el cambio del mostrador nunca es exactamente el de la ficha**
     * — se paga a la tasa del día del banco, de Yape o de la calle, y el cuadre usa la de SUNAT.
     *
     * Ese error es **proporcional al importe convertido**, no al total ni fijo. El tipo de cambio
     * se guarda con tres decimales, así que dos tasas plausibles difieren en unos pocos milésimos:
     * sobre 750 dólares, 0.002 de diferencia son 1.50 soles ≈ 0.44 USD. Con una constante de 1.00,
     * una reserva de 750 pasa y una de 3.000 se queda colgada por dos dólares que no son un error
     * de nadie.
     *
     * 0.1 % es aproximadamente **tres milésimos de tasa**, que es el margen entre cotizaciones
     * reales de un mismo día. Más ancho empezaría a tapar errores de verdad.
     *
     * ⚠️ Calibrado con poca evidencia —hoy sólo hay dos cruces con residuo, de 0.00 y 0.10—, así
     * que es un número a revisar cuando haya más casos, no una constante física. La forma
     * (suelo + proporción) sí está fundamentada; el 0.1 % es la parte que puede moverse.
     */
    public const string UMBRAL_CUADRE_PROPORCION = '0.001';

    /**
     * @param array<string, array{cargos: string, pagos: string, saldo: string}> $porMoneda
     *        Indexado por id de moneda, ordenado alfabéticamente.
     * @param string $monedaCuadre Moneda en la que se expresa el cuadre.
     * @param string $cuadre       Los saldos de todas las monedas llevados a `$monedaCuadre`.
     * @param string $tolerancia   Hasta cuánto cuenta como «cuadrado». `0.00` si no hubo conversión.
     */
    private function __construct(
        public array $porMoneda,
        public string $monedaCuadre,
        public string $cuadre,
        public string $tolerancia,
        public ?string $tipoCambio,
    ) {
    }

    /**
     * La única puerta. Devuelve el objeto ya calculado desde las colecciones de la ficha.
     *
     * El constructor es privado por lo mismo que en `MomentoDeHito`: si se pudiera hacer `new`,
     * la garantía de que estas cifras salen del espejo correcto duraría hasta el primero que lo
     * hiciera.
     */
    public static function de(PmsInformacionFinanciera $info): self
    {
        $acumulado = [];

        /** Suma en la moneda que toque, creando la fila si es la primera vez. */
        $sumar = static function (string $moneda, float $cargo, float $pago) use (&$acumulado): void {
            $acumulado[$moneda] ??= ['cargos' => 0.0, 'pagos' => 0.0];
            $acumulado[$moneda]['cargos'] += $cargo;
            $acumulado[$moneda]['pagos'] += $pago;
        };

        foreach ($info->getCargos() as $cargo) {
            if (!self::cargoCuenta($cargo, $info)) {
                continue;
            }

            $sumar(self::monedaDe($cargo->getMoneda()?->getId()), (float) ($cargo->getTotalLinea() ?? $cargo->getMonto() ?? '0'), 0.0);
        }

        foreach ($info->getPagos() as $pago) {
            $imputado = self::importeImputado($pago);

            if ($imputado !== null) {
                $sumar((string) $pago->getMonedaSaldada()?->getId(), 0.0, $imputado);

                continue;
            }

            $sumar(self::monedaDe($pago->getMoneda()?->getId()), 0.0, (float) $pago->getMonto());
        }

        ksort($acumulado);

        $porMoneda = [];
        foreach ($acumulado as $moneda => $cifras) {
            $porMoneda[$moneda] = [
                'cargos' => self::dosDecimales($cifras['cargos']),
                'pagos' => self::dosDecimales($cifras['pagos']),
                'saldo' => self::dosDecimales($cifras['cargos'] - $cifras['pagos']),
            ];
        }

        $base = self::monedaDe($info->getMoneda()?->getId());
        $tc = $info->getTipoCambio();

        [$cuadre, $convertido] = self::calcularCuadre($porMoneda, $base, $tc);

        return new self($porMoneda, $base, $cuadre, self::tolerancia($convertido, count($porMoneda) > 1), $tc);
    }

    /** ¿Hay saldo pendiente en alguna moneda? Es la pregunta estricta, sin umbral. */
    public function quedaAlgoPorCobrar(): bool
    {
        foreach ($this->porMoneda as $cifras) {
            if ((float) $cifras['saldo'] > 0) {
                return true;
            }
        }

        return false;
    }

    /** ¿Hay algo que cobrar? Una ficha sin cargos no está «pagada»: es que no se ha cobrado. */
    public function hayCargos(): bool
    {
        foreach ($this->porMoneda as $cifras) {
            if ((float) $cifras['cargos'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las monedas con algún movimiento, en orden alfabético.
     *
     * @return list<string>
     */
    public function monedas(): array
    {
        return array_keys($this->porMoneda);
    }

    /** ¿Esta ficha tiene deuda o cobros en más de una moneda? */
    public function esMixta(): bool
    {
        return count($this->porMoneda) > 1;
    }

    /**
     * ¿Hay alguna moneda que el cuadre no pudo convertir por falta de tipo de cambio?
     *
     * Su saldo **no está** en `$cuadre`: se descartó en vez de inventarlo. Quien lea el cuadre sin
     * preguntar esto estará leyendo una cifra a la que le falta una deuda entera.
     *
     * ⚠️ ESPEJO de `monedas_sin_convertir` en `PmsEstadoPagoEventosService::subconsultaDeCuadre()`.
     * Con el sello de la cabecera no debería ser `true` nunca; existe para el día que la
     * cotización no esté disponible al crear la ficha.
     */
    public function hayMonedaSinConvertir(): bool
    {
        if (!$this->esMixta()) {
            return false;
        }

        return (float) ($this->tipoCambio ?? 0) <= 0.0;
    }

    /**
     * ¿El cuadre da «cero» dentro de lo razonable?
     *
     * Con una sola moneda no hay nada que cuadrar: la respuesta es si esa moneda está saldada, y
     * ahí sí se exige exactitud — no hay ningún cambio de por medio que justifique un residuo.
     */
    public function cuadra(): bool
    {
        if (!$this->esMixta()) {
            return !$this->quedaAlgoPorCobrar();
        }

        // Sin tipo de cambio falta una moneda entera dentro de `$cuadre`. Que dé cero no significa
        // que esté pagada, significa que no se pudo mirar: se contesta que NO cuadra, que es la
        // respuesta segura y la misma que da el SQL del estado de pago.
        if ($this->hayMonedaSinConvertir()) {
            return false;
        }

        // `<=` y no valor absoluto: un SOBREPAGO deja el cuadre negativo y tiene que contar como
        // pagada, igual que hacía el `<= 0` del modelo viejo. Con `abs()`, quien paga de más se
        // quedaría en «parcial» para siempre.
        return (float) $this->cuadre <= (float) $this->tolerancia;
    }

    /**
     * ¿El huésped pagó de MÁS, más allá de lo que explica el redondeo del cambio?
     *
     * No es lo contrario de {@see self::cuadra()} y no debe confundirse con él: un sobrepago
     * **está pagado** —no se le puede exigir nada— y por eso `cuadra()` da `true` y la estancia
     * pasa a `pago-total`, igual que en el modelo viejo. Pero alguien tiene que enterarse: son
     * S/ 10 del huésped que están en nuestra caja.
     *
     * Se mide contra la misma tolerancia que el resto: por debajo de ella es el redondeo del
     * cambio y no hay nada que devolver.
     */
    public function haySaldoAFavor(): bool
    {
        return (float) $this->cuadre < -((float) $this->tolerancia);
    }

    /**
     * ¿Es un CRUCE de monedas, o sea, hay dinero que sólo puede pertenecer a otra deuda?
     *
     * La señal es una moneda con **cobros y sin ningún cargo**: ese dinero no puede saldar nada
     * suyo. Es lo que distingue a `GASUNN` —pagó en soles una deuda en dólares— de `XK6FV4`, que
     * simplemente debe en dos monedas.
     *
     * ⚠️ Mira SÓLO el lado de los cobros. Exigir además una moneda «con cargos y sin cobros»
     * dejaba fuera a `XTHRMQ`, cuya deuda en soles está pagada a medias en soles y a medias en
     * dólares, y que es un cruce de manual.
     */
    public function hayCruceDeMonedas(): bool
    {
        foreach ($this->porMoneda as $cifras) {
            if ((float) $cifras['pagos'] != 0.0 && (float) $cifras['cargos'] == 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Conviene proponerle al operador que impute un cobro?
     *
     * Cuando hay un cruce y el cuadre cae dentro del umbral, lo que queda es el redondeo del
     * cambio: un clic lo cierra. Si el cuadre se sale, no es redondeo y hay que mirarlo.
     */
    public function sugiereImputacion(): bool
    {
        return $this->esMixta() && $this->hayCruceDeMonedas() && $this->cuadra();
    }

    // ── Reglas espejo del SQL ────────────────────────────────────────────────

    /**
     * ¿Este cargo entra en la suma?
     *
     * Espejo de `WHERE COALESCE(c.tipo,'charge') = 'charge' AND i2.activa = 1`. En una ficha
     * ANULADA **no cuenta nada**: los cargos siguen en la tabla —no se pierde historia— pero
     * dejan de sumar, la penalización incluida. Ver §12.7 y el aviso de abajo.
     *
     * ⚠️ **Un cargo con `tipo` a `null` cuenta como cargo**, y no es indulgencia: `$tipo` recibe
     * su valor en `prePersist` (`aplicarDefectosDeCargoManual()`), así que una entidad **recién
     * añadida y todavía sin flush lo tiene vacío** — y leer la ficha justo en ese momento es para
     * lo que existe este objeto. Sin esto, un cargo recién creado desaparecía de la suma.
     *
     * Lo cazó el test unitario, no el análisis estático. El SQL lleva el mismo `COALESCE` para que
     * el espejo sea exacto: en la base hoy no hay ni una fila con `tipo` nulo, pero la columna lo
     * admite y dos criterios distintos sobre la misma fila es justo lo que no puede pasar aquí.
     */
    private static function cargoCuenta(PmsCargoFinanciero $cargo, PmsInformacionFinanciera $info): bool
    {
        if ($cargo->getTipo() !== null && !$cargo->esCargo()) {
            return false;
        }

        // ⚠️ **La penalización YA NO es la excepción** (31/08/2026). Decía
        // `|| $cargo->getTipoCargo() === PmsTipoCargo::PENALIZACION`: en una ficha anulada los
        // cargos de la estancia dejaban de sumar pero la penalización seguía contando, porque
        // la regla se escribió dando por hecho que una cancelación se puede cobrar.
        //
        // **Y no se puede.** Bajo las condiciones actuales de las OTA no tenemos acceso a la
        // tarjeta del huésped, así que una penalización de una reserva cancelada es deuda que
        // nadie va a reclamar — y una reserva cancelada además desaparece del panel, así que
        // nadie la vuelve a mirar. El resultado era saldo vivo, invisible y falso.
        //
        // ⚠️ **El cargo NO se borra**: sigue en la tabla con su importe, sólo deja de sumar. El
        // día que la OTA dé acceso a la tarjeta se revierte esta línea y vuelven a contar todas,
        // también las viejas. Que se vuelve a poner **aquí** lo recuerda el propio panel: la
        // ficha de una reserva anulada con penalización lo dice en pantalla, que es donde se
        // mira. Ver `docs/PmsBeds24ReservasSync.md` §12.7.
        return $info->isActiva();
    }

    /**
     * Lo que este cobro abona a OTRA moneda, o `null` si salda la suya.
     *
     * Espejo de `expresionImputa()` + la rama 2b del `UNION ALL`. Las tres condiciones —lo declara,
     * hay tipo de cambio, el par está soportado— tienen que cumplirse todas: si no, el cobro cae
     * en su propia moneda y **no se pierde**. Es la diferencia con el rollup viejo, donde una fila
     * sin tipo de cambio aportaba 0 y se evaporaba en silencio.
     *
     * `round()` aquí y no al final: 223.70 / 3.391 = 65.9687…, y sin redondear por fila el saldo
     * se queda en 0.0013 y no llega nunca a cero exacto.
     */
    private static function importeImputado(PmsPagoFinanciero $pago): ?float
    {
        if (!$pago->imputaAOtraMoneda()) {
            return null;
        }

        $tc = (float) $pago->getTipoCambio();

        if ($tc <= 0.0) {
            return null;
        }

        $propia = self::monedaDe($pago->getMoneda()?->getId());
        $destino = (string) $pago->getMonedaSaldada()?->getId();
        $monto = (float) $pago->getMonto();

        return match (true) {
            $propia === 'PEN' && $destino === 'USD' => round($monto / $tc, 2),
            $propia === 'USD' && $destino === 'PEN' => round($monto * $tc, 2),
            // Par no soportado: se queda en su moneda, como en el SQL.
            default => null,
        };
    }

    /**
     * Los saldos de todas las monedas, llevados a la de cuadre con UN solo tipo de cambio.
     *
     * Sin tipo de cambio no se inventa nada: se devuelve el saldo de la moneda de cuadre y las
     * demás se quedan fuera. Es preferible a dar una cifra que nadie pactó — y con el sello
     * automático del TC no debería pasar nunca.
     *
     * @param array<string, array{cargos: string, pagos: string, saldo: string}> $porMoneda
     *
     * @return array{0: string, 1: float} El cuadre, y cuánto importe hubo que convertir para
     *                                    obtenerlo — que es lo que fija la tolerancia.
     */
    private static function calcularCuadre(array $porMoneda, string $base, ?string $tipoCambio): array
    {
        $tc = (float) $tipoCambio;
        $total = 0.0;
        $convertido = 0.0;

        foreach ($porMoneda as $moneda => $cifras) {
            $saldo = (float) $cifras['saldo'];

            if ($moneda === $base) {
                $total += $saldo;

                continue;
            }

            if ($tc <= 0.0) {
                continue;
            }

            $enBase = match (true) {
                $moneda === 'USD' && $base === 'PEN' => $saldo * $tc,
                $moneda === 'PEN' && $base === 'USD' => $saldo / $tc,
                default => $saldo,
            };

            $total += $enBase;
            // En valor absoluto: lo que importa es cuánto dinero pasó por una tasa, no en qué
            // dirección. Dos saldos de signo opuesto no cancelan su incertidumbre, la suman.
            $convertido += abs($enBase);
        }

        return [self::dosDecimales($total), $convertido];
    }

    /**
     * Cuánta diferencia se admite: un suelo fijo más una parte proporcional a lo convertido.
     *
     * Sin conversión no hay tolerancia. Es la regla entera: la holgura la concede **el hecho de
     * haber pasado por una tasa de cambio**, no el tamaño de la reserva. Quien deba 0.50 en una
     * sola moneda debe 0.50.
     */
    private static function tolerancia(float $convertido, bool $huboConversion): string
    {
        if (!$huboConversion) {
            return '0.00';
        }

        return self::dosDecimales(max(
            (float) self::UMBRAL_CUADRE_MINIMO,
            $convertido * (float) self::UMBRAL_CUADRE_PROPORCION,
        ));
    }

    /** `COALESCE(moneda_id, 'USD')` del SQL: una fila sin moneda cuenta como dólares. */
    private static function monedaDe(?string $moneda): string
    {
        return ($moneda === null || $moneda === '') ? 'USD' : $moneda;
    }

    private static function dosDecimales(float $valor): string
    {
        return number_format($valor, 2, '.', '');
    }
}
