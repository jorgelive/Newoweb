<?php

declare(strict_types=1);

namespace App\Pms\Enum;

/**
 * Clasificación estandarizada de un concepto financiero (PmsCargoFinanciero).
 *
 * Beds24 reporta descripciones-plantilla iguales en Booking y Airbnb
 * ("suplemento de limpieza", "cargo por servicio", "[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]")
 * y un `subType` grueso (el 11 mezcla limpieza y servicio). Este enum es la capa de
 * clasificación traducible por encima de esos datos crudos (que se conservan como verdad
 * histórica en `subTipo`/`descripcion`).
 */
enum PmsTipoCargo: string
{
    case ALOJAMIENTO  = 'alojamiento';
    case LIMPIEZA     = 'limpieza';
    case SERVICIO     = 'servicio';
    case PENALIZACION = 'penalizacion';
    case OTRO         = 'otro';

    /** subType de Beds24 para el cargo de alojamiento (noches de habitación). */
    private const SUBTYPE_ALOJAMIENTO = 8;

    /**
     * Clasifica un invoiceItem de Beds24 a partir de su subType y descripción.
     * Fuente única de verdad: la usan tanto el webhook como el pull.
     */
    public static function desdeBeds24(?string $descripcion, ?int $subTipo): self
    {
        $desc = mb_strtolower(trim((string) $descripcion));

        // 1. PENALIZACIÓN PRIMERO, antes que la regla del subType.
        //    El "Cancel Fee" de Beds24 llega con `subType: 8` —el mismo del alojamiento—, así
        //    que mirando sólo el subType se clasificaría como una noche más. Es justo el cargo
        //    que decide el saldo de una reserva anulada (§12.7), así que no puede confundirse.
        if ($desc !== '' && (str_contains($desc, 'cancel') || str_contains($desc, 'penaliz') || str_contains($desc, 'no show'))) {
            return self::PENALIZACION;
        }

        // 2. El subType 8 identifica el alojamiento sin ambigüedad.
        if ($subTipo === self::SUBTYPE_ALOJAMIENTO) {
            return self::ALOJAMIENTO;
        }

        // 3. Desambiguamos el resto por descripción (el subType 11 mezcla limpieza y servicio).
        if ($desc !== '') {
            if (str_contains($desc, 'limpieza') || str_contains($desc, 'cleaning')) {
                return self::LIMPIEZA;
            }
            if (str_contains($desc, 'servicio') || str_contains($desc, 'service')) {
                return self::SERVICIO;
            }
            // Plantilla de alojamiento de Beds24 o nombre de habitación / noches.
            if (str_contains($desc, 'roomname')
                || str_contains($desc, 'firstnight')
                || str_contains($desc, 'leavingday')
                || str_contains($desc, 'noche')
                || str_contains($desc, 'night')
            ) {
                return self::ALOJAMIENTO;
            }
        }

        return self::OTRO;
    }

    /**
     * Etiqueta legible en español. Es la ÚNICA fuente de verdad: el frontend la
     * consume por `PmsEnumAjaxController` en vez de duplicar el diccionario en TS.
     */
    public function label(): string
    {
        return match ($this) {
            self::ALOJAMIENTO  => 'Alojamiento',
            self::LIMPIEZA     => 'Limpieza',
            self::SERVICIO     => 'Servicio',
            self::PENALIZACION => 'Penalización',
            self::OTRO         => 'Otro',
        };
    }

    /** Color semántico (Tailwind) para las insignias del panel financiero. */
    public function color(): string
    {
        return match ($this) {
            self::ALOJAMIENTO  => 'sky',
            self::LIMPIEZA     => 'violet',
            self::SERVICIO     => 'amber',
            self::PENALIZACION => 'rose',
            self::OTRO         => 'slate',
        };
    }

    /** Clave de traducción para exponer la etiqueta en el frontend (i18n). */
    public function labelKey(): string
    {
        return 'pms.cargo.tipo.' . $this->value;
    }
}
