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
    ) {}

    /**
     * Forma plana para JSON. Es lo que ve el modelo cuando llama a la herramienta de
     * disponibilidad, así que las claves se leen en español y sin abreviar: son parte
     * del prompt efectivo.
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'nombre'          => $this->nombre,
            'establecimiento' => $this->establecimiento,
            'capacidad'       => $this->capacidad,
            'tarifa_base'     => $this->tarifaBase,
            'moneda'          => $this->moneda,
        ];
    }
}
