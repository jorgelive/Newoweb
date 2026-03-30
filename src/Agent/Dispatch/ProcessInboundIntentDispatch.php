<?php

declare(strict_types=1);

namespace App\Agent\Dispatch;

final readonly class ProcessInboundIntentDispatch
{
    public function __construct(public string $messageId) {}
}