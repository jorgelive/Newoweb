<?php

declare(strict_types=1);

namespace App\Enum;

enum SexoEnum: string
{
    case MASCULINO = 'M';
    case FEMENINO = 'F';

    public function getLabel(): string
    {
        return match($this) {
            self::MASCULINO => 'Masculino',
            self::FEMENINO => 'Femenino',
        };
    }
}