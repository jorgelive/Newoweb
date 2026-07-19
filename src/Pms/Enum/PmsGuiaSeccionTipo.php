<?php
declare(strict_types=1);

namespace App\Pms\Enum;

enum PmsGuiaSeccionTipo: string
{
    case Ingreso     = 'ingreso';      // La destacada (cómo entrar)
    case Descriptivo = 'descriptivo';  // Fotos, WiFi, detalles de la unidad
    case Normas      = 'normas';       // Reglas de la casa
}