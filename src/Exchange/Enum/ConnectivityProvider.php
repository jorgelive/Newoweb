<?php

declare(strict_types=1);

namespace App\Exchange\Enum;

enum ConnectivityProvider: string
{
    case BEDS24  = 'beds24';
    case GUPSHUP = 'gupshup';

    // Puedes ir agregando más en el futuro
    // case MAILCHIMP = 'mailchimp';

    public function getLabel(): string
    {
        return match($this) {
            self::BEDS24  => 'Beds24',
            self::GUPSHUP => 'WhatsApp Gupshup',
        };
    }
}