<?php
declare(strict_types=1);

namespace App\Exchange\Service\Mapping;

final readonly class ItemResult
{
    public function __construct(
        public string|int $queueItemId,
        public bool $success,
        public ?string $message = null,
        public ?string $remoteId = null,
        public array $extraData = []
    ) {}
}