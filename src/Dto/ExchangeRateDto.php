<?php
declare(strict_types=1);

namespace App\Dto;

use DateTimeImmutable;

final class ExchangeRateDto
{
    /**
     * @param string $buy  Se guarda como string para evitar problemas de punto flotante.
     * @param string $sell Se guarda como string.
     */
    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly string $buy,
        public readonly string $sell,
        public readonly string $currencyCode
    ) {}
}