<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentoTipoEnum: string
{
    case DNI = 'DNI';
    case CE = 'CE'; // Carné de Extranjería
    case RUC = 'RUC';
    case PASAPORTE = 'PASAPORTE';
    case CI = 'CI'; // Carné de Identidad

    public function getLabel(): string
    {
        return match($this) {
            self::DNI => 'DNI',
            self::CE => 'C.E.',
            self::RUC => 'RUC',
            self::PASAPORTE => 'Pasaporte',
            self::CI => 'Carné de Identidad',
        };
    }

    /**
     * Retorna el código requerido por la API del Ministerio de Cultura (Machu Picchu).
     */
    public function getCodigoMC(): ?int
    {
        return match($this) {
            self::DNI => 1,
            self::CE => 2,
            self::PASAPORTE => 3,
            self::CI => 7,
            self::RUC => null,
        };
    }

    /**
     * Retorna el código requerido por la API de Consettur (Buses Aguas Calientes).
     */
    public function getCodigoConsettur(): ?int
    {
        return match($this) {
            self::DNI => 1,
            self::CE => 2,
            self::PASAPORTE, self::CI => 4,
            self::RUC => null,
        };
    }
}