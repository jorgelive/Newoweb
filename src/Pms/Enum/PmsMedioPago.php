<?php

declare(strict_types=1);

namespace App\Pms\Enum;

/**
 * Medio por el que se recibió un pago (PmsPagoFinanciero).
 *
 * La tarjeta de crédito lleva un recargo del 5.5% (`comisionPorcentaje()`), que se agrega
 * a lo cobrado al cliente. El resto de medios no tienen recargo por defecto.
 */
enum PmsMedioPago: string
{
    case EFECTIVO               = 'efectivo';
    case PLIN_YAPE              = 'plin_yape';
    case TARJETA_CREDITO        = 'tarjeta_credito';
    case WESTERN_UNION          = 'western_union';
    case TRANSFERENCIA_BANCARIA = 'transferencia_bancaria';
    case PAYPAL                 = 'paypal';

    /** Recargo por defecto aplicado al cobrar (porcentaje, como string BCMath-friendly). */
    public function comisionPorcentaje(): string
    {
        return match ($this) {
            self::TARJETA_CREDITO => '5.5',
            default               => '0',
        };
    }

    /**
     * Etiqueta legible en español. Es la ÚNICA fuente de verdad: el frontend la
     * consume por `PmsEnumAjaxController` en vez de duplicar el diccionario en TS.
     */
    public function label(): string
    {
        return match ($this) {
            self::EFECTIVO               => 'Efectivo',
            self::PLIN_YAPE              => 'Plin / Yape',
            self::TARJETA_CREDITO        => 'Tarjeta de Crédito',
            self::WESTERN_UNION          => 'Western Union',
            self::TRANSFERENCIA_BANCARIA => 'Transferencia Bancaria',
            self::PAYPAL                 => 'PayPal',
        };
    }

    /** Icono FontAwesome para pintar el medio de pago en el panel financiero. */
    public function icono(): string
    {
        return match ($this) {
            self::EFECTIVO               => 'fa-money-bill-wave',
            self::PLIN_YAPE              => 'fa-mobile-screen-button',
            self::TARJETA_CREDITO        => 'fa-credit-card',
            self::WESTERN_UNION          => 'fa-building-columns',
            self::TRANSFERENCIA_BANCARIA => 'fa-right-left',
            self::PAYPAL                 => 'fa-paypal',
        };
    }

    /** Clave de traducción para exponer la etiqueta en el frontend (i18n). */
    public function labelKey(): string
    {
        return 'pms.pago.medio.' . $this->value;
    }
}
