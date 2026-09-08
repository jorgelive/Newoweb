<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Vuelos;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Enum\GrupoTipoEnum;

/**
 * Saca los vuelos del expediente **en el mismo JSON que se carga**.
 *
 * ## Por qué existe, y por qué no es un formulario
 *
 * Un PNR con cuatro tramos, cada uno con número, fecha, aerolínea, ruta y dos horas, más las notas
 * de la reserva, es un formulario con veintitantos campos anidados en dos niveles. Y lo que de
 * verdad se hace no es crearlo de cero: es **corregir un horario** cuando la aerolínea reprograma.
 *
 * Descargar lo que hay, editar la línea que cambió y volver a cargarlo es el camino corto — y de
 * paso el formato deja de ser un examen: se aprende leyendo lo propio.
 *
 * ## El contrato es el del importador, no uno nuevo
 *
 * 🔥 **Espejo exacto de {@see VuelosImportador}. Al tocar uno, tocar el otro.** Si el exportador
 * escribiera una clave que el importador no lee —o al revés—, el viaje de ida y vuelta perdería
 * datos **en silencio**: se descarga, se edita una línea, se vuelve a cargar, y lo que el
 * exportador nombró distinto desaparece sin un solo error. Es la familia de fallo que persigue
 * `PadronFormato` teniendo una sola definición para leer y escribir.
 *
 * Aquí no se puede unificar en una constante —una lee y el otro escribe— así que la garantía es
 * la comprobación: exportar, reimportar en ensayo y no obtener **ningún** cambio.
 *
 * ⚠️ **`pnr_nuevo` no se exporta**, y no es un olvido: es una instrucción de renombrado, no un
 * dato del expediente. Escribirlo en la salida haría que reimportar sin tocar nada renombrara el
 * PNR a sí mismo — inofensivo hoy, y una bomba el día que alguien edite ese campo por error.
 *
 * ⚠️ **Sólo el eje de reserva aérea.** Una habitación no tiene vuelos, y sacarla con `"vuelos": []`
 * haría que reimportar **desvinculara** lo que ese grupo tuviera: la lista de un PNR REEMPLAZA.
 */
final readonly class VuelosExportador
{
    /**
     * @return list<array{pnr: string, emitido: bool, notas?: list<string>, vuelos: list<array<string, mixed>>}>
     */
    public function exportar(CotizacionFile $file): array
    {
        $reservas = [];

        foreach ($file->getGrupos() as $grupo) {
            if ($grupo->getTipo() !== GrupoTipoEnum::RESERVA_AEREA) {
                continue;
            }

            $reservas[] = $this->reserva($grupo);
        }

        return $reservas;
    }

    /** El JSON tal cual se pega, ya con sangría: se edita a mano, así que se lee a mano. */
    public function comoTexto(CotizacionFile $file): string
    {
        return (string) json_encode(
            $this->exportar($file),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return array{pnr: string, emitido: bool, notas?: list<string>, vuelos: list<array<string, mixed>>} */
    private function reserva(CotizacionFileGrupo $grupo): array
    {
        $reserva = [
            'pnr' => (string) $grupo->getClave(),
            'emitido' => $grupo->isEmitido(),
        ];

        $notas = $grupo->getNotas();

        if ($notas !== []) {
            $reserva['notas'] = $notas;
        }

        $vuelos = [];

        foreach ($grupo->getVuelos() as $vuelo) {
            $tramo = [
                'numero' => (string) $vuelo->getNumero(),
                'fecha' => $vuelo->getFecha()?->format('Y-m-d'),
                'aerolinea' => $vuelo->getAerolinea(),
                'leg' => [
                    'origen' => $vuelo->getOrigen(),
                    'destino' => $vuelo->getDestino(),
                    // ⚠️ `Y-m-d H:i` y no ISO con zona: es lo que acepta el importador y lo que se
                    // teclea. Un `+00:00` de más y la hora que se edita deja de ser la del billete.
                    'salida' => $vuelo->getSalida()?->format('Y-m-d H:i'),
                    'llegada' => $vuelo->getLlegada()?->format('Y-m-d H:i'),
                ],
            ];

            $notasDelVuelo = $vuelo->getNotas();

            if ($notasDelVuelo !== []) {
                $tramo['notas'] = $notasDelVuelo;
            }

            $vuelos[] = $tramo;
        }

        $reserva['vuelos'] = $vuelos;

        return $reserva;
    }
}
