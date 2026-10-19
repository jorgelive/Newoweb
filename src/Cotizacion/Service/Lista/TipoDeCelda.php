<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Lista;

/** Cómo guarda Excel lo que se teclea en una celda. Ver `Celda`. */
enum TipoDeCelda
{
    case Texto;
    case Numero;
    case Calculada;
}
