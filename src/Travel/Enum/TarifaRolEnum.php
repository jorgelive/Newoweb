<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Define el rol comercial de una tarifa frente a las demás opciones disponibles
 * dentro de un mismo componente (ej. Expedition/Vistadome/Hiram Bingham en el tren).
 * A diferencia de los otros enums de este namespace, es intercambiable a nivel de
 * snapshot: el cliente puede promover una alternativa a estándar y viceversa.
 */
enum TarifaRolEnum: string
{
    case ESTANDAR = 'estandar';
    case OPERATIVO = 'operativo';
    case ALTERNATIVA = 'alternativa';

    /** Oculta las operativas de itinerario/incluye/selector de upgrade del cliente. */
    public function esVisibleParaCliente(): bool
    {
        return match($this) {
            self::OPERATIVO => false,
            default => true,
        };
    }

    /** Las operativas nacen con comisión 0; el resto es ajustable por el operador. */
    public function comisionEditablePorDefecto(): bool
    {
        return match($this) {
            self::OPERATIVO => false,
            default => true,
        };
    }

    /** Se clasifican en rama principal. */
    public function sumaRamaPrincipal(): bool
    {
        return match($this) {
            self::ESTANDAR, self::OPERATIVO => true,
            default => false,
        };
    }
}