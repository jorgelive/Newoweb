<?php

declare(strict_types=1);

namespace App\Operacion\Enum;

/**
 * Por qué medio se le pagó al PROVEEDOR ({@see \App\Operacion\Entity\OperacionPago}).
 *
 * ── Por qué no se reutiliza el del alojamiento ──────────────────────────────
 * {@see \App\Pms\Enum\PmsMedioPago} responde a la pregunta contraria: **cómo nos pagó el
 * huésped a nosotros**. Se parecen porque el vocabulario del dinero en Perú es el mismo, pero
 * no son la misma lista y no evolucionan juntas:
 *
 *  - Ahí hay `paypal` y `tarjeta_credito` con su **recargo del 5.5 %**, que existe porque se lo
 *    cobramos al cliente. Pagando a un proveedor no hay recargo que trasladar a nadie.
 *  - Aquí hay `deposito` —ir al banco y depositar en su cuenta—, que como forma de COBRAR no
 *    tiene sentido: el huésped no deposita, transfiere.
 *  - Y `seCobraEnMano()`, que allí decide si preguntar por el cobrador, aquí no significa nada:
 *    quien recibe es el proveedor, no alguien de casa.
 *
 * Importar el enum del PMS en Operación habría metido conocimiento de un dominio en otro por
 * ahorrarse seis líneas, y la primera vez que al alojamiento le hiciera falta un medio nuevo
 * aparecería en el selector de pagos a proveedores sin que nadie lo hubiera pedido.
 *
 * ⚠️ **La etiqueta es la ÚNICA fuente de verdad**, igual que en el PMS: el panel la consume por
 * `OperacionEnumAjaxController` en vez de duplicar el diccionario en TypeScript.
 */
enum OperacionMedioPago: string
{
    case EFECTIVO               = 'efectivo';
    case TRANSFERENCIA_BANCARIA = 'transferencia_bancaria';
    case DEPOSITO               = 'deposito';
    case PLIN_YAPE              = 'plin_yape';
    case TARJETA                = 'tarjeta';
    case OTRO                   = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::EFECTIVO               => 'Efectivo',
            self::TRANSFERENCIA_BANCARIA => 'Transferencia bancaria',
            self::DEPOSITO               => 'Depósito en cuenta',
            self::PLIN_YAPE              => 'Plin / Yape',
            self::TARJETA                => 'Tarjeta',
            self::OTRO                   => 'Otro',
        };
    }

    /** Icono FontAwesome, para que la lista de pagos se lea de un vistazo. */
    public function icono(): string
    {
        return match ($this) {
            self::EFECTIVO               => 'fa-money-bill-wave',
            self::TRANSFERENCIA_BANCARIA => 'fa-right-left',
            self::DEPOSITO               => 'fa-building-columns',
            self::PLIN_YAPE              => 'fa-mobile-screen-button',
            self::TARJETA                => 'fa-credit-card',
            self::OTRO                   => 'fa-ellipsis',
        };
    }
}
