<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Por dónde entró un pago de un expediente de turismo.
 *
 * Es el vocabulario de Cotizaciones, no el de Finanzas ni el del PMS: el contrato de caja
 * ({@see \App\Finanzas\Contract\FinMovimientoProviderInterface::mediosDisponibles()}) pide que cada
 * módulo declare el suyo. Se parece a `FinMedioCobroTipo` —son los medios que se le OFRECEN al
 * cliente en «Cómo reservar»— más la tarjeta, que allí no es una cuenta sino un enlace.
 */
enum CotizacionMedioPagoEnum: string
{
    case YAPE                   = 'yape';
    case PLIN                   = 'plin';
    case TRANSFERENCIA_BANCARIA = 'transferencia_bancaria';
    case TARJETA                = 'tarjeta';
    case WESTERN_UNION          = 'western_union';
    case EFECTIVO               = 'efectivo';
    case PAYPAL                 = 'paypal';

    public function label(): string
    {
        return match ($this) {
            self::YAPE                   => 'Yape',
            self::PLIN                   => 'Plin',
            self::TRANSFERENCIA_BANCARIA => 'Transferencia bancaria',
            self::TARJETA                => 'Tarjeta',
            self::WESTERN_UNION          => 'Western Union',
            self::EFECTIVO               => 'Efectivo',
            self::PAYPAL                 => 'PayPal',
        };
    }
}
