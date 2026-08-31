<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Pax\Service\TextosUi;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use DateTimeInterface;
use IntlDateFormatter;
use IntlDatePatternGenerator;

/**
 * «Del 28 al 31 de agosto · Casita 2»: las estancias de una reserva, dichas en su idioma.
 *
 * ── Por qué no bastan `checkin_date` y `checkout_date` ──────────────────────
 * Son el **mínimo y el máximo** de la reserva, así que con más de una estancia la frase deja de
 * ser cierta. Es el mismo error que `getNoches()` tenía con el hueco, sólo que allí tocaba
 * dinero. Medido el 31/08/2026 sobre reservas reales:
 *
 * | Reserva | Lo que decían las variables | La verdad |
 * |---|---|---|
 * | `3DAGPB` | «del 28 de agosto al 6 de septiembre» | dos estancias con **4 noches de hueco** |
 * | `XDGCYT` | «(Casita 1, Casita 4, Casita 6, Casita 7)» | cuatro casitas, dos con fechas distintas |
 * | `KXET9H` | «del 27 de septiembre al 3 de octubre» | dos casitas que ni empiezan el mismo día |
 *
 * Y no es raro: 15 de las últimas multi-estancia. Casi todas contiguas o en paralelo, donde el
 * rango acierta **por casualidad** — pero el nombre de la casita ya no.
 *
 * ── Cómo se dice ────────────────────────────────────────────────────────────
 * Se agrupa por PAR DE FECHAS. Si todos los tramos coinciden —lo normal cuando alguien reserva
 * dos casitas a la vez— sale una sola línea con las casitas juntas; si difieren, un tramo por
 * línea con la suya. Nadie tiene que leer cuatro veces las mismas fechas.
 *
 * ⚠️ **La gramática del rango es de cada idioma, no del código.** «del … al …» en español, «from
 * … to …» en inglés, «du … au …» en francés: eso vive en `res_estancia_tramo` con dos
 * marcadores, igual que el saludo. Las fechas las formatea ICU con el patrón que cada locale
 * considera correcto —«28 de agosto» / «August 28»— vía {@see IntlDatePatternGenerator}, no con
 * una tabla de meses en español como la que arrastra `GenerarMensajePrepagoSkill`.
 */
final readonly class PmsRedactorDeEstancias
{
    public function __construct(private TextosUi $textos)
    {
    }

    /** Una línea por tramo distinto, o cadena vacía si no hay nada que decir. */
    public function texto(PmsReserva $reserva, string $idioma): string
    {
        /** @var array<string, array{inicio: DateTimeInterface, fin: DateTimeInterface, unidades: list<string>}> $tramos */
        $tramos = [];

        foreach ($reserva->getEventosCalendario() as $evento) {
            if ((string) $evento->getEstado()?->getId() === PmsEventoEstado::CODIGO_CANCELADA) {
                continue;
            }

            $inicio = $evento->getInicio();
            $fin = $evento->getFin();

            if ($inicio === null || $fin === null) {
                continue;
            }

            $clave = $inicio->format('Y-m-d') . '|' . $fin->format('Y-m-d');
            $tramos[$clave] ??= ['inicio' => $inicio, 'fin' => $fin, 'unidades' => []];

            $unidad = $evento->getPmsUnidad()?->getNombre();

            // `in_array` y no un `Set`: dos eventos de la MISMA casita y las mismas fechas son el
            // espejo manual de Beds24 (§6.3), y repetir «Casita 2 · Casita 2» delataría una
            // interioridad nuestra en un mensaje al huésped.
            if ($unidad !== null && $unidad !== '' && !in_array($unidad, $tramos[$clave]['unidades'], true)) {
                $tramos[$clave]['unidades'][] = $unidad;
            }
        }

        if ($tramos === []) {
            return '';
        }

        usort($tramos, static fn (array $a, array $b): int => $a['inicio'] <=> $b['inicio']);

        $lineas = [];

        foreach ($tramos as $tramo) {
            $rango = $this->rango($tramo['inicio'], $tramo['fin'], $idioma);
            $lineas[] = $tramo['unidades'] === []
                ? $rango
                : sprintf('%s · %s', $rango, implode(', ', $tramo['unidades']));
        }

        return implode("\n", $lineas);
    }

    /**
     * «del 28 al 31 de agosto», con la preposición que ponga cada idioma.
     *
     * El año sólo aparece cuando el tramo cruza de año: dentro del mismo, decirlo es ruido — y en
     * una reserva de hotel el año se da por hecho.
     */
    private function rango(DateTimeInterface $inicio, DateTimeInterface $fin, string $idioma): string
    {
        $mismoAno = $inicio->format('Y') === $fin->format('Y');
        $patron = $this->patron($idioma, $mismoAno ? 'dMMMM' : 'dMMMMy');

        return $this->textos->texto('res_estancia_tramo', $idioma, [
            'desde' => (string) IntlDateFormatter::formatObject($inicio, $patron, $idioma),
            'hasta' => (string) IntlDateFormatter::formatObject($fin, $patron, $idioma),
        ]);
    }

    /**
     * El patrón que ESE idioma considera correcto para ese esqueleto.
     *
     * `dMMMM` no es un formato: es lo que se quiere decir —día y mes con nombre—. ICU lo traduce
     * a `d 'de' MMMM` en español y a `MMMM d` en inglés. Escribirlo a mano sería volver a la
     * tabla de meses en español.
     */
    private function patron(string $idioma, string $esqueleto): string
    {
        $patron = (new IntlDatePatternGenerator($idioma))->getBestPattern($esqueleto);

        // `false` si el idioma no lo resuelve. Se cae al esqueleto crudo, que ICU también sabe
        // interpretar: da un formato menos idiomático, no una fecha equivocada.
        return $patron !== false ? $patron : $esqueleto;
    }
}
