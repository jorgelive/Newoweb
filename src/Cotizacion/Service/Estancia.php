<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use DateTimeImmutable;

/**
 * Una estancia del pasajero: dónde duerme, desde cuándo y hasta cuándo.
 *
 * `$desde` es el día de entrada y `$hasta` el de salida, tal como vienen del componente de
 * alojamiento cotizado. Las **noches** que cubre son las que van de `$desde` a `$hasta`, sin
 * incluir la última: una estancia 04→06 cubre las noches 4→5 y 5→6, y el día 6 el pasajero ya
 * no duerme ahí.
 */
final readonly class Estancia
{
    public function __construct(
        public DateTimeImmutable $desde,
        public DateTimeImmutable $hasta,
        public string $hotel,
        public ?string $direccion,
    ) {}

    /**
     * Cómo se nombra en la orden que lee el proveedor.
     *
     * Sin dirección se devuelve el nombre a secas, no una cadena vacía: un conductor puede
     * buscar «Hotel Terra - Cusco» y preguntar; con el renglón en blanco no tiene ni por dónde
     * empezar. Que falte la dirección es un problema de la ficha del hotel, no una razón para
     * callarse el nombre.
     */
    public function paraLaOrden(): string
    {
        $direccion = trim((string) $this->direccion);

        return $direccion !== '' ? $this->hotel . ' — ' . $direccion : $this->hotel;
    }

    public function estaCompleta(): bool
    {
        return trim((string) $this->direccion) !== '';
    }
}
