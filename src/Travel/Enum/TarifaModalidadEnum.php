<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Define el nivel de exclusividad del servicio para buscar la tarifa correcta en el catálogo.
 */
enum TarifaModalidadEnum: string
{
    case PRIVADO = 'privado';
    case COMPARTIDO = 'compartido';
}