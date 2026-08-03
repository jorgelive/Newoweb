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
    /** Sin reserva: catálogo público de la unidad. */
    case Publico = 'publico';

    /**
     * Hay estancia, pero no da derecho a nada privado: cancelada, sin pago
     * confiable, o excluida de la guía a mano. Se trata igual que un visitante.
     */
    case NoConfirmada = 'no_confirmada';

    /** Estancia válida, pero aún faltan más de 24 h para el check-in. */
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
}
