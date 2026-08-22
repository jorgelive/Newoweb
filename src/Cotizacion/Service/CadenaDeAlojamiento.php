<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use DateTimeImmutable;

/**
 * Dónde duerme el pasajero cada noche del viaje.
 *
 * Es lo que convierte «el alojamiento del pasajero» —el modo que declara el catálogo— en una
 * dirección concreta para el proveedor. El catálogo sabe que el recojo es en su hotel; **cuál**
 * hotel sólo se sabe con un expediente delante, y eso es esto.
 *
 * ## Las dos preguntas, y por qué son distintas
 *
 * ```
 * dondeDurmio(D)    la noche que TERMINA el día D    →  desde <  D <= hasta
 * dondeDormira(D)   la noche que EMPIEZA el día D    →  desde <= D <  hasta
 * ```
 *
 * Con una estancia 04→06 y un servicio el día 6: durmió ahí (la noche 5→6 termina esa mañana)
 * pero ya no dormirá (el 6 se muda). Un traslado de ese día sale entonces del hotel viejo y
 * llega al nuevo, que es exactamente lo que hay que decirle al conductor. Con una sola pregunta
 * —«en qué hotel está el día 6»— la respuesta sería ambigua y el 50 % de las veces, falsa.
 *
 * ## ⚠️ Un hueco es una respuesta, no un fallo
 *
 * En un trek el pasajero duerme en campamento y no hay estancia esa noche: se devuelve `null`.
 * **Lo que no se hace nunca es caer a la estancia anterior.** Sería la respuesta plausible —el
 * último hotel conocido— y mandaría al proveedor a Cusco a recoger a alguien que está a cuatro
 * horas de camino. Los campamentos se declaran como `TravelPunto` con modo fijo; esto no los
 * inventa.
 *
 * Es un objeto puro a propósito: sin él, la regla de qué noche cubre qué estancia sólo se podría
 * probar con base de datos, y es justo la que no puede fallar.
 */
final readonly class CadenaDeAlojamiento
{
    /** @param list<Estancia> $estancias ordenadas por fecha de entrada */
    public function __construct(private array $estancias) {}

    /** Dónde durmió la noche que termina el día `$fecha`. */
    public function dondeDurmio(DateTimeImmutable $fecha): ?Estancia
    {
        $dia = $fecha->setTime(0, 0);

        foreach ($this->estancias as $estancia) {
            if ($estancia->desde < $dia && $dia <= $estancia->hasta) {
                return $estancia;
            }
        }

        return null;
    }

    /** Dónde dormirá la noche que empieza el día `$fecha`. */
    public function dondeDormira(DateTimeImmutable $fecha): ?Estancia
    {
        $dia = $fecha->setTime(0, 0);

        foreach ($this->estancias as $estancia) {
            if ($estancia->desde <= $dia && $dia < $estancia->hasta) {
                return $estancia;
            }
        }

        return null;
    }

    /** @return list<Estancia> */
    public function estancias(): array
    {
        return $this->estancias;
    }

    public function estaVacia(): bool
    {
        return $this->estancias === [];
    }
}
