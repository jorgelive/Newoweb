<?php

declare(strict_types=1);

namespace App\Message\Contract;

/**
 * Define los identificadores estrictos para los hitos cronológicos de una conversación.
 * Cualquier módulo (PMS, Tours, etc.) que sincronice fechas debe usar estas constantes.
 */
interface ConversationMilestoneInterface
{
    /** Fecha en que se creó/registró el evento (Reserva, Compra, etc.) */
    public const string CREATED = 'created_at';

    /** Fecha y Hora en que inicia el servicio (Check-in, Inicio del Tour, etc.) */
    public const string START = 'start';

    /** Fecha y Hora en que finaliza el servicio (Check-out, Retorno del Tour, etc.) */
    public const string END = 'end';

    /** Fecha y Hora exacta en la que el huésped indicó que llegará (Expected Arrival) */
    public const string EXPECTED_ARRIVAL = 'expected_arrival';

    /** Fecha y hora en la que el evento o reserva fue cancelado */
    public const string CANCELLED = 'cancelled_at';
}