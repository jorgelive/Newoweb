<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * ¿Este servicio tiene DÓNDE empieza y DÓNDE termina?
 *
 * ── Para qué ────────────────────────────────────────────────────────────────
 * Es lo primero que pregunta un proveedor cuando recibe una Orden de Servicio: **dónde recojo y
 * dónde dejo**. Y no se puede contestar igual para todo — una entrada a Machu Picchu no recoge a
 * nadie, un guía se presenta en un sitio pero no lleva al pasajero a ninguna parte, y un bus
 * hace las dos cosas.
 *
 * ── Por qué un enum y no dos booleanos ──────────────────────────────────────
 * Porque «tiene fin pero no inicio» no existe, y con dos `bool` sí se puede escribir. Los
 * estados posibles son tres y esto los cierra: quien reciba este valor no puede construir uno
 * imposible.
 */
enum PuntosDeServicio: string
{
    /** Recoge y deja: transportes, trenes, vuelos y excursiones. Los dos puntos van en la orden. */
    case INICIO_Y_FIN = 'inicio_y_fin';

    /**
     * Sólo dónde presentarse.
     *
     * El guía queda con el pasajero en un punto y ahí acaba su parte: no lo lleva de vuelta,
     * eso lo hace el transporte. Poner un destino sería inventarle una obligación.
     */
    case SOLO_INICIO = 'solo_inicio';

    /**
     * Ni uno ni otro: entradas, comidas, extras.
     *
     * ⚠️ No es que «todavía no se sepa»: es que **no aplica**. Un ticket es un derecho de
     * entrada, no un traslado. Dejarlo en «pendiente» invitaría a rellenarlo, y a que el
     * proveedor lea un punto de recojo que nadie va a atender.
     */
    case NINGUNO = 'ninguno';

    public function programaInicio(): bool
    {
        return $this !== self::NINGUNO;
    }

    public function programaFin(): bool
    {
        return $this === self::INICIO_Y_FIN;
    }
}
