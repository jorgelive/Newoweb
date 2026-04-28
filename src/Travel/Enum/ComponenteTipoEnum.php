<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Clasifica la naturaleza operativa del servicio.
 * Determina reglas de negocio críticas en la UI, como la exigencia de horarios exactos o dependencias de duración.
 */
enum ComponenteTipoEnum: string
{
    case TICKET_HORARIO_FIJO = 'ticket_fijo';
    case TICKET_HORARIO_VAR = 'ticket_variable';
    case GUIADO = 'guiado';
    case TRANSPORTE = 'transporte';
    case ALOJAMIENTO = 'alojamiento';
    case ALIMENTACION = 'alimentacion';
    case EXCURSION_POOL = 'pool';
    case EXCURSION_PRIVADA = 'privada';
    case PERSONAL_EXTRA = 'personal_extra';
    case EXTRAS = 'extras';
    case VUELO = 'vuelo';
    case TREN = 'tren';

    /**
     * Define si la UI (Vue) debe exigir y mostrar un selector de hora específica (H:i).
     * Si retorna false, el backend debe forzar la hora a '00:00:00' al persistir.
     *
     * @return bool
     */
    public function requiereHoraExacta(): bool
    {
        return match($this) {
            self::TREN,
            self::VUELO,
            self::TRANSPORTE,
            self::TICKET_HORARIO_FIJO,
            self::EXCURSION_POOL,
            self::EXCURSION_PRIVADA,
            self::GUIADO => true,

            self::ALOJAMIENTO,
            self::TICKET_HORARIO_VAR,
            self::ALIMENTACION,
            self::EXTRAS,
            self::PERSONAL_EXTRA => false,
        };
    }

    /**
     * Establece la prioridad visual para el proveedor en los manifiestos y reportes operativos.
     * Menor número indica mayor prioridad (aparece antes).
     *
     * @return int
     */
    public function prioridad(): int
    {
        return match($this) {
            self::GUIADO, self::TRANSPORTE, self::EXCURSION_POOL, self::EXCURSION_PRIVADA, self::TREN => 1,
            self::ALOJAMIENTO, self::VUELO => 2,
            self::ALIMENTACION => 3,
            self::TICKET_HORARIO_FIJO, self::TICKET_HORARIO_VAR => 4,
            default => 5,
        };
    }
}