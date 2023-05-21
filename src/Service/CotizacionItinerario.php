<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\CotizacionCotizacion;
use App\Entity\CotizacionCotservicio;
use App\Entity\CotizacionEstadocotizacion;

class CotizacionItinerario
{
    private CotizacionCotizacion $cotizacion;
    private CotizacionCotservicio $cotservicio;

    public function getItinerario(CotizacionCotizacion $cotizacion): array
    {

        $this->cotizacion = $cotizacion;

        $itinerario = [];
        if($cotizacion->getCotservicios()->count() > 0) {

            foreach ($cotizacion->getCotservicios() as $cotservicio):

                if ($cotservicio->getItinerario()->getItinerariodias()->count() > 0) {

                    foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia):

                        $fecha = clone($cotservicio->getFechahorainicio());
                        $fecha->add(new \DateInterval('P' . ($dia->getDia() - 1) . 'D'));
                        //Las claves son numericas y empiezan en 0
                        if(!isset($primeraFecha)){
                            $primeraFecha = new \DateTime($fecha->format('Y-m-d'));
                        }

                        $currentDate = new \DateTime($fecha->format('Y-m-d'));
                        $nroDia = (int)$primeraFecha->diff($currentDate)->format('%d') + 1;

                        //se sobreescriben en cada iteracion
                        $itinerario[$fecha->format('ymd')]['fecha'] = $fecha;
                        $itinerario[$fecha->format('ymd')]['nroDia'] = $nroDia;

                        $tempItinerario['tituloDia'] = $dia->getTitulo();
                        if(!empty($cotservicio->getItinerario()->getTitulo())){
                            $tempItinerario['titulo'] = $cotservicio->getItinerario()->getTitulo();
                        }
                        $tempItinerario['descripcion'] = $dia->getContenido();
                        $tempItinerario['archivos'] = $dia->getItidiaarchivos();

                        if(!empty($dia->getNotaitinerariodia())){
                            $tempItinerario['nota'] = $dia->getNotaitinerariodia()->getContenido();
                        }

                        $itinerario[$fecha->format('ymd')]['fechaitems'][] = $tempItinerario;
                        unset($tempItinerario);

                    endforeach;
                }
            endforeach;
        }

        return $itinerario;
    }

    public function getTituloItinerario(\DateTime $fecha, CotizacionCotservicio $cotservicio): string
    {
        $itinerarioFechaAux = $this->getItinerarioFechaAux($cotservicio);
        $tituloItinerario = '';

        if(!empty($itinerarioFechaAux)){

            $diaAnterior = clone ($fecha);
            $diaAnterior->sub(new \DateInterval('P1D')) ;
            $diaPosterior = clone ($fecha);
            $diaPosterior->add(new \DateInterval('P1D')) ;

            if(isset($itinerarioFechaAux[$fecha->format('ymd')])){
                $tituloItinerario = $itinerarioFechaAux[$fecha->format('ymd')];
            }elseif((int)$fecha->format('H') > 12 && isset($itinerarioFechaAux[$diaPosterior->format('ymd')])){
                $tituloItinerario = $itinerarioFechaAux[$diaPosterior->format('ymd')];
            }elseif((int)$fecha->format('H') <= 12 && isset($itinerarioFechaAux[$diaAnterior->format('ymd')])){
                $tituloItinerario = $itinerarioFechaAux[$diaAnterior->format('ymd')];
            }else{
                $tituloItinerario = reset($itinerarioFechaAux) ?? '';
            }
        }
        //primero es el importante por dia si no hubera importantes se coje el titulo
        if(empty($tituloItinerario) && !empty($cotservicio->getItinerario()->getTitulo())){
            $tituloItinerario = $cotservicio->getItinerario()->getTitulo();
        }

        return $tituloItinerario;
    }

    public function getItinerarioFechaAux(CotizacionCotservicio $cotservicio): array
    {
        $this->cotservicio = $cotservicio;

        $itinerarioFechaAux = [];

        if ($cotservicio->getItinerario()->getItinerariodias()->count() > 0) {

            foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia):
                $fecha = clone($cotservicio->getFechahorainicio());
                $fecha->add(new \DateInterval('P' . ($dia->getDia() - 1) . 'D'));

                if($dia->isImportante() === true){
                    $itinerarioFechaAux[$fecha->format('ymd')] = $dia->getTitulo();
                }

            endforeach;
        }

        return $itinerarioFechaAux;
    }
}