<?php

namespace App\Service;

use App\Entity\CotizacionCotizacion;
use App\Entity\CotizacionCotservicio;
use App\Entity\MaestroMedio;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Procesa itinerarios de una cotización y permite incrustar la
 * agenda (componentes agendables) por día.
 *
 * Reglas de agenda:
 *  - Solo componentes con Tipocomponente::isAgendable() === true
 *  - Solo si el componente tiene ítems (título armado desde items)
 *  - En el Twig se filtran los que tienen inicio != fin
 *  - Si varios componentes comparten el MISMO horario (inicio/fin),
 *    se agrupan en un solo registro concatenando títulos con " + ".
 */
class CotizacionItinerario
{
    /** Separador para títulos agrupados en el mismo horario. */
    private const AGENDA_JOINER = ' + ';

    private CotizacionCotizacion $cotizacion;
    private CotizacionCotservicio $cotservicio;
    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Itinerario “clásico”: días con contenido y días libres insertados.
     * Devuelve array indexado por fecha (Ymd) con:
     *  - fecha (\DateTime)
     *  - nroDia (int)
     *  - fechaitems (array)
     */
    public function getItinerario(CotizacionCotizacion $cotizacion): array
    {
        $this->cotizacion = $cotizacion;
        $itinerario = [];

        foreach ($cotizacion->getCotservicios() as $cotservicio) {
            if ($cotservicio->getItinerario()->getItinerariodias()->count() === 0) {
                continue;
            }

            foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia) {
                $fecha = (clone $cotservicio->getFechahorainicio())
                    ->add(new \DateInterval('P' . ($dia->getDia() - 1) . 'D'));

                // primera fecha para calcular nroDia relativo
                $primeraFecha ??= clone $fecha;
                $nroDia = (int)$primeraFecha->diff($fecha)->format('%d') + 1;

                $tempItinerario = [
                    'tituloDia'   => $dia->getTitulo(),
                    'descripcion' => $dia->getContenido(),
                    'archivos'    => $dia->getItidiaarchivos(),
                ];

                if (!empty($dia->getNotaitinerariodia())) {
                    $tempItinerario['nota'] = $dia->getNotaitinerariodia()->getContenido();
                }

                if (!empty($cotservicio->getItinerario()->getTitulo())) {
                    $tempItinerario['titulo'] = $cotservicio->getItinerario()->getTitulo();
                }

                $itinerario[$fecha->format('Ymd')] = [
                    'fecha'      => $fecha,
                    'nroDia'     => $nroDia,
                    'fechaitems' => [$tempItinerario],
                ];
            }
        }

