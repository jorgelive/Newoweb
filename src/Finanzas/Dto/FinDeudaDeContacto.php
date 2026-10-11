<?php

declare(strict_types=1);

namespace App\Finanzas\Dto;

/** Una deuda de alguien en un módulo. Ver `FinDeudasDeContactoInterface`. */
final readonly class FinDeudaDeContacto
{
    public function __construct(
        /** El módulo, ya escrito para el operador: «Reserva de alojamiento». */
        public string $tipoEtiqueta,
        public string $origenTipo,
        public string $origenId,
        public ?string $referencia,
        public string $descripcion,
        public string $moneda,
        public string $saldo,
    ) {}

    /** @return array<string, string|null> */
    public function aArray(): array
    {
        return [
            'tipoEtiqueta' => $this->tipoEtiqueta,
            'origenTipo' => $this->origenTipo,
            'origenId' => $this->origenId,
            'referencia' => $this->referencia,
            'descripcion' => $this->descripcion,
            'moneda' => $this->moneda,
            'saldo' => $this->saldo,
        ];
    }
}
