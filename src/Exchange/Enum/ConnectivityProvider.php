<?php

declare(strict_types=1);

namespace App\Exchange\Enum;

enum ConnectivityProvider: string
{
    case BEDS24  = 'beds24';
    case META = 'meta';

    /**
     * El correo. No es una API con endpoints: el transporte es el mailer de Symfony sobre
     * Microsoft Graph (ver `docs/CorreoSaliente.md`). Está aquí porque el motor exige un
     * proveedor por endpoint, y el endpoint del correo es un marcador de posición — el destino
     * de un correo es un buzón, no una ruta.
     */
    case EMAIL = 'email';

    // Puedes ir agregando más en el futuro
    // case MAILCHIMP = 'mailchimp';

    public function getLabel(): string
    {
        return match($this) {
            self::BEDS24  => 'Beds24',
            self::META => 'Meta',
            self::EMAIL => 'Correo',
        };
    }
}