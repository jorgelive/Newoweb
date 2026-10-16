<?php

declare(strict_types=1);

namespace App\Cotizacion\Dto;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
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

    /** Lo que el cliente ha pagado a cuenta de UNA propuesta: los pagos que la nombran y los que no nombran ninguna. */
    public static function pagadoUsdDePropuesta(CotizacionFile $file, int $propuesta): float
    {
        $suma = 0.0;

        foreach ($file->getPagos() as $pago) {
            if ($pago->cuenta() && ($pago->getPropuesta() === null || $pago->getPropuesta() === $propuesta)) {
                $suma += $pago->equivalenteUsd();
            }
        }

        return round($suma, 2);
    }

    /** @return list<Cotizacion> Las propuestas vivas: sin las fotos congeladas (históricos). */
    private static function vivas(CotizacionFile $file): array
    {
        $vivas = [];

        foreach ($file->getCotizaciones() as $cotizacion) {
            if ($cotizacion->getEstado() !== CotizacionEstadoEnum::HISTORICO) {
                $vivas[] = $cotizacion;
            }
        }

        return $vivas;
    }

    private static function vendida(CotizacionFile $file): ?Cotizacion
    {
        foreach ([CotizacionEstadoEnum::CONFIRMADO, CotizacionEstadoEnum::OPERADO, CotizacionEstadoEnum::OPERATIVA] as $estado) {
            foreach (self::vivas($file) as $cotizacion) {
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
