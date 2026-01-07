<?php
declare(strict_types=1);

namespace App\Pms\Service\Tarifa\Engine;

use App\Pms\Service\Tarifa\Dto\TarifaLogicalRangeDto;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Convierte un mapa diario (Y-m-d => {price,currency,minStay,sourceId})
 * a rangos lógicos (compactados).
 *
 * Importante:
 * - Para preservar trazabilidad del ganador, el merge exige también mismo sourceId.
 * - Este servicio NO rellena huecos: si necesitas huecos, rellénalos en el flattener (fallbackProvider).
 */
final class TarifaLogicalRangeCompressor
{
    /**
     * Compacta SOLO las fechas presentes en $daily.
     *
     * @param array<string, array{
     *   price: float|int,
     *   currency?: string|null,
     *   minStay?: int|null,
     *   sourceId?: string|null
     * }> $daily
     *
     * @return TarifaLogicalRangeDto[]
     */
    public function compress(array $daily): array
    {
        if (empty($daily)) {
            return [];
        }

        ksort($daily);

        $dates = array_keys($daily);

        $first = $daily[$dates[0]];

        $price = (float) ($first['price'] ?? 0);
        $currency = isset($first['currency']) ? (string) $first['currency'] : null;

        $minStay = isset($first['minStay']) ? (int) $first['minStay'] : 2;
        if ($minStay <= 0) {
            $minStay = 2;
        }

        $sourceId = isset($first['sourceId']) && is_string($first['sourceId']) && $first['sourceId'] !== ''
            ? $first['sourceId']
            : null;

        $start = $dates[0];
        $prev = $dates[0];

        $ranges = [];

        $n = count($dates);
        for ($i = 1; $i < $n; $i++) {
            $curDate = $dates[$i];
            $cur = $daily[$curDate];

            $curPrice = (float) ($cur['price'] ?? 0);
            $curCurrency = isset($cur['currency']) ? (string) $cur['currency'] : null;

            $curMinStay = isset($cur['minStay']) ? (int) $cur['minStay'] : 2;
            if ($curMinStay <= 0) {
                $curMinStay = 2;
            }

            $curSourceId = isset($cur['sourceId']) && is_string($cur['sourceId']) && $cur['sourceId'] !== ''
                ? $cur['sourceId']
                : null;

            $expectedNext = (new DateTimeImmutable($prev))->modify('+1 day')->format('Y-m-d');
            $isConsecutive = ($curDate === $expectedNext);

            $samePrice = ($curPrice === $price);
            $sameCurrency = ($curCurrency === $currency);
            $sameMinStay = ($curMinStay === $minStay);
            $sameSourceId = ($curSourceId === $sourceId);

            if ($isConsecutive && $samePrice && $sameCurrency && $sameMinStay && $sameSourceId) {
                $prev = $curDate;
                continue;
            }

            $ranges[] = $this->makeRange($start, $prev, $price, $currency, $minStay, $sourceId);

            $start = $curDate;
            $prev = $curDate;
            $price = $curPrice;
            $currency = $curCurrency;
            $minStay = $curMinStay;
            $sourceId = $curSourceId;
        }

        $ranges[] = $this->makeRange($start, $prev, $price, $currency, $minStay, $sourceId);

        return $ranges;
    }

    private function makeRange(
        string $startYmd,
        string $lastYmd,
        float $price,
        ?string $currency,
        int $minStay,
        ?string $sourceId
    ): TarifaLogicalRangeDto {
        $start = new DateTimeImmutable($startYmd);
        $last = new DateTimeImmutable($lastYmd);

        if ($last < $start) {
            throw new InvalidArgumentException('compress(): fechas inválidas.');
        }

        // end exclusivo: último día + 1
        $end = $last->modify('+1 day');

        return (new TarifaLogicalRangeDto($start, $end, $price, $currency, $minStay))
            ->withSourceId($sourceId);
    }
}