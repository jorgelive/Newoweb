<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\GrupoTipoEnum;

/**
 * Quién se queda fuera cuando el grupo se parte.
 *
 * ── El fallo que evita ──────────────────────────────────────────────────────
 * Se parte el vuelo en «Nacional» (22) e «Internacional» (18) y el grupo tiene 41 personas. La
 * número 41 **no está en ninguno de los dos**, así que su itinerario no le enseña ningún vuelo. No
 * hay error, no hay fila roja: hay alguien que abre su viaje y no ve cómo llega.
 *
 * Es la familia de fallo que este proyecto persigue —el que no se ve— y aquí muerde peor que de
 * costumbre, porque **quien lo sufre no puede reportarlo**: no echa de menos lo que no sabía que
 * existía. La única forma de enterarse es preguntarlo.
 *
 * ── La regla ────────────────────────────────────────────────────────────────
 * ```
 * unión(miembros de los subgrupos de un eje)  ⊇  pasajeros del expediente
 * ```
 *
 * ⚠️ **Por EJE, no en total.** Estar en una habitación no cubre no estar en ningún vuelo: son
 * preguntas distintas y hay que hacerlas por separado. Un pasajero puede pertenecer a varios ejes
 * a la vez —de hecho es lo normal— y a ninguno de ellos por completo.
 *
 * ⚠️ **Un eje sin ningún subgrupo NO se avisa.** Que no haya vuelos declarados significa que este
 * viaje no usa ese eje, no que falten 41 personas. Avisar ahí llenaría de rojo cualquier
 * expediente normal, y una lista de avisos que casi siempre miente no la lee nadie —que es cómo se
 * pierde el aviso que sí importaba—.
 */
final readonly class CoberturaDeSubgrupos
{
    /**
     * Los ejes en los que falta gente, con quién falta.
     *
     * @return list<array{eje: string, ejeLabel: string, faltan: list<string>}>
     */
    public function revisar(CotizacionFile $file): array
    {
        if (!$file->isUsaPadron()) {
            return [];
        }

        /** @var array<string, list<string>> $cubiertosPorEje */
        $cubiertosPorEje = [];

        foreach ($file->getGrupos() as $grupo) {
            $eje = $grupo->getTipo()?->value;

            if ($eje === null) {
                continue;
            }

            // El eje existe aunque el subgrupo esté vacío: alguien lo declaró.
            $cubiertosPorEje[$eje] ??= [];

            foreach ($grupo->getMiembros() as $pertenencia) {
                $id = $pertenencia->getPasajero()?->getId()?->toRfc4122();

                if ($id !== null) {
                    $cubiertosPorEje[$eje][] = $id;
                }
            }
        }

        if ($cubiertosPorEje === []) {
            return [];
        }

        $hallazgos = [];

        foreach ($cubiertosPorEje as $eje => $cubiertos) {
            $faltan = [];

            foreach ($file->getFilepasajeros() as $pasajero) {
                if (in_array($pasajero->getId()?->toRfc4122(), $cubiertos, true)) {
                    continue;
                }

                $faltan[] = trim($pasajero->getNombre() . ' ' . $pasajero->getApellido());
            }

            if ($faltan === []) {
                continue;
            }

            $hallazgos[] = [
                'eje' => $eje,
                'ejeLabel' => GrupoTipoEnum::tryFrom($eje)?->label() ?? $eje,
                'faltan' => $faltan,
            ];
        }

        return $hallazgos;
    }
}
