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
     *  - fecha (\DateTime)
     *  - nroDia (int)
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

            /** @var \App\Entity\ServicioItinerariodia $dia */
            foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia) {
                $fecha = (clone $cotservicio->getFechahorainicio())
                    ->add(new \DateInterval('P' . ($dia->getDia() - 1) . 'D'));

                // primera fecha para calcular nroDia relativo
                $primeraFecha ??= clone $fecha;
                $nroDia = (int)$primeraFecha->diff($fecha)->format('%d') + 1;

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

                // Si ya existe la fecha, acumulamos más servicios/ítems
                if (!isset($itinerario[$key])) {
                    $itinerario[$key] = [
                        'fecha'      => $fecha,
                        'nroDia'     => $nroDia,
                        'fechaitems' => [],
                    ];
                }
                $itinerario[$key]['fechaitems'][] = $tempItinerario;
            }
        }

        // Asegurar orden por fecha ascendente
        \ksort($itinerario);

        // Inserta días libres si hay saltos en nroDia
        return $this->agregarDiasLibres($itinerario);
    }

    /**
     * Itinerario con “agenda” incrustada por SERVICIO.
     * Agrupa por horario idéntico (HH:mm–HH:mm) concatenando títulos con " + ".
     */
    public function getItinerarioConAgenda(CotizacionCotizacion $cotizacion): array
    {
        // 1) Base del itinerario (con días libres y múltiples servicios por día)
        $dias = $this->getItinerario($cotizacion);
        if (empty($dias)) {
            return $dias;
        }

        // 2) Recolectar componentes agendables por día Y servicio
        $agendaPorDiaServicio = []; // [Ymd][servicioId] = [eventos...]

        foreach ($cotizacion->getCotservicios() as $servicio) {
            $sid = $servicio->getId();
            foreach ($servicio->getCotcomponentes() as $componente) {

                /** @var \App\Entity\CotizacionCotcomponente $componente */
                /** @var \App\Entity\ServicioTipocomponente|null $tipo */
                $tipo = $componente->getComponente()?->getTipocomponente();
                if (!$tipo || $tipo->isAgendable() !== true) {
                    continue;
                }

                $ini = $componente->getFechahorainicio();
                $fin = $componente->getFechahorafin();
                if (!$ini || !$fin) {
                    continue;
                }

                // título armado desde items (si no hay, NO se agrega)
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

        // 3) Inyectar agenda por servicio dentro de cada item del día
        foreach ($dias as $key => &$dia) {
            $k = $dia['fecha']->format('Ymd');

            if (empty($dia['fechaitems'])) {
                continue;
            }

            foreach ($dia['fechaitems'] as &$item) {
                $sid = $item['servicioId'] ?? null;
                if (!$sid) {
                    continue;
                }

                $agendaServicio = $agendaPorDiaServicio[$k][$sid] ?? [];
                if (!empty($agendaServicio)) {
                    // ordenar por hora de inicio y agrupar por mismo horario
                    \usort($agendaServicio, fn($a, $b) => $a['fechahorainicio'] <=> $b['fechahorainicio']);
                    $agendaServicio = $this->mergeAgendaSameSchedule($agendaServicio);

                    // guardar la agenda dentro del ítem/servicio
                    $item['agenda'] = $agendaServicio;
                }
            }
            unset($item);
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

        // Aplanar: concateno títulos con el joiner único
        $result = [];
        foreach ($buckets as $row) {
            if (isset($row['_titulos'])) {
                // Limpieza por si hay vacíos/espacios
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

        // Reordenar por hora de inicio por si acaso
        \usort($result, fn($a, $b) => $a['fechahorainicio'] <=> $b['fechahorainicio']);
        return \array_values($result);
    }

    // ---------- Fotos & títulos (sin cambios) ----------
    /**
     * Devuelve la foto principal (MaestroMedio) de un servicio de cotización.
     *
     * Prioridad:
     *  1) Recorre los días del itinerario y, si encuentra un archivo marcado como portada (`isPortada()`),
     *     retorna inmediatamente su medio (`getMedio()`).
     *  2) Si no hay portada, guarda como candidato el PRIMER archivo del PRIMER día marcado como importante (`isImportante()`).
     *  3) Si no hubo portada en ningún día, retorna el medio del candidato (o `null` si no existe).
     *
     * @param CotizacionCotservicio $cotservicio Servicio con itinerario y días/archivos asociados.
     * @return ?MaestroMedio La imagen principal a mostrar o null si no se encontró.
     */
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

    /**
     * Reúne y devuelve TODOS los archivos del itinerario de un servicio, y garantiza que
     * haya un archivo marcado como portada.
     *
     * Lógica:
     * 1) Recorre los días del itinerario y agrega cada archivo a la colección $fotos, en orden.
     * 2) Si encuentra algún archivo con `isPortada() === true`, recuerda que ya existe portada.
     * 3) Además, si un día está marcado como importante (`$dia->isImportante()`) guarda como candidato
     *    el PRIMER archivo de ese día (y su índice dentro de $fotos).
     * 4) Al finalizar:
     *      - Si NO había ninguna portada y SÍ existe candidato del primer día importante,
     *        lo marca como portada (`setPortada(true)`) y lo reubica en su mismo índice.
     *
     * Resultado: colección de archivos (en el mismo orden recorrido), con al menos uno en portada
     * (el que ya lo estaba o, en su defecto, el primer archivo del primer día importante).
     *
     * @param CotizacionCotservicio $cotservicio Servicio con itinerario/días/archivos.
     * @return \Doctrine\Common\Collections\Collection<Itidiaarchivo> Colección de archivos del itinerario.
     */
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

    /**
     * Inserta días libres cuando hay saltos en nroDia. Mantiene el orden cronológico.
     * Los días libres no tienen 'servicioId' ni 'agenda'.
     */
    private function agregarDiasLibres(array $itinerario): array
    {
        // Asegurarnos de recorrer en orden de fecha (claves Ymd)
        \ksort($itinerario);

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

    /**
     * Construye un título concatenando los títulos de los items del componente usando el joiner global.
     */
    private function joinItemTitles($componente): string
    {
        $titulos = [];
        $items = $componente->getComponente()?->getComponenteitems() ?? [];
        foreach ($items as $item) {
            if (\is_object($item) && \method_exists($item, 'getTitulo')) {
                $t = \trim((string) $item->getTitulo());
                if ($t !== '' && !\in_array($t, $titulos, true)) {
                    $titulos[] = $t; // evita duplicados exactos y vacíos
                }
            }
        }
        return \implode(self::AGENDA_JOINER, $titulos);
    }
}
