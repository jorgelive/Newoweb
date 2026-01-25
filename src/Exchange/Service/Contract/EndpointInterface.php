<?php
declare(strict_types=1);

namespace App\Exchange\Service\Contract;

interface EndpointInterface
{
    public function getEndpoint(): ?string; // ej: '/inventory/calendar'
    public function getMetodo(): ?string;   // ej: 'POST'
}