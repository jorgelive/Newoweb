<?php

declare(strict_types=1);

namespace App\Pms\Dto;

/**
 * Una casita libre en el rango consultado.
 *
 * Se devuelve como DTO y no como PmsUnidad para que el consumidor —la API del panel o
 * la herramienta del agente— no arrastre la entidad entera ni sus relaciones perezosas.
 */
final readonly class PmsUnidadDisponibleDto
{
    public function __construct(
        public string $id,
        public string $nombre,
        public string $establecimiento,
        public ?int $capacidad,
        public string $tarifaBase,
        public ?string $moneda,
        // 🛏️ Comodidad, que NO es aforo: dos grupos de 8 caben igual en una casita de dos
        // habitaciones y en una de tres, y no es lo mismo. Viaja aquí para poder COMPARAR sin
        // ir a la guía casita por casita.
        public ?int $habitaciones = null,
        public ?string $camas = null,
        public ?int $banos = null,
    ) {}

    /**
     * Forma plana para JSON. Es lo que ve el modelo cuando llama a la herramienta de
     * disponibilidad, así que las claves se leen en español y sin abreviar: son parte
     * del prompt efectivo.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'nombre'          => $this->nombre,
            'establecimiento' => $this->establecimiento,
            'capacidad'       => $this->capacidad,
            'habitaciones'    => $this->habitaciones,
            'camas'           => $this->camas,
            'banos'           => $this->banos,
            'tarifa_base'     => $this->tarifaBase,
            'moneda'          => $this->moneda,
        ];
    }
}
