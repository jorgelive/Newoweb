<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Define la naturaleza de la nota transversal para su correcto renderizado en el PDF y en Vue.js.
 */
enum NotaTipoEnum: string
{
    case INTRODUCCION   = 'introduccion';   // Storytelling (Ej. "El Legado Inca")
    case RECOMENDACION  = 'recomendacion';  // Tip / Foco (Ej. "Llevar agua")
    case ADVERTENCIA    = 'advertencia';    // Alerta / Rojo (Ej. "Mal de altura")
    case POLITICA       = 'politica';       // Texto legal (Ej. "Términos de cancelación")
    case EQUIPAJE       = 'equipaje';       // Restricciones de trenes o vuelos
}