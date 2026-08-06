<?php

declare(strict_types=1);

namespace App\Pms\Dto;

/**
 * Una estancia que ocupa una casita dentro del rango consultado.
 *
 * Espejo de {@see PmsUnidadDisponibleDto}: aquélla dice qué se puede vender, ésta quién está
 * dentro. Las dos salen del mismo servicio y del mismo cálculo de solape, y **tienen que
 * cuadrar**: libres + ocupadas = parque total.
 *
 * Por eso aquí entran también los bloqueos y las extensiones, que no son personas. Si se
 * filtraran por «no tienen huésped», el operador vería 5 libres y 1 ocupada de 7 casitas y no
 * sabría dónde está la séptima. `es_estancia` distingue a quien duerme de lo que sólo tapa.
 */
final readonly class PmsOcupacionDto
{
    public function __construct(
        public string $casita,
        public string $casitaId,
        public string $establecimiento,
        public ?string $huesped,
        public string $entra,
        public string $sale,
        public ?string $horaEntrada,
        public ?string $horaSalida,
        public string $estado,
        public bool $esEstancia,
        public bool $esOta,
        public ?string $localizador,
        public ?string $reservaId,
        public string $eventoId,
    ) {}

    /**
     * Forma plana para JSON. Las claves son parte del prompt efectivo: se leen en español y
     * sin abreviar, igual que en el DTO hermano.
     */
    public function toArray(): array
    {
        return array_filter([
            'casita'          => $this->casita,
            'casita_id'       => $this->casitaId,
            'establecimiento' => $this->establecimiento,
            'huesped'         => $this->huesped,
            'entra'           => $this->entra,
            'sale'            => $this->sale,
            'hora_entrada'    => $this->horaEntrada,
            'hora_salida'     => $this->horaSalida,
            'estado'          => $this->estado,
            'es_estancia'     => $this->esEstancia,
            'es_ota'          => $this->esOta,
            'localizador'     => $this->localizador,
            // 🔗 Claves de encadenado: permiten ir de «quién está en la casita 1» a la cuenta,
            // al chat o al cambio de horario sin volver a buscar al huésped por su nombre.
            'reserva_id'      => $this->reservaId,
            'evento_id'       => $this->eventoId,
        ], static fn ($v) => $v !== null);
    }
}
