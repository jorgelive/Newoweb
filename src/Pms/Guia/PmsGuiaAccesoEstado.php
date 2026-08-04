<?php

declare(strict_types=1);

namespace App\Pms\Guia;

/**
 * Situación de una estancia respecto a su guía. Es el eje vertical de la
 * matriz de PmsGuiaAcceso::permite().
 *
 * Sustituye a los strings sueltos que devolvía GuiaHelperResponseTrait
 * ('demo', 'unconfirmed', 'pending', 'expired', 'active'), que viajaban al
 * navegador como `config.access_status` y allí se volvían a interpretar.
 */
enum PmsGuiaAccesoEstado: string
{
    /**
     * Sin estancia detrás: catálogo público de la unidad.
     *
     * También es donde cae, defensivamente, una estancia cancelada o excluida
     * a mano: deja de ser cliente DE ESA UNIDAD (puede tener otras vigentes).
     * En la práctica no se llega aquí por ese camino — `getEventosActivosGuia()`
     * ya las descarta antes, así que ni salen en la lista de la reserva ni se
     * puede abrir su guía.
     */
    case Publico = 'publico';

    /**
     * Estancia vigente, pero sin pago confiable. Es la única situación con
     * salida en manos del huésped, y por eso su mensaje invita a confirmar.
     */
    case SinPago = 'sin_pago';

    /** Pagada, pero aún faltan más de 24 h para el check-in. */
    case Pendiente = 'pendiente';

    /** Ventana abierta: desde 24 h antes del check-in hasta el check-out. */
    case Activa = 'activa';

    /** Ya pasó el check-out. */
    case Expirada = 'expirada';

    /** Si hay un huésped identificado detrás (todo menos el catálogo). */
    public function esHuesped(): bool
    {
        return self::Publico !== $this;
    }

    /**
     * Si el dinero ya entró. Abre el nivel `ClienteConfirmado` con
     * independencia de la ventana temporal: una vez pagada, la estancia sigue
     * confirmada antes, durante y después de la ventana.
     */
    public function pagoConfirmado(): bool
    {
        return self::Pendiente === $this
            || self::Activa === $this
            || self::Expirada === $this;
    }
}
