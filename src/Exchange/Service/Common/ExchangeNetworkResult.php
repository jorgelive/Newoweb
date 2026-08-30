<?php
declare(strict_types=1);

namespace App\Exchange\Service\Common;

final readonly class ExchangeNetworkResult
{
    /**
     * @param array<array-key, mixed> $decodedData La respuesta del canal ya decodificada. No se
     *        estrecha más porque cada canal devuelve lo suyo —Beds24 una lista, Meta un objeto—
     *        y quien sabe interpretarla es el handler del canal, no esta caja.
     */
    public function __construct(
        public array $decodedData, // El array PHP para la lógica
        public string $rawBody,    // El texto JSON exacto para auditoría
        public int $statusCode     // El código HTTP (200, 201, 400, 500)
    ) {}
}