<?php

namespace App\Oweb\Service;

use App\Oweb\Entity\CotizacionCotizacion;
use App\Oweb\Entity\CotizacionCotservicio;
use App\Oweb\Entity\MaestroMedio;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Procesa itinerarios de una cotización y permite incrustar la
 * agenda por SERVICIO dentro de cada ítem del día.
 *
 * Reglas de agenda:
 *  - Solo componentes con Tipocomponente::isAgendable() === true
 *  - Solo si el componente tiene ítems (título armado desde items)
 *  - En Twig se filtran los que tienen inicio != fin
 *  - Si varios componentes comparten el MISMO horario (inicio/fin),
 *    se agrupan en un solo registro concatenando títulos con " + ".
 */
class CotizacionItinerario
{
    /** Separador único para títulos agrupados en el mismo horario. Reutilízalo en otros lados si lo necesitas. */
    public const AGENDA_JOINER = ' + ';

    private CotizacionCotizacion $cotizacion;
    private CotizacionCotservicio $cotservicio;
    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Itinerario “clásico”: días con contenido e inserción de días libres.
     * Acumula múltiples servicios por fecha y adjunta 'servicioId' en cada ítem.
     *
     * Devuelve array indexado por fecha (Ymd) con:
     *  - fecha (\DateTimeInterface)
     *  - nroDia (int, asignado secuencialmente)
     *  - fechaitems (array de ítems/servicios del día)
     */
    public function getItinerario(CotizacionCotizacion $cotizacion): array
    {
        $this->cotizacion = $cotizacion;
        $itinerario = [];

        foreach ($cotizacion->getCotservicios() as $cotservicio) {
            if ($cotservicio->getItinerario()->getItinerariodias()->count() === 0) {
                continue;
            }

            /** @var \App\Oweb\Entity\ServicioItinerariodia $dia */
            foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia) {
                $fecha = (clone $cotservicio->getFechahorainicio())
                    ->add(new \DateInterval('P' . ($dia->getDia() - 1) . 'D'));

                $tempItinerario = [
                    'servicioId'  => $cotservicio->getId(), // clave para ligar agenda por servicio
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

                $key = $fecha->format('Ymd');

                if (!isset($itinerario[$key])) {
                    $itinerario[$key] = [
                        'fecha'      => $fecha,
                        'fechaitems' => [],
                    ];
                }
                $itinerario[$key]['fechaitems'][] = $tempItinerario;
            }
        }

        // 1) Asegurar orden por fecha ascendente
        \ksort($itinerario);

        // 2) Insertar días libres únicamente por huecos reales de calendario
        $itinerarioLineal = $this->insertarDiasLibresPorFechas($itinerario);

        // 3) Asignar nroDia secuencial (1..N) ya con días libres incluidos
        $itinerarioNumerado = $this->asignarNroDiaSecuencial($itinerarioLineal);

        return $itinerarioNumerado;
    }

    /**
     * Itinerario con “agenda” incrustada por SERVICIO.
     * Agrupa por horario idéntico (HH:mm–HH:mm) concatenando títulos con " + ".
     */
    public function getItinerarioConAgenda(CotizacionCotizacion $cotizacion): array
    {
        $dias = $this->getItinerario($cotizacion);
        if (empty($dias)) {
            return $dias;
        }

        $agendaPorDiaServicio = [];

        foreach ($cotizacion->getCotservicios() as $servicio) {
            $sid = $servicio->getId();
            foreach ($servicio->getCotcomponentes() as $componente) {

                /** @var \App\Oweb\Entity\CotizacionCotcomponente $componente */
                /** @var \App\Oweb\Entity\ServicioTipocomponente|null $tipo */
                $tipo = $componente->getComponente()?->getTipocomponente();
                if (!$tipo || $tipo->isAgendable() !== true) {
                    continue;
                }

                $ini = $componente->getFechahorainicio();
                $fin = $componente->getFechahorafin();
                if (!$ini || !$fin) {
                    continue;
                }

                $tituloItems = $this->joinItemTitles($componente);
                if ($tituloItems === '') {
                    continue;
                }

                $fechaKey = $ini->format('Ymd');
                $agendaPorDiaServicio[$fechaKey][$sid][] = [
                    'tituloItinerario' => $this->getTituloItinerario($ini, $servicio),
                    'nombre'           => (string)($componente->getComponente()?->getNombre() ?? ''),
                    'tipoComponente'   => (string)($tipo->getNombre() ?? ''),
                    'fechahorainicio'  => $ini,
                    'fechahorafin'     => $fin,
                    'titulo'           => $tituloItems,
                ];
            }
        }

        foreach ($dias as &$dia) {
            $k = $dia['fecha']->format('Ymd');
            foreach ($dia['fechaitems'] as &$item) {
                $sid = $item['servicioId'] ?? null;
                if (!$sid) {
                    continue;
                }
                $agendaServicio = $agendaPorDiaServicio[$k][$sid] ?? [];
                if (!empty($agendaServicio)) {
                    \usort($agendaServicio, fn($a, $b) => $a['fechahorainicio'] <=> $b['fechahorainicio']);
                    $agendaServicio = $this->mergeAgendaSameSchedule($agendaServicio);
                    $item['agenda'] = $agendaServicio;
                }
            }
            unset($item);
        }
        unset($dia);

        return $dias;
    }

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

