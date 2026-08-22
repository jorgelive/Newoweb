<?php

declare(strict_types=1);

namespace App\Operacion\Service;

/**
 * Dónde recoge y dónde deja UN servicio operado, ya resuelto del todo.
 *
 * A diferencia de {@see \App\Cotizacion\Service\PuntosResueltos}, aquí no quedan modos: «el
 * alojamiento del pasajero» ya se convirtió en un hotel con su dirección, porque a esta altura
 * hay un expediente con fechas delante. Lo que sale de aquí es literalmente lo que lee el
 * proveedor.
 */
final readonly class PuntosOperativos
{
    /** @param list<string> $avisos */
    public function __construct(
        public bool $aplica,
        public ?string $recojo,
        public ?string $entrega,
        public bool $tieneEntrega,
        /** ¿Lo escribió el operador a mano, en vez de venir del catálogo? */
        public bool $recojoEsOverride,
        public bool $entregaEsOverride,
        public array $avisos,
    ) {}

    public static function noAplica(): self
    {
        return new self(false, null, null, false, false, false, []);
    }

    public function estaCompleto(): bool
    {
        if (!$this->aplica) {
            return true;
        }

        return $this->recojo !== null && (!$this->tieneEntrega || $this->entrega !== null);
    }
}
