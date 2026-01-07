<?php
declare(strict_types=1);

namespace App\Pms\Service\Tarifa\Engine;

use App\Pms\Service\Tarifa\Dto\TarifaLogicalRangeDto;
use DateTimeInterface;

/**
 * Fachada para:
 * - aplanar rangos solapados a precio por día (intervalo)
 * - compactar a rangos lógicos (efectivos)
 *
 * Este servicio NO sabe de Beds24: produce rangos neutrales.
 */
final class TarifaPricingEngine
{
    public function __construct(
        private TarifaDailyPriceFlattener $flattener,
        private TarifaLogicalRangeCompressor $compressor
    ) {
    }

    /**
     * Punto de entrada si quieres "rangos efectivos" compactados,
     * sin rellenar huecos.
     *
     * @param array<int, object|array<string,mixed>> $rangos
     * @param callable $rangeAccessor fn($r): array{
     *   start:DateTimeInterface,
     *   end:DateTimeInterface,
     *   price:float|int|string,
     *   minStay?:int|null,
     *   currency?:string|null,
     *   important?:bool,
     *   weight?:int,
     *   id?:int|string|null
     * }
     * @param callable|null $priorityComparator fn($a,$b): int
     *
     * @return TarifaLogicalRangeDto[]
     */
    public function buildLogicalRangesForInterval(
        array $rangos,
        DateTimeInterface $from,
        DateTimeInterface $to,
        callable $rangeAccessor,
        ?callable $priorityComparator = null
    ): array {
        $daily = $this->flattener->flatten($rangos, $from, $to, $rangeAccessor, $priorityComparator);

        return $this->compressor->compress($daily);
    }

    /**
     * Variante robusta:
     * Genera rangos lógicos para TODO el intervalo [from, to),
     * rellenando huecos con fallback (por ejemplo, tarifa base de la unidad).
     *
     * Importante:
     * - El fallback se aplica en el flattener (por día).
     * - El compressor solo compacta.
     *
     * @param array<int, object|array<string,mixed>> $rangos
     * @param callable $rangeAccessor fn($r): array{
     *   start:DateTimeInterface,
     *   end:DateTimeInterface,
     *   price:float|int|string,
     *   minStay?:int|null,
     *   currency?:string|null,
     *   important?:bool,
     *   weight?:int,
     *   id?:int|string|null
     * }
     * @param callable|null $priorityComparator fn($a,$b): int
     * @param callable|null $fallbackProvider fn(DateTimeInterface $day): ?array{
     *   price:float|int|string,
     *   minStay?:int|null,
     *   currency?:string|null,
     *   sourceId?:string|null
     * }
     *
     * @return TarifaLogicalRangeDto[]
     */
    public function buildLogicalRangesForIntervalWithFallback(
        array $rangos,
        DateTimeInterface $from,
        DateTimeInterface $to,
        callable $rangeAccessor,
        ?callable $priorityComparator = null,
        ?callable $fallbackProvider = null
    ): array {
        $daily = $this->flattener->flatten(
            $rangos,
            $from,
            $to,
            $rangeAccessor,
            $priorityComparator,
            $fallbackProvider
        );

        return $this->compressor->compress($daily);
    }

    /**
     * Devuelve el mapa diario resuelto para el intervalo [from, to) (to exclusivo).
     * Útil si quieres debug, inspección o lógicas por día.
     *
     * @param array<int, object|array<string,mixed>> $rangos
     * @param callable $rangeAccessor fn($r): array{
     *   start:DateTimeInterface,
     *   end:DateTimeInterface,
     *   price:float|int|string,
     *   minStay?:int|null,
     *   currency?:string|null,
     *   important?:bool,
     *   weight?:int,
     *   id?:int|string|null
     * }
     * @param callable|null $priorityComparator fn($a,$b): int
     *
     * @return array<string, array{price:float, minStay:int, currency:?string, sourceId:string}>
     */
    public function buildDailyPricesForInterval(
        array $rangos,
        DateTimeInterface $from,
        DateTimeInterface $to,
        callable $rangeAccessor,
        ?callable $priorityComparator = null
    ): array {
        return $this->flattener->flatten($rangos, $from, $to, $rangeAccessor, $priorityComparator);
    }
}