<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Define la categoría de confort o estándar del servicio para determinar
 * el tipo de tarifa a aplicar en el catálogo.
 *
 * Los casos representan los nombres comerciales comunes de las categorías
 * y sus valores respaldados (backed values) están definidos en minúsculas
 * para mantener la consistencia con el almacenamiento en la base de datos.
 *
 * @example $categoria = TarifaCategoriaEnum::ESTANDAR->value; // Devuelve 'estandar'
 */
enum TarifaCategoriaEnum: string
{
    /** Categoria básica o estándar del servicio. */
    case ESTANDAR = 'estandar';

    /** Categoría económica accesible. */
    case ECONOMICO = 'economico';

    /** Categoría de confort superior. */
    case SUPERIOR = 'superior';

    /** Categoría de alto nivel o lujo. */
    case PREMIUM = 'premium';
}