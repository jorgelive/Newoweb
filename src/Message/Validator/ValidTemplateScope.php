<?php

declare(strict_types=1);

namespace App\Message\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ValidTemplateScope extends Constraint
{
    public string $messageTypeMismatch = 'Esta plantilla es exclusiva para el módulo: {{ type }}.';
    public string $messageSourceMismatch = 'Esta plantilla no está permitida para el origen/OTA de esta reserva ({{ source }}).';
    public string $messageAgencyMismatch = 'Esta plantilla no está permitida para la agencia de esta reserva.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}