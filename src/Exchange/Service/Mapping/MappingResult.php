<?php
declare(strict_types=1);

namespace App\Exchange\Service\Mapping;

use App\Exchange\Service\Contract\ChannelConfigInterface;

final readonly class MappingResult
{
    public function __construct(
        public string $method,
        public string $fullUrl, // URL completa: Base + Endpoint
        public array $payload,
        public ChannelConfigInterface $config,
        public array $correlationMap,
        public array $metadata = []
    ) {}
}