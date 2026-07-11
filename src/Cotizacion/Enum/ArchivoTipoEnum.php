<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

enum ArchivoTipoEnum: string
{
    case BOLETO = 'boleto';
    case FACTURA = 'factura';
    case RESERVA = 'reserva';
    case OTROS = 'otros';

    public function getLabel(): string
    {
        return match($this) {
            self::BOLETO => 'Boleto / Ticket',
            self::FACTURA => 'Factura / Recibo',
            self::RESERVA => 'Confirmación de Reserva',
            self::OTROS => 'Otros Documentos',
        };
    }

    /**
     * Define si este tipo de documento debe mostrarse al cliente en el
     * visor público. Documentos administrativos internos (facturas) o de
     * clasificación ambigua (otros) quedan fuera por defecto.
     */
    public function esPublico(): bool
    {
        return match($this) {
            self::BOLETO, self::RESERVA => true,
            self::FACTURA, self::OTROS => false,
        };
    }
}