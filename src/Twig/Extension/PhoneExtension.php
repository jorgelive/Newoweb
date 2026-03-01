<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class PhoneExtension extends AbstractExtension
{
    private PhoneNumberUtil $phoneUtil;

    public function __construct()
    {
        $this->phoneUtil = PhoneNumberUtil::getInstance();
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('phone_format', [$this, 'formatPhone']),
        ];
    }

    /**
     * Intenta formatear el número a E.164 Internacional (+XX XXX XXX).
     * Si no puede parsearlo, devuelve el original.
     */
    public function formatPhone(?string $phone): string
    {
        if (empty($phone)) {
            return '';
        }

        try {
            // Se le inyecta un '+' temporal asumiendo que el número en BD 
            // ya viene limpio y con el código de país (ej: '51987654321')
            $numberProto = $this->phoneUtil->parse('+' . ltrim($phone, '+'), null);
            return $this->phoneUtil->format($numberProto, PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException $e) {
            return $phone; // Fallback seguro
        }
    }
}