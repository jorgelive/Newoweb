<?php
declare(strict_types=1);

namespace App\Pms\Service\Tarifa\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Rango lógico (efectivo) de tarifa.
 *
 * Convención:
 * - start inclusivo
 * - end exclusivo ("[start, end)")
 * - minStay: estancia mínima requerida
 *
 * Extras:
 * - sourceId: identificador del "rango ganador" (id o hash) para trazabilidad.
 */
final class TarifaLogicalRangeDto
{
    private DateTimeImmutable $start;
    private DateTimeImmutable $end;
    private float $price;
    private int $minStay;
    private ?string $currency;
    private ?string $sourceId;

    public function __construct(
        DateTimeInterface $start,
        DateTimeInterface $end,
        float $price,
        ?string $currency = null,
        int $minStay = 2,
        ?string $sourceId = null
    ) {
        $this->start = self::toDateImmutable($start);
        $this->end = self::toDateImmutable($end);

        if ($this->end <= $this->start) {
            throw new InvalidArgumentException('El rango debe cumplir end > start (convención end exclusivo).');
        }

        $this->price = $price;
        $this->currency = $currency;
        $this->minStay = $minStay;
        $this->sourceId = $sourceId;
    }

    public function getStart(): DateTimeImmutable
    {
        return $this->start;
    }

    public function getEnd(): DateTimeImmutable
    {
        return $this->end;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getMinStay(): int
    {
        return $this->minStay;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }

    public function withCurrency(?string $currency): self
    {
        return new self($this->start, $this->end, $this->price, $currency, $this->minStay, $this->sourceId);
    }

    public function withMinStay(int $minStay): self
    {
        return new self($this->start, $this->end, $this->price, $this->currency, $minStay, $this->sourceId);
    }

    public function withSourceId(?string $sourceId): self
    {
        return new self($this->start, $this->end, $this->price, $this->currency, $this->minStay, $sourceId);
    }

    /**
     * Para APIs estilo calendario (Beds24, FullCalendar, etc).
     *
     * Convención:
     * - from inclusivo
     * - to exclusivo por defecto
     *
     * @return array<string,mixed>
     */
    public function toCalendarArray(
        bool $endExclusive = true,
        string $priceKey = 'price1',
        bool $includeCurrency = false,
        bool $includeSourceId = false
    ): array {
        $from = $this->start->format('Y-m-d');

        $to = $endExclusive
            ? $this->end->format('Y-m-d')
            : $this->end->modify('-1 day')->format('Y-m-d');

        $out = [
            'from' => $from,
            'to' => $to,
            $priceKey => $this->price,
            'minStay' => $this->minStay,
        ];

        if ($includeCurrency && $this->currency !== null) {
            $out['currency'] = $this->currency;
        }

        if ($includeSourceId && $this->sourceId !== null) {
            $out['sourceId'] = $this->sourceId;
        }

        return $out;
    }

    private static function toDateImmutable(DateTimeInterface $dt): DateTimeImmutable
    {
        $imm = ($dt instanceof DateTimeImmutable) ? $dt : DateTimeImmutable::createFromInterface($dt);

        return $imm->setTime(0, 0, 0);
    }
}