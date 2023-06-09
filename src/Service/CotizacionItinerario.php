<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\CotizacionCotizacion;
use App\Entity\CotizacionCotservicio;
use App\Entity\CotizacionEstadocotizacion;
use function Symfony\Component\HttpKernel\Log\format;

class CotizacionItinerario
{
    private CotizacionCotizacion $cotizacion;
    private CotizacionCotservicio $cotservicio;
    private TranslatorInterface $translator;

    function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    public function getItinerario(CotizacionCotizacion $cotizacion): array
    {

        $this->cotizacion = $cotizacion;

        $itinerario = [];
        if($cotizacion->getCotservicios()->count() > 0) {

            foreach ($cotizacion->getCotservicios() as $cotservicio):

                if ($cotservicio->getItinerario()->getItinerariodias()->count() > 0) {

                    //iniciamos la variable para definir si ingresar dias libres
                    $diaEsperado = 1;

                    foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia):

                        $fecha = new \DateTime($cotservicio->getFechahorainicio()->format('Y-m-d'));
                        $fecha->add(new \DateInterval('P' . ($dia->getDia() - 1) . 'D'));
                        //Las claves son numericas y empiezan en 0
                        if(!isset($primeraFecha)){
                            $primeraFecha = new \DateTime($fecha->format('Y-m-d'));
                        }

                        $currentDate = new \DateTime($fecha->format('Y-m-d'));

                        $nroDia = (int)$primeraFecha->diff($currentDate)->format('%d') + 1;

                        //se sobreescriben en cada iteración
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

        $itinerarioConLibres = [];
        $diaEsperado = 1;
        foreach ($itinerario as $itinerarioDia){
            if($itinerarioDia['nroDia'] == $diaEsperado){
                $diaEsperado = $itinerarioDia['nroDia'] + 1;
                $itinerarioConLibres[] = $itinerarioDia;
            }else{
                //estan ordenados por fecha por lo que siempre serán mayores
                $diferenciaDias = $itinerarioDia['nroDia'] - $diaEsperado;
                $baseDate = new \DateTimeImmutable($itinerarioDia['fecha']->format('Y-m-d'));
                //limito a 30 por si hay error
                for ($i = 0; $i < $diferenciaDias && $i < 30; $i++) {
                    $freeDayTemp['fecha'] = $baseDate->sub(new \DateInterval('P' . $diferenciaDias - $i  . 'D'));
                    $freeDayTemp['nroDia'] = $itinerarioDia['nroDia'] - $diferenciaDias + $i;
                    $freeDayTemp['fechaitems'][0]['tituloDia'] = $this->translator->trans('dia_libre_titulo', [], 'messages');
                    $freeDayTemp['fechaitems'][0]['descripcion'] = '<p>' . $this->translator->trans('dia_libre_contenido', [], 'messages') . '</p>';
                    $itinerarioConLibres[] = $freeDayTemp;
                }
                $diaEsperado = $itinerarioDia['nroDia'] + 1;
                $itinerarioConLibres[] = $itinerarioDia;
            }
        }

        return $itinerarioConLibres;
    }

    public function getMainPhoto(CotizacionCotservicio $cotservicio): string
    {

        if ($cotservicio->getItinerario()->getItinerariodias()->count() > 0) {

            foreach ($cotservicio->getItinerario()->getItinerariodias() as $dia):

                if($dia->isImportante() === true || $cotservicio->getItinerario()->getItinerariodias()->count() === 1){
                    if($dia->getItidiaarchivos()->count() > 0){
                        foreach ($dia->getItidiaarchivos() as $archivo):

                        endforeach;
                    }

                }

            endforeach;
        }


        return $tituloItinerario;
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
        //primero es el importante por dia si no hubiera importantes se coge el título
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