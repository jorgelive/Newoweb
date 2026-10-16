<?php

declare(strict_types=1);

namespace App\Cotizacion\Dto;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use Doctrine\Common\Collections\Criteria;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * La cuenta de un expediente: cuánto vale lo que se vende, cuánto se ha pagado y cuánto falta.
 *
 * Todo en DÓLARES, que es la moneda de los totales de la cotización: un pago en soles cuenta por
 * su equivalente al tipo de cambio de su día (`CotizacionPago::equivalenteUsd()`). Las sumas por
 * moneda, sin convertir, van aparte en `pagadoPorMoneda` para quien cuadra caja.
 *
 * ── ¿El total de qué propuesta? ─────────────────────────────────────────────
 * Un expediente tiene varias, y el saldo sólo tiene sentido contra una. Por orden:
 *
 * 1. **La vendida**: confirmada; si no, operada; si no, operativa (que toma el dinero de su
 *    confirmada, `Cotizacion::origenFinancieroParaCliente()`).
 * 2. **La que se está pagando**: la propuesta del último pago que la diga.
 * 3. **La única**: si sólo hay una propuesta viva, es ésa aunque el pago no la nombre.
 *
 * Si no hay ninguna, no hay total: se dice lo pagado y nada más. Inventar una base sería dar un
 * saldo falso con toda la seguridad del mundo.
 *
 * ⚠️ **El adelanto en dinero NO se calcula aquí**: se manda el porcentaje y lo convierte
 * `montoDeAdelanto()` (`dominio/cotizacion/adelanto.ts`), la misma función que el editor y `pax`.
 *
 * 🪞 Espejo: `util/src/types/fileDetalleModel.ts` (`CuentaDelExpediente`).
 */
final readonly class CuentaDelExpediente
{
    /** @param array<string, float> $pagadoPorMoneda Suma de lo que cuenta, por moneda y sin convertir. */
    public function __construct(
        #[Groups(['file:item:read'])]
        public ?int $propuestaBase,

        #[Groups(['file:item:read'])]
        public ?string $estadoBase,

        /** Total de venta de la propuesta base, en USD; `null` sin base. */
        #[Groups(['file:item:read'])]
        public ?float $totalUsd,

        /** El porcentaje de adelanto de la propuesta base; `null` sin base. */
        #[Groups(['file:item:read'])]
        public ?float $porcentajeAdelanto,

        #[Groups(['file:item:read'])]
        public float $pagadoUsd,

        /** Lo que falta, en USD. Negativo si se cobró de más; `null` sin base. */
        #[Groups(['file:item:read'])]
        public ?float $saldoUsd,

        #[Groups(['file:item:read'])]
        public array $pagadoPorMoneda,

        /**
         * Hay pagos y la propuesta que se paga sigue pendiente o enviada. Registrar un pago NO la
         * confirma —confirmar arma la operativa y lo decide una persona—: esto es el aviso.
         */
        #[Groups(['file:item:read'])]
        public ?int $propuestaSinConfirmar,
    ) {
    }

    public static function de(CotizacionFile $file): self
    {
        $pagadoUsd = 0.0;
        $porMoneda = [];
        $propuestaDelUltimoPago = null;

        foreach ($file->getPagos() as $pago) {
            if (!$pago->cuenta()) {
                continue;
            }

            $pagadoUsd += $pago->equivalenteUsd();
            $porMoneda[$pago->getMoneda()] = round(($porMoneda[$pago->getMoneda()] ?? 0.0) + (float) $pago->getMonto(), 2);
            $propuestaDelUltimoPago = $pago->getPropuesta() ?? $propuestaDelUltimoPago;
        }

        $pagadoUsd = round($pagadoUsd, 2);
        $vendida = self::vendida($file);
        $base = $vendida ?? self::laQueSePaga($file, $propuestaDelUltimoPago);

        if ($base === null) {
            return new self(null, null, null, null, $pagadoUsd, null, $porMoneda, null);
        }

        $total = round((float) $base->origenFinancieroParaCliente()->getTotalVenta(), 2);

        return new self(
            propuestaBase: $base->getPropuesta(),
            estadoBase: $base->getEstado()->value,
            totalUsd: $total,
            porcentajeAdelanto: $base->porcentajeDeAdelanto(),
            pagadoUsd: $pagadoUsd,
            saldoUsd: round($total - $pagadoUsd, 2),
            pagadoPorMoneda: $porMoneda,
            propuestaSinConfirmar: $vendida === null && $pagadoUsd > 0 ? $base->getPropuesta() : null,
        );
    }

    /**
     * Lo que el cliente ha pagado a cuenta de UNA propuesta: en USD y por moneda, sin convertir.
     *
     * 🔥 **La misma regla que el saldo, no una parecida** (revisión del 16/10/2026). Antes la ficha
     * sumaba todos los pagos y `pax` sólo los que nombraban la propuesta: un pago a la v1, ya
     * cancelada, bajaba el saldo de la v2 en util y en `pax` la v2 seguía pidiendo el adelanto
     * entero a quien ya había pagado. Ahora:
     *
     * - Si es la propuesta BASE de la cuenta → todo lo pagado, igual que el saldo.
     * - Si no hay base → los pagos que la nombran y los que no nombran ninguna.
     * - Si hay otra base → sólo los que la nombran: lo pagado por la vendida no es de la hermana.
     *
     * `porMoneda` es lo que de verdad se transfirió: `pax` lo enseña en la vista en soles en vez de
     * reconvertir los dólares al cambio de la cotización («yo mandé 1 700, ¿por qué dice 1 702?»).
     *
     * @return array{usd: float, porMoneda: array<string, float>}
     */
    public static function pagadoDePropuesta(CotizacionFile $file, int $propuesta): array
    {
        return self::pagadoContra($file, $propuesta, self::de($file)->propuestaBase);
    }

    /**
     * Cada propuesta viva (sin cerradas ni canceladas), con su total, lo pagado y el saldo.
     *
     * Una fila por número de propuesta: la confirmada (u operada) si la hay —es la que se vendió y
     * la que dice el dinero—; si no, la publicada. La operativa no: su dinero es el de su confirmada.
     *
     * @return list<PropuestaDeLaCuenta>
     */
    public static function propuestas(CotizacionFile $file): array
    {
        $base = self::de($file)->propuestaBase;
        $porNumero = [];

        foreach (self::vivas($file) as $cotizacion) {
            if (\in_array($cotizacion->getEstado(), [CotizacionEstadoEnum::CERRADO, CotizacionEstadoEnum::CANCELADO, CotizacionEstadoEnum::OPERATIVA], true)) {
                continue;
            }

            $numero = $cotizacion->getPropuesta();
            $actual = $porNumero[$numero] ?? null;

            if ($actual === null || self::rango($cotizacion) > self::rango($actual)) {
                $porNumero[$numero] = $cotizacion;
            }
        }

        ksort($porNumero);
        $lista = [];

        foreach ($porNumero as $numero => $cotizacion) {
            $id = $cotizacion->getId();
            if ($id === null) {
                continue;
            }

            $total = round((float) $cotizacion->origenFinancieroParaCliente()->getTotalVenta(), 2);
            $pagado = self::pagadoContra($file, $numero, $base)['usd'];

            $lista[] = new PropuestaDeLaCuenta(
                propuesta: $numero,
                cotizacionId: $id->toRfc4122(),
                estado: $cotizacion->getEstado()->value,
                totalUsd: $total,
                pagadoUsd: $pagado,
                saldoUsd: max(0.0, round($total - $pagado, 2)),
                porcentajeAdelanto: $cotizacion->porcentajeDeAdelanto(),
            );
        }

        return $lista;
    }

    /** Cuál de las filas de un mismo número es la que se cobra: vendida > publicada > el resto. */
    private static function rango(Cotizacion $cotizacion): int
    {
        return match (true) {
            \in_array($cotizacion->getEstado(), [CotizacionEstadoEnum::CONFIRMADO, CotizacionEstadoEnum::OPERADO], true) => 2,
            $cotizacion->isPublicado() => 1,
            default => 0,
        };
    }

    /**
     * La regla de lo pagado contra una propuesta, sabiendo ya cuál es la base (ver
     * {@see self::pagadoDePropuesta()}). Aparte para no recalcular la base en cada propuesta.
     *
     * @return array{usd: float, porMoneda: array<string, float>}
     */
    private static function pagadoContra(CotizacionFile $file, int $propuesta, ?int $base): array
    {
        $usd = 0.0;
        $porMoneda = [];

        foreach ($file->getPagos() as $pago) {
            if (!$pago->cuenta()) {
                continue;
            }

            $cuenta = match (true) {
                $base === $propuesta => true,
                $base === null => $pago->getPropuesta() === null || $pago->getPropuesta() === $propuesta,
                default => $pago->getPropuesta() === $propuesta,
            };

            if ($cuenta) {
                $usd += $pago->equivalenteUsd();
                $porMoneda[$pago->getMoneda()] = round(($porMoneda[$pago->getMoneda()] ?? 0.0) + (float) $pago->getMonto(), 2);
            }
        }

        return ['usd' => round($usd, 2), 'porMoneda' => $porMoneda];
    }

    /**
     * Las propuestas vivas: sin las fotos congeladas (históricos).
     *
     * ⚠️ **Con `Criteria`, no recorriendo la colección.** El provider público lee las propuestas con
     * una consulta escalar para no hidratar nunca las 100+ versiones de un expediente grande; un
     * `foreach` aquí las cargaría todas. `matching()` sobre la colección sin inicializar pide a la
     * base sólo las que casan; en util, donde ya está cargada, filtra en memoria.
     *
     * @return list<Cotizacion>
     */
    private static function vivas(CotizacionFile $file): array
    {
        return $file->cotizacionesQue(
            Criteria::create()->where(Criteria::expr()->neq('estado', CotizacionEstadoEnum::HISTORICO)),
        );
    }

    /**
     * La propuesta vendida del expediente: confirmada; si no, operada; si no, operativa.
     *
     * ⚠️ Supone **un viaje por expediente** (`ARCHIVADO` = el viaje terminó). Con dos vendidas, todo
     * lo pagado contaría contra la primera que encuentre. Un segundo viaje es otro expediente.
     */
    public static function vendida(CotizacionFile $file): ?Cotizacion
    {
        $vendidas = $file->cotizacionesQue(Criteria::create()->where(Criteria::expr()->in('estado', [
            CotizacionEstadoEnum::CONFIRMADO, CotizacionEstadoEnum::OPERADO, CotizacionEstadoEnum::OPERATIVA,
        ])));

        foreach ([CotizacionEstadoEnum::CONFIRMADO, CotizacionEstadoEnum::OPERADO, CotizacionEstadoEnum::OPERATIVA] as $estado) {
            foreach ($vendidas as $cotizacion) {
                if ($cotizacion->getEstado() === $estado) {
                    return $cotizacion;
                }
            }
        }

        return null;
    }

    private static function laQueSePaga(CotizacionFile $file, ?int $propuesta): ?Cotizacion
    {
        $vivas = array_values(array_filter(
            self::vivas($file),
            // Una propuesta descartada no es la que se paga.
            static fn (Cotizacion $c): bool => !\in_array($c->getEstado(), [CotizacionEstadoEnum::CERRADO, CotizacionEstadoEnum::CANCELADO], true),
        ));

        if ($propuesta !== null) {
            $candidatas = array_values(array_filter($vivas, static fn (Cotizacion $c): bool => $c->getPropuesta() === $propuesta));
            // La publicada primero: es la que el cliente tiene delante.
            usort($candidatas, static fn (Cotizacion $a, Cotizacion $b): int => (int) $b->isPublicado() <=> (int) $a->isPublicado());

            if ($candidatas !== []) {
                return $candidatas[0];
            }
        }

        return \count($vivas) === 1 ? $vivas[0] : null;
    }
}
