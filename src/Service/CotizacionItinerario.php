<?php

namespace App\Service;

use App\Entity\CotizacionCotizacion;
use App\Entity\CotizacionCotservicio;
use App\Entity\MaestroMedio;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Clase para procesar itinerarios de una cotización.
 * Separa la lógica de fotos, títulos y días libres.
 */
class CotizacionItinerario
{
    private CotizacionCotizacion $cotizacion;
    private CotizacionCotservicio $cotservicio;
    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Devuelve el itinerario completo de una cotización, incluyendo días libres.
     *
     * @param CotizacionCotizacion $cotizacion
     * @return array
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

                // Primera fecha para cálculo de nroDia
                $primeraFecha ??= clone $fecha;
                $nroDia = (int)$primeraFecha->diff($fecha)->format('%d') + 1;

                $tempItinerario = [
                    'tituloDia' => $dia->getTitulo(),
                    'descripcion' => $dia->getContenido(),
                    'archivos' => $dia->getItidiaarchivos(),
                ];

                if (!empty($dia->getNotaitinerariodia())) {
                    $tempItinerario['nota'] = $dia->getNotaitinerariodia()->getContenido();
                }

                if (!empty($cotservicio->getItinerario()->getTitulo())) {
                    $tempItinerario['titulo'] = $cotservicio->getItinerario()->getTitulo();
                }

                $itinerario[$fecha->format('ymd')] = [
                    'fecha' => $fecha,
                    'nroDia' => $nroDia,
                    'fechaitems' => [$tempItinerario],
                ];
            }
        }

        return $this->agregarDiasLibres($itinerario);
    }

    /**
     * Devuelve la foto principal de un servicio.
     *
     * @param CotizacionCotservicio $cotservicio
     * @return MaestroMedio|null
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
     * Devuelve todas las fotos de un servicio como Collection.
     *
     * @param CotizacionCotservicio $cotservicio
     * @return Collection
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

        // Si no hay portada, la primera importante se marca como portada
        if ($importantFirst && $importantIndex !== null && !$fotos->isEmpty() && !$setPortada) {
            $importantFirst->setPortada(true);
            $fotos->set($importantIndex, $importantFirst);
        }

        return $fotos;
    }

    /**
     * Devuelve el título del itinerario para un servicio en una fecha específica.
     *
     * @param \DateTime $fecha
     * @param CotizacionCotservicio $cotservicio
     * @return string
     */
    public function getTituloItinerario(\DateTime $fecha, CotizacionCotservicio $cotservicio): string
    {
        $itinerarioFechaAux = $this->getItinerarioFechaAux($cotservicio);
        $tituloItinerario = '';

        $diaAnterior = (clone $fecha)->sub(new \DateInterval('P1D'));
        $diaPosterior = (clone $fecha)->add(new \DateInterval('P1D'));

        if (isset($itinerarioFechaAux[$fecha->format('ymd')])) {
            $tituloItinerario = $itinerarioFechaAux[$fecha->format('ymd')];
        } elseif ((int)$fecha->format('H') > 12 && isset($itinerarioFechaAux[$diaPosterior->format('ymd')])) {
            $tituloItinerario = $itinerarioFechaAux[$diaPosterior->format('ymd')];
        } elseif ((int)$fecha->format('H') <= 12 && isset($itinerarioFechaAux[$diaAnterior->format('ymd')])) {
            $tituloItinerario = $itinerarioFechaAux[$diaAnterior->format('ymd')];
        } else {
            $tituloItinerario = reset($itinerarioFechaAux) ?? '';
        }

        return $tituloItinerario ?: $cotservicio->getItinerario()->getTitulo() ?? '';
    }

    /**
     * Genera un array auxiliar con títulos de días importantes para un servicio.
     *
     * @param CotizacionCotservicio $cotservicio
     * @return array
     */
    public function getItinerarioFechaAux(CotizacionCotservicio $cotservicio): array
    {
        $this->cotservicio = $cotservicio;
        $aux = [];

        foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia) {
            if ($dia->isImportante()) {
                $fecha = (clone $cotservicio->getFechahorainicio())->add(new \DateInterval('P' . ($dia->getDia() - 1) . 'D'));
                $aux[$fecha->format('ymd')] = $dia->getTitulo();
            }
        }

        return $aux;
    }

    /**
     * Inserta días libres en el itinerario.
     *
     * @param array $itinerario
     * @return array
     */
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
                        'fecha' => $baseDate->sub(new \DateInterval('P' . ($diferenciaDias - $i) . 'D')),
                        'nroDia' => $itinerarioDia['nroDia'] - $diferenciaDias + $i,
                        'fechaitems' => [[
                            'tituloDia' => $this->translator->trans('dia_libre_titulo', [], 'messages'),
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
}