<?php

declare(strict_types=1);

namespace App\Pms\Guia;

use App\Pms\Entity\PmsEventoCalendario;
use DateTimeImmutable;

/**
 * De qué casita se está hablando cuando una reserva tiene varias.
 *
 * ### Por qué no vale con coger la primera
 *
 * Porque la guía **no es la misma en cada casita**, y no en detalles cosméticos:
 *
 * ```
 * Ducha (casa 1) → «La manija IZQUIERDA controla el agua caliente»
 * Ducha (casa 2) → «La manija DERECHA controla el agua caliente»
 * ```
 *
 * Responder con la casita equivocada no da una respuesta imprecisa: da la **contraria**, y el
 * huésped abre el agua fría. Con los códigos de puerta es peor todavía: el de otra casita
 * simplemente no abre, y a las 23:00 con la maleta en la calle eso es una llamada.
 *
 * ### La regla
 *
 * ```
 * 1 estancia                     → ésa, sin preguntar.
 * varias, pero UNA en curso hoy  → la que está viviendo. Es el cambio de casita a mitad
 *                                  de estancia: preguntar ahí sería absurdo.
 * varias en curso a la vez       → se PREGUNTA. Es el grupo que reservó dos casitas, y
 *                                  aquí no hay forma de saber en cuál está quien escribe.
 * ninguna en curso               → se PREGUNTA si hay más de una: aún no ha llegado a
 *                                  ninguna, así que tampoco se puede deducir.
 * ```
 *
 * Es el mismo criterio que `PmsReservaRepository::findVivasByTelefono()` usa para elegir
 * reserva, un nivel más abajo: lo de ahora manda, y ante el empate se pregunta en vez de
 * adivinar.
 */
final readonly class PmsGuiaEstanciaResolver
{
    /**
     * @param array<int, PmsEventoCalendario> $eventos Ya filtrados por `getEventosActivosGuia()`.
     *
     * @return array{evento: ?PmsEventoCalendario, ambiguo: bool, candidatas: array<int, PmsEventoCalendario>}
     *   `evento` null + `ambiguo` true ⇒ hay que preguntar al huésped de cuál habla.
     */
    public function resolver(array $eventos, string $casitaPedida = ''): array
    {
        $eventos = array_values($eventos);

        if ($eventos === []) {
            return ['evento' => null, 'ambiguo' => false, 'candidatas' => []];
        }

        // Con la casita dicha no hay nada que deducir: se busca y se respeta, o no existe.
        if ($casitaPedida !== '') {
            return [
                'evento' => $this->porNombre($eventos, $casitaPedida),
                'ambiguo' => false,
                'candidatas' => $eventos,
            ];
        }

        if (count($eventos) === 1) {
            return ['evento' => $eventos[0], 'ambiguo' => false, 'candidatas' => $eventos];
        }

        $enCurso = $this->enCurso($eventos);

        // Exactamente una en curso: el huésped está físicamente en ésa. Cubre el cambio de
        // casita a mitad de estancia, donde las fechas ya dicen cuál es sin preguntar nada.
        if (count($enCurso) === 1) {
            return ['evento' => $enCurso[0], 'ambiguo' => false, 'candidatas' => $eventos];
        }

        // Varias a la vez, o ninguna empezada: no hay dato que resuelva esto. Se pregunta.
        return [
            'evento' => null,
            'ambiguo' => true,
            'candidatas' => $enCurso !== [] ? $enCurso : $eventos,
        ];
    }

    /**
     * Por slug o por nombre: el modelo puede pasar «casita-4» o «Casita 4» según de dónde lo
     * haya sacado, y las dos formas son legítimas.
     *
     * @param array<int, PmsEventoCalendario> $eventos
     */
    private function porNombre(array $eventos, string $casita): ?PmsEventoCalendario
    {
        $buscada = mb_strtolower(trim($casita));

        foreach ($eventos as $evento) {
            $unidad = $evento->getPmsUnidad();

            if ($unidad !== null
                && (mb_strtolower((string) $unidad->getSlug()) === $buscada
                    || mb_strtolower((string) $unidad->getNombre()) === $buscada)
            ) {
                return $evento;
            }
        }

        return null;
    }

    /**
     * Las que el huésped está ocupando hoy.
     *
     * Se compara por DÍA y no por instante: `inicio`/`fin` llevan la hora de check-in y
     * check-out (§12.5.5 del doc de sync), así que a las 09:00 del día de salida el huésped
     * sigue dentro y su guía le sigue haciendo falta.
     *
     * @param array<int, PmsEventoCalendario> $eventos
     * @return array<int, PmsEventoCalendario>
     */
    private function enCurso(array $eventos): array
    {
        $hoy = new DateTimeImmutable('today');

        return array_values(array_filter($eventos, static function (PmsEventoCalendario $e) use ($hoy): bool {
            $inicio = $e->getInicio();
            $fin = $e->getFin();

            if ($inicio === null || $fin === null) {
                return false;
            }

            return new DateTimeImmutable($inicio->format('Y-m-d')) <= $hoy
                && new DateTimeImmutable($fin->format('Y-m-d')) >= $hoy;
        }));
    }
}
