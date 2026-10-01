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
 * Por eso aquí entran también los bloqueos, que no son personas, y las estancias que sólo ocupan
 * el rango con su noche extra (`nocheExtra`). Si se filtraran por «no tienen huésped», el
 * operador vería 5 libres y 1 ocupada de 7 casitas y no sabría dónde está la séptima. `es_estancia` distingue a quien duerme de lo que sólo tapa.
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
        /**
         * `entrada_temprana` o `salida_tardia` si lo ÚNICO que cae en el rango consultado es esa
         * noche extra; `null` si la estancia misma lo ocupa. `entra`/`sale` siguen siendo los de
         * la estancia: la noche extra es la víspera de `entra` o la noche de `sale`.
         */
        public ?string $nocheExtra = null,
    ) {}

    /** Quién ocupa, dicho para una persona: «Anna Müller» o «la entrada temprana de Anna Müller». */
    public function quienOcupa(): string
    {
        $quien = $this->huesped ?? $this->estado;

        return match ($this->nocheExtra) {
            'entrada_temprana' => 'la entrada temprana de ' . $quien,
            'salida_tardia' => 'la salida tardía de ' . $quien,
            default => $quien,
        };
    }

    /**
     * Las noches que ocupa DENTRO de lo que importa: las de la estancia, o sólo su noche extra.
     *
     * @return array{0: string, 1: string} Días `Y-m-d`, semiabierto.
     */
    public function nochesOcupadas(): array
    {
        $dia = static fn (string $ymd, string $mas): string => (new \DateTimeImmutable($ymd))->modify($mas)->format('Y-m-d');

        return match ($this->nocheExtra) {
            'entrada_temprana' => [$dia($this->entra, '-1 day'), $this->entra],
            'salida_tardia' => [$this->sale, $dia($this->sale, '+1 day')],
            default => [$this->entra, $this->sale],
        };
    }

    /**
     * Forma plana para JSON. Las claves son parte del prompt efectivo: se leen en español y
     * sin abreviar, igual que en el DTO hermano.
     *
     * @return array<string, mixed>
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
            // Sólo cuando lo que ocupa el rango es su entrada temprana o su salida tardía, no la
            // estancia: `entra`/`sale` son los de la estancia, que cae fuera.
            'noche_extra'     => $this->nocheExtra,
        ], static fn ($v) => $v !== null);
    }
}