            $key = $ini->format('H:i') . '|' . $fin->format('H:i');

            if (!isset($buckets[$key])) {
                $row['_titulos'] = [$row['titulo']];
                $buckets[$key]   = $row;
            } else {
                if (!\in_array($row['titulo'], $buckets[$key]['_titulos'], true)) {
                    $buckets[$key]['_titulos'][] = $row['titulo'];
                }
            }
        }

        $result = [];
        foreach ($buckets as $row) {
            if (isset($row['_titulos'])) {
                $clean = [];
                foreach ($row['_titulos'] as $t) {
                    $t = \trim((string)$t);
                    if ($t !== '' && !\in_array($t, $clean, true)) {
                        $clean[] = $t;
                    }
                }
                $row['titulo'] = \implode(self::AGENDA_JOINER, $clean);
                unset($row['_titulos']);
            }
            $result[] = $row;
        }

        \usort($result, fn($a, $b) => $a['fechahorainicio'] <=> $b['fechahorainicio']);
        return \array_values($result);
    }

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

    private function insertarDiasLibresPorFechas(array $itinerario): array
    {
        if (empty($itinerario)) {
            return [];
        }

        \ksort($itinerario);
        $diasCompletos = [];
        $prevDate = null;

        foreach ($itinerario as $dia) {
            $current = new \DateTimeImmutable($dia['fecha']->format('Y-m-d'));
            if ($prevDate !== null) {
                $gap = (int)$prevDate->diff($current)->format('%a');
                if ($gap > 1) {
                    for ($i = 1; $i <= $gap - 1 && $i <= 30; $i++) {
                        $freeDate = $prevDate->add(new \DateInterval('P' . $i . 'D'));
                        $diasCompletos[] = [
                            'fecha'      => $freeDate,
                            'fechaitems' => [[
                                'tituloDia'   => $this->translator->trans('dia_libre_titulo', [], 'messages'),
                                'descripcion' => '<p>' . $this->translator->trans('dia_libre_contenido', [], 'messages') . '</p>',
                            ]],
                        ];
                    }
                }
            }
            $diasCompletos[] = $dia;
            $prevDate = $current;
        }

        return $diasCompletos;
    }

    private function asignarNroDiaSecuencial(array $dias): array
    {
        $n = 1;
        foreach ($dias as &$d) {
            $d['nroDia'] = $n++;
        }
        unset($d);
        return $dias;
    }

    /**
     * ⚠️ Método antiguo, ya no se usa.
     * Se deja comentado para referencia y compatibilidad si otro código externo lo invoca.
     */
    /*
    private function agregarDiasLibres(array $itinerario): array
    {
        // Método obsoleto: reemplazado por insertarDiasLibresPorFechas()
        return $itinerario;
    }
    */

    private function joinItemTitles($componente): string
    {
        $titulos = [];
        $items = $componente->getComponente()?->getComponenteitems() ?? [];
        foreach ($items as $item) {
            if (\is_object($item) && \method_exists($item, 'getTitulo')) {
                $t = \trim((string) $item->getTitulo());
                if ($t !== '' && !\in_array($t, $titulos, true)) {
                    $titulos[] = $t;
                }
            }
        }
        return \implode(self::AGENDA_JOINER, $titulos);
    }
}