        return $this->agregarDiasLibres($itinerario);
    }

    /**
     * Itinerario con “agenda” incrustada por día.
     * Agrupa por horario idéntico (HH:mm–HH:mm) concatenando títulos con " + ".
     */
    public function getItinerarioConAgenda(CotizacionCotizacion $cotizacion): array
    {
        // 1) Base del itinerario (con días libres)
        $dias = $this->getItinerario($cotizacion);
        if (empty($dias)) {
            return $dias;
        }

        // 2) Recolecto todos los componentes agendables con ítems
        $agendaPorDia = []; // Ymd => [eventos...]

        foreach ($cotizacion->getCotservicios() as $servicio) {
            foreach ($servicio->getCotcomponentes() as $componente) {
                $tipo = $componente->getComponente()?->getTipocomponente();
                if (!$tipo || $tipo->isAgendable() !== true) {
                    continue;
                }

                $ini = $componente->getFechahorainicio();
                $fin = $componente->getFechahorafin();
                if (!$ini || !$fin) {
                    continue;
                }

                // título desde items (si no hay, NO se agrega, igual que en CotizacionAgenda)
                $tituloItems = $this->joinItemTitles($componente);
                if ($tituloItems === '') {
                    continue;
                }

                $fechaKey = $ini->format('Ymd');
                $agendaPorDia[$fechaKey][] = [
                    'tituloItinerario' => $this->getTituloItinerario($ini, $servicio),
                    'nombre'           => (string)($componente->getComponente()?->getNombre() ?? ''),
                    'tipoComponente'   => (string)($tipo->getNombre() ?? ''),
                    'fechahorainicio'  => $ini,
                    'fechahorafin'     => $fin,
                    'titulo'           => $tituloItems,
                ];
            }
        }

        // 3) Inserto la agenda (ordenada y AGRUPADA por mismo horario) en cada día
        foreach ($dias as $key => &$dia) {
            $k = $dia['fecha']->format('Ymd');
            $agendaDia = $agendaPorDia[$k] ?? [];

            // ordenar por hora de inicio
            \usort($agendaDia, fn($a, $b) => $a['fechahorainicio'] <=> $b['fechahorainicio']);

            // AGRUPAR por mismo horario (inicio y fin iguales)
            $agendaDia = $this->mergeAgendaSameSchedule($agendaDia);

            if (!empty($agendaDia)) {
                $dia['agenda'] = $agendaDia;
            }
        }
        unset($dia);

        return $dias;
    }

    // ---------- Agrupador por horario idéntico ----------

    /**
     * Une entradas con el MISMO horario (H:i–H:i), concatenando títulos sin duplicar.
     *
     * @param array<int, array<string,mixed>> $agendaDia
     * @return array<int, array<string,mixed>>
     */
    private function mergeAgendaSameSchedule(array $agendaDia): array
    {
        if (empty($agendaDia)) {
            return $agendaDia;
        }

        $buckets = [];
        foreach ($agendaDia as $row) {
            $ini = $row['fechahorainicio'] ?? null;
            $fin = $row['fechahorafin'] ?? null;
            if (!$ini instanceof \DateTimeInterface || !$fin instanceof \DateTimeInterface) {
                continue;
            }

            // Clave por horario (minuto exacto). Si quieres segundos, usa 'H:i:s'.
            $key = $ini->format('H:i') . '|' . $fin->format('H:i');

            if (!isset($buckets[$key])) {
                $row['_titulos'] = [$row['titulo']];
                $buckets[$key]   = $row;
            } else {
                // Evitar títulos repetidos exactos
                if (!\in_array($row['titulo'], $buckets[$key]['_titulos'], true)) {
                    $buckets[$key]['_titulos'][] = $row['titulo'];
                }
            }
        }

        // Aplanar: concateno títulos con ' + '
        $result = [];
        foreach ($buckets as $row) {
            if (isset($row['_titulos'])) {
                $row['titulo'] = \implode(self::AGENDA_JOINER, $row['_titulos']);
                unset($row['_titulos']);
            }
            $result[] = $row;
        }

        // Reordenar por hora de inicio por si acaso
        \usort($result, fn($a, $b) => $a['fechahorainicio'] <=> $b['fechahorainicio']);
        return \array_values($result);
    }

    // ---------- Fotos & títulos (sin cambios) ----------

    public function getMainPhoto(CotizacionCotservicio $cotservicio): ?MaestroMedio
    {
        $primerArchivoImportante = null;

        foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia) {
            foreach ($dia->getItidiaarchivos() as $key => $archivo) {
                if ($archivo->isPortada()) {
                    return $archivo->getMedio();
                }
                if ($dia->isImportante() && $key === 0) {
                    $primerArchivoImportante = $archivo;
                }
            }
        }

        return $primerArchivoImportante?->getMedio() ?? null;
    }

    public function getFotos(CotizacionCotservicio $cotservicio): Collection
    {
        $fotos = new ArrayCollection();
        $importantFirst = null;
        $importantIndex = null;
        $setPortada = false;

        foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia) {
            foreach ($dia->getItidiaarchivos() as $key => $archivo) {
                if ($archivo->isPortada()) {
                    $setPortada = true;
                }
                $fotos->add($archivo);

                if ($dia->isImportante() && $key === 0) {
                    $importantFirst = $archivo;
                    $importantIndex = $fotos->count() - 1;
                }
            }
        }

        if ($importantFirst && $importantIndex !== null && !$fotos->isEmpty() && !$setPortada) {
            $importantFirst->setPortada(true);
            $fotos->set($importantIndex, $importantFirst);
        }

        return $fotos;
    }

    /**
     * Heurística de título de itinerario (igual que tu versión).
     */
    public function getTituloItinerario(\DateTime $fecha, CotizacionCotservicio $cotservicio): string
    {
        $itinerarioFechaAux = $this->getItinerarioFechaAux($cotservicio);
        $tituloItinerario = '';

        $diaAnterior  = (clone $fecha)->sub(new \DateInterval('P1D'));
        $diaPosterior = (clone $fecha)->add(new \DateInterval('P1D'));

        if (isset($itinerarioFechaAux[$fecha->format('Ymd')])) {
            $tituloItinerario = $itinerarioFechaAux[$fecha->format('Ymd')];
        } elseif ((int)$fecha->format('H') > 12 && isset($itinerarioFechaAux[$diaPosterior->format('Ymd')])) {
            $tituloItinerario = $itinerarioFechaAux[$diaPosterior->format('Ymd')];
        } elseif ((int)$fecha->format('H') <= 12 && isset($itinerarioFechaAux[$diaAnterior->format('Ymd')])) {
            $tituloItinerario = $itinerarioFechaAux[$diaAnterior->format('Ymd')];
        } else {
            $tituloItinerario = reset($itinerarioFechaAux) ?: '';
        }

        return $tituloItinerario ?: ($cotservicio->getItinerario()->getTitulo() ?? '');
    }

    public function getItinerarioFechaAux(CotizacionCotservicio $cotservicio): array
    {
        $this->cotservicio = $cotservicio;
        $aux = [];

        foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia) {
            if ($dia->isImportante()) {
                $fecha = (clone $cotservicio->getFechahorainicio())->add(new \DateInterval('P' . ($dia->getDia() - 1) . 'D'));
                $aux[$fecha->format('Ymd')] = $dia->getTitulo();
            }
        }

        return $aux;
    }

    // ---------- Helpers internos ----------

    private function agregarDiasLibres(array $itinerario): array
    {
        $itinerarioConLibres = [];
        $diaEsperado = 1;

        foreach ($itinerario as $itinerarioDia) {
            if ($itinerarioDia['nroDia'] === $diaEsperado) {
                $diaEsperado++;
                $itinerarioConLibres[] = $itinerarioDia;
            } else {
                $diferenciaDias = $itinerarioDia['nroDia'] - $diaEsperado;
                $baseDate = new \DateTimeImmutable($itinerarioDia['fecha']->format('Y-m-d'));

                for ($i = 0; $i < $diferenciaDias && $i < 30; $i++) {
                    $freeDayTemp = [
                        'fecha'      => $baseDate->sub(new \DateInterval('P' . ($diferenciaDias - $i) . 'D')),
                        'nroDia'     => $itinerarioDia['nroDia'] - $diferenciaDias + $i,
                        'fechaitems' => [[
                            'tituloDia'   => $this->translator->trans('dia_libre_titulo', [], 'messages'),
                            'descripcion' => '<p>' . $this->translator->trans('dia_libre_contenido', [], 'messages') . '</p>',
                        ]],
                    ];
                    $itinerarioConLibres[] = $freeDayTemp;
                }

                $diaEsperado = $itinerarioDia['nroDia'] + 1;
                $itinerarioConLibres[] = $itinerarioDia;
            }
        }

        return $itinerarioConLibres;
    }

    private function joinItemTitles($componente): string
    {
        $titulos = [];
        $items = $componente->getComponente()?->getComponenteitems() ?? [];
        foreach ($items as $item) {
            if (\is_object($item) && \method_exists($item, 'getTitulo')) {
                $titulos[] = $item->getTitulo();
            }
        }
        return \implode(', ', $titulos);
    }
}
