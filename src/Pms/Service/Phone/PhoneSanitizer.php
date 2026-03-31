<?php

declare(strict_types=1);

namespace App\Pms\Service\Phone;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Servicio encargado de sanitizar y estandarizar números de teléfono en el PMS.
 */
final class PhoneSanitizer
{
    private PhoneNumberUtil $phoneUtil;

    public function __construct()
    {
        $this->phoneUtil = PhoneNumberUtil::getInstance();
    }

    /**
     * Utiliza libphonenumber para formatear a estándar internacional E.164 (sin el +).
     *
     * @param string $rawPhone El número de teléfono crudo (ej: '+353 87 260 4677').
     * @param string $defaultCountryIso El ISO2 del país por defecto (ej: 'PE', 'IE').
     * @return string El número formateado o un fallback limpio en caso de fallo.
     * * @example
     * $sanitizer->cleanPhoneNumber('+353 87 260 4677', 'IE'); // Retorna: '353872604677'
     */
    public function cleanPhoneNumber(string $rawPhone, string $defaultCountryIso): string
    {
        $rawPhone = trim($rawPhone);
        if ($rawPhone === '') {
            return '';
        }

        try {
            $numberProto = $this->phoneUtil->parse($rawPhone, strtoupper($defaultCountryIso));

            if ($this->phoneUtil->isPossibleNumber($numberProto)) {
                $formatted = $this->phoneUtil->format($numberProto, PhoneNumberFormat::E164);
                // Retornamos sin el '+' inicial para la BD
                return ltrim($formatted, '+');
            }
        } catch (NumberParseException $e) {
            // Falla silenciosa: si el usuario puso basura pasa al fallback
        }

        // =====================================================================
        // FALLBACK MANUAL (Específico Perú)
        // =====================================================================
        $clean = preg_replace('/[^0-9]/', '', $rawPhone);

        if (strlen($clean) === 9 && str_starts_with($clean, '9')) {
            return '51' . $clean;
        }

        return $clean;
    }
}