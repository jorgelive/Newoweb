<?php

declare(strict_types=1);

namespace App\Front\Comun\Enum;

/**
 * Las dos clases de hoja que distingue el Código de Protección y Defensa del Consumidor.
 * La diferencia la decide el consumidor y no se corrige después: cambia el plazo y el trato.
 */
enum LibroReclamacionTipoEnum: string
{
    /** Disconformidad con el producto o servicio contratado. */
    case RECLAMO = 'reclamo';

    /** Malestar con la atención, sin relación con lo contratado. */
    case QUEJA = 'queja';
}
