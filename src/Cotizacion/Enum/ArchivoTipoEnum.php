<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

enum ArchivoTipoEnum: string
{
    case BOLETO = 'BOLETO';
    case FACTURA = 'FACTURA';
    case RESERVA = 'RESERVA';
    case OTROS = 'OTROS';

    public function getLabel(): string
    {
        return match($this) {
            self::BOLETO => 'Boleto / Ticket',
            self::FACTURA => 'Factura / Recibo',
            self::RESERVA => 'Confirmación de Reserva',
            self::OTROS => 'Otros Documentos',
        };
    }
}