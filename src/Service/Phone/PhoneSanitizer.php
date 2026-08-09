<?php

declare(strict_types=1);

namespace App\Service\Phone;

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
     * Regla de oro: **nunca se inventa un prefijo**. Solo se antepone el 51 al móvil
     * peruano tal y como lo teclea la gente aquí (9 dígitos empezando por 9). Todo lo
     * demás —incompleto, fijo, extranjero mal escrito— se guarda con los dígitos que
     * llegaron, porque un dato crudo es recuperable y uno con prefijo falso no.
     *
     * @param string $rawPhone El número de teléfono crudo (ej: '+353 87 260 4677').
     * @param string $defaultCountryIso El ISO2 del país por defecto (ej: 'PE', 'IE').
     * @return string El número formateado o los dígitos tal cual si no valida.
     * * @example
     * $sanitizer->cleanPhoneNumber('+353 87 260 4677', 'IE'); // Retorna: '353872604677'
     */
    public function cleanPhoneNumber(string $rawPhone, string $defaultCountryIso): string
    {
        $rawPhone = trim($rawPhone);
        if ($rawPhone === '') {
            return '';
        }

        $iso = strtoupper($defaultCountryIso);
        // Se trabaja sobre dígitos porque es el formato en que se persiste (E.164 sin
        // el '+') y el que comparan los repositorios al buscar reservas por teléfono.
        $clean = preg_replace('/[^0-9]/', '', $rawPhone) ?? '';

        try {
            $numberProto = $this->phoneUtil->parse($rawPhone, $iso);

            // isValidNumber() y NO isPossibleNumber(): el segundo solo comprueba que la
            // longitud sea plausible, y Perú admite fijos de 6 dígitos, así que daba por
            // bueno un número incompleto ('940418') y le pegaba el '+51'. Como se guarda
            // sin el '+', el siguiente guardado lo releía como nacional y le añadía otro:
            // 940418 -> 51940418 -> 5151940418, que se mostraba como «+51 51 940418»
            // (un fijo de Puno) en el drawer de reservas.
            if ($this->phoneUtil->isValidNumber($numberProto)) {
                $formatted = $this->phoneUtil->format($numberProto, PhoneNumberFormat::E164);
                // Retornamos sin el '+' inicial para la BD
                return ltrim($formatted, '+');
            }
        } catch (NumberParseException) {
            // Falla silenciosa: si el usuario puso basura pasa a la regla de abajo
        }

        // =====================================================================
        // ÚNICO PREFIJO MANUAL: móvil peruano (9 dígitos empezando por 9)
        // =====================================================================
        // Un número que ya trae el 51 delante tiene 11 dígitos, así que no entra aquí:
        // por construcción es imposible duplicar el prefijo.
        if ($iso === 'PE' && strlen($clean) === 9 && str_starts_with($clean, '9')) {
            return '51' . $clean;
        }

        return $clean;
    }
}