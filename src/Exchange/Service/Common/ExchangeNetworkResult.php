<?php
declare(strict_types=1);

namespace App\Exchange\Service\Common;

final readonly class ExchangeNetworkResult
{
    public function __construct(
        public array $decodedData, // El array PHP para la lógica
        public string $rawBody,    // El texto JSON exacto para auditoría
        public int $statusCode     // El código HTTP (200, 201, 400, 500)
    ) {}
}