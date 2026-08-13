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

    /**
     * Se va, pero VUELVE: el hueco entre dos tramos de la misma estancia.
     *
     * El caso típico aquí es la escapada de dos noches a Machu Picchu. No es un `END`: la
     * estancia sigue viva y el huésped no se despide. Necesita su propio mensaje —cómo dejar la
     * casita, qué hacer con la llave, qué pasa con su equipaje— y hasta ahora **no existía**:
     * la mensajería sólo veía la llegada del primer tramo y la salida del último.
     */
    public const string TEMPORARY_END = 'temporary_end';

    /** Vuelve tras una salida temporal. Es una bienvenida, no un check-in. */
    public const string REENTRY = 'reentry';

    /**
     * Cambia de casita sin irse: termina un tramo y empieza otro el mismo día, en otra unidad.
     *
     * ⚠️ **No confundir con ocupar dos casitas A LA VEZ**, que es lo que hace un grupo y es el
     * caso más común de reserva multitramo. Eso no es un cambio: no hay nada que anunciar.
     * Quien derive este hito tiene que agrupar los tramos que se solapan antes de compararlos
     * — ver `PmsHitosDeEstancia`.
     */
    public const string UNIT_CHANGE = 'unit_change';
}