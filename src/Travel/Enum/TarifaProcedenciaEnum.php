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
}