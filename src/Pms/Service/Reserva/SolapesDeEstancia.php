<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;

/**
 * Con quién choca una estancia: otra estancia viva de otro huésped en la misma casita, contando
 * las noches extra de las dos.
 *
 * Es la regla de {@see \App\Pms\EventListener\PmsEventoCalendarioSolapeListener}, sacada aquí para
 * que la usen los dos que la necesitan sin escribirla dos veces: el listener, que FRENA al guardar,
 * y el aviso del editor ({@see \App\Pms\Controller\Api\PmsSolapesDeEstanciaController}), que la
 * enseña ANTES de guardar. Ver docs/PmsDisponibilidad.md §8.c.
 *
 * Qué cuenta, igual que siempre: `IMPIDEN_VENTA`, solape por `DATE()` y el rango efectivo con la
 * noche extra, vía {@see PmsDisponibilidadService::ocupacion()}. Un bloqueo no choca, y lo de la
 * misma reserva tampoco.
 */
final readonly class SolapesDeEstancia
{
    public function __construct(
        private PmsDisponibilidadService $disponibilidad,
    ) {}

    /**
     * @param bool $cambiaLaEstancia Si sólo cambió una casilla de horario extra, las noches de la
     *                               estancia no se vuelven a mirar: no se han movido, y un solape
     *                               que bajó del canal no tiene por qué impedir marcarla.
     *
     * @return list<string> Un texto por choque, con lo que hay que hacer. Vacía si no choca.
     */
    public function de(PmsEventoCalendario $evento, bool $cambiaLaEstancia = true): array
    {
        $estado = $evento->getEstado()?->getId();
        $inicio = $evento->getInicio();
        $fin = $evento->getFin();

        if ($estado === null || !in_array($estado, PmsEventoEstado::IMPIDEN_VENTA, true)
            || $evento->getPmsUnidad() === null || $inicio === null || $fin === null
            || $fin->format('Y-m-d') <= $inicio->format('Y-m-d')) {
            return [];
        }

        $choques = [];

        if ($cambiaLaEstancia) {
            $choques = $this->en($evento, $inicio, $fin, 'Libera esas noches o elige otra casita antes de guardar.');
        }

        foreach ($evento->nochesExtra() as $noche) {
            $choques = [...$choques, ...$this->en($evento, $noche->desde, $noche->hasta, sprintf(
                'La %s ocupa también esa noche: elige otra fecha o casita, o quita la casilla.',
                mb_strtolower($noche->etiqueta())
            ))];
        }

        return array_values(array_unique($choques));
    }

    /** @return list<string> */
    private function en(PmsEventoCalendario $evento, \DateTimeInterface $desde, \DateTimeInterface $hasta, string $queHacer): array
    {
        $unidad = $evento->getPmsUnidad();

        if ($unidad === null) {
            return [];
        }

        $propioId = $evento->getId() !== null ? (string) $evento->getId() : null;
        $reservaId = $evento->getReserva()?->getId() !== null ? (string) $evento->getReserva()->getId() : null;
        $choques = [];

        foreach ($this->disponibilidad->ocupacion($desde, $hasta, (string) $unidad->getId()) as $otro) {
            if ($otro->eventoId === $propioId || ($reservaId !== null && $otro->reservaId === $reservaId)) {
                continue;
            }

            // Un bloqueo no choca: se usa para cerrar la casita en los canales y es normal crear
            // una estancia directa encima (Jorge, 28/09/2026).
            if (!$otro->esEstancia) {
                continue;
            }

            [$entra, $sale] = $otro->nochesOcupadas();

            $choques[] = sprintf(
                '%s ya está ocupada del %s al %s por %s. %s',
                $unidad->getNombre(),
                (new \DateTimeImmutable($entra))->format('d/m'),
                (new \DateTimeImmutable($sale))->format('d/m'),
                $otro->quienOcupa(),
                $queHacer,
            );
        }

        return $choques;
    }
}
