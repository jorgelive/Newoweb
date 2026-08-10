<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Pms\Entity\PmsReserva;

/**
 * ¿Este huésped nos paga desde Perú?
 *
 * Sirve para elegir qué medios de cobro se le ofrecen ({@see \App\Finanzas\Enum\FinAudienciaCobro}),
 * y por eso no es una curiosidad estadística: acertar o no le cuesta dinero al huésped.
 *
 * ### Por qué es un servicio y no un método privado
 *
 * Lo preguntan dos sitios que no se conocen —el chat del agente
 * ({@see \App\Agent\Skill\Pms\ConsultarMediosPagoSkill}) y la guía del huésped
 * ({@see \App\Api\Provider\Pms\PmsGuiaHuespedProvider})— y **tienen que contestar lo mismo**.
 * Si la pantalla le enseña una cuenta del BCP y el asistente le dice que no tenemos cuentas
 * para él, el huésped no sabe a cuál de los dos creer.
 *
 * Vive en `Pms\Finanzas` y no en `src/Finanzas/` porque necesita saber qué es una
 * `PmsReserva`, y `src/Finanzas/` no importa de `App\Pms` en ningún archivo.
 */
final readonly class PmsProcedenciaHuesped
{
    /** Prefijo telefónico de Perú. */
    private const string PREFIJO_PERU = '51';

    /**
     * `true` desde Perú, `false` desde fuera, `null` cuando no hay con qué saberlo.
     *
     * El país de la reserva manda. Se mira el teléfono de CONTACTO
     * ({@see PmsReserva::getTelefonoContacto()}) y no `telefono` a secas: cuál de los dos
     * números es el bueno ya se decidió en un sitio y aquí sólo se consume.
     *
     * ### ⚠️ El prefijo 51 NO demuestra que sea peruano
     *
     * {@see \App\Service\Phone\PhoneSanitizer::cleanPhoneNumber()} **antepone el 51** a
     * cualquier móvil de 9 dígitos que llegue sin prefijo, que es la mayoría. Un `51…` en la
     * base puede haberlo puesto el saneador y no el huésped, así que leerlo como «es de Perú»
     * es leer nuestro propio valor por defecto.
     *
     * Por eso la evidencia es asimétrica, y esto es lo contrario de lo que uno escribiría:
     *
     * - Teléfono que **no** empieza por 51 → `false`. Eso sí lo tecleó alguien: nadie le pone
     *   un +34 por defecto a nadie.
     * - Teléfono que empieza por 51 y reserva sin país → `null`, no `true`. Se le enseñan
     *   todos los medios y que elija, que es mejor que darle por peruano y esconderle el
     *   Western Union al que sí venía de fuera.
     */
    public function pagaDesdePeru(PmsReserva $reserva): ?bool
    {
        $pais = $reserva->getPais()?->getId();

        if ($pais !== null && $pais !== '') {
            return strtoupper($pais) === 'PE';
        }

        $telefono = preg_replace('/\D/', '', (string) $reserva->getTelefonoContacto());

        if ($telefono === null || $telefono === '') {
            return null;
        }

        return str_starts_with($telefono, self::PREFIJO_PERU) ? null : false;
    }
}
