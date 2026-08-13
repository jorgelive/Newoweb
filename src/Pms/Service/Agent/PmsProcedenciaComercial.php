<?php

declare(strict_types=1);

namespace App\Pms\Service\Agent;

/**
 * Qué implica, para el alojamiento, el canal por el que se vendió un asunto.
 *
 * ── Por qué está aquí y no en el núcleo ─────────────────────────────────────
 * El identificador del canal —`booking`, `airbnb`, `directo`— es **opaco** para el núcleo del
 * agente: lo transporta y lo imprime, pero no sabe qué significa. Que Airbnb ya haya cobrado o que
 * Booking aplique depósito es conocimiento de alojamiento, y en Turismo será otro —quién gestiona
 * un cambio de fecha, quién emite el comprobante—. Ver CLAUDE.md §Dominios y contratos.
 *
 * ── Por qué es una clase aparte y no un método de la entidad ────────────────
 * Hacen falta las mismas frases desde dos sitios que no comparten datos:
 *
 * - {@see \App\Pms\Entity\PmsConversacionEnlace::procedenciaParaElPrompt()}, que las saca de sus
 *   propias columnas `origen`/`agencia`.
 * - {@see PmsFrentes::frenteDe()}, que construye el frente **desde la reserva** y no pasa por el
 *   enlace — y es el camino que de verdad llega al prompt.
 *
 * Con la redacción en la entidad, el segundo camino quedaba mudo y la funcionalidad entera era
 * código muerto. Una sola fuente, dos llamadores.
 *
 * ── Cómo se escriben las frases ─────────────────────────────────────────────
 * **En positivo para todas las ramas**: cada una dice algo cierto, y así no queda nada que el
 * modelo tenga que callarse. «Airbnb: ya cobrado, sin depósito» funciona; «a los de Airbnb no les
 * menciones el depósito» es supresión, y las supresiones se ignoran.
 */
final class PmsProcedenciaComercial
{
    /**
     * La línea que se le enseña al modelo, o `null` si no aporta nada.
     *
     * ⚠️ Un canal desconocido devuelve `null` **a propósito**: mejor sin línea que con una frase
     * adivinada sobre quién cobró. Equivocarse en eso es de lo que más daño hace, porque el
     * huésped lo lee como un intento de cobrarle dos veces.
     *
     * La agencia manda sobre el origen cuando existe: una reserva que entró por una mayorista es
     * suya aunque el canal técnico diga otra cosa.
     */
    public static function frase(?string $origen, ?string $agencia = null): ?string
    {
        $agencia = trim((string) $agencia);

        if ($agencia !== '') {
            return sprintf(
                'Esta reserva la trajo la agencia %s: lo que se le cobre o se le cambie se '
                . 'coordina con ellos, no directamente con el huésped.',
                $agencia
            );
        }

        return match (strtolower(trim((string) $origen))) {
            'airbnb' => 'Reservó por Airbnb: la plataforma ya cobró la estancia entera, así que no '
                . 'paga nada al llegar y NO hay depósito de garantía.',
            'booking' => 'Reservó por Booking.com: la plataforma no cobra, el pago se abona aquí '
                . '—al llegar o adelantado— y sí aplica el depósito de garantía.',
            'directo' => 'Es una reserva directa: el pago se abona aquí —al llegar o adelantado— y '
                . 'sí aplica el depósito de garantía.',
            default => null,
        };
    }
}
