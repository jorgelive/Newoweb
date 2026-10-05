<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Clasifica el mercado de origen del pasajero.
 * Fundamental para la aplicación de impuestos o convenios de la DDC y tarifas de tren.
 */
enum TarifaProcedenciaEnum: string
{
    case NACIONAL = 'nacional';
    case EXTRANJERO = 'extranjero';
    case COMUNIDAD_ANDINA = 'can';

    /**
     * Cómo se nombra en pantalla y en los documentos que salen al proveedor.
     *
     * ⚠️ **Espejo de `PROCEDENCIA_CONFIG`** en `util/src/types/cotizacionEditorModel.ts` — si
     * cambia una etiqueta, se tocan LOS DOS. Vive aquí y no en cada superficie porque ya había
     * una copia enterrada en un método privado de `TravelTarifa` (`getProcedenciaIcono()`) y la
     * orden de servicio habría sido la tercera.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::NACIONAL => 'Nacional',
            self::EXTRANJERO => 'Extranjero',
            self::COMUNIDAD_ANDINA => 'Comunidad Andina',
        };
    }

    /** El icono solo, para los `__toString()` compactos de los selects de EasyAdmin. */
    public function icono(): string
    {
        return match ($this) {
            self::NACIONAL => '🇵🇪',
            self::EXTRANJERO => '🌎',
            self::COMUNIDAD_ANDINA => '🤝 CAN',
        };
    }
}