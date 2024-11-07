<?php

namespace App\Service;

use App\Entity\ServicioTipocomponente;
use App\Entity\ServicioTipotarifa;
use App\Entity\ServicioTipotarifadetalle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\CotizacionCotizacion;

class CotizacionResumen
{

    private EntityManagerInterface $entityManager;

    private TranslatorInterface $translator;

    private CotizacionCotizacion $cotizacion;

    private CotizacionItinerario $cotizacionItinerario;

    private RequestStack $requestStack;


    function __construct(EntityManagerInterface $entityManager, TranslatorInterface $translator, CotizacionItinerario $cotizacionItinerario, RequestStack $requestStack)
    {
        $this->entityManager = $entityManager;
        $this->translator = $translator;
        $this->cotizacionItinerario = $cotizacionItinerario;
        $this->requestStack = $requestStack;
    }

    function getDatosFromId(int $id): array
    {

        $cotizacionEncontrada = $this->entityManager
            ->getRepository('App\Entity\CotizacionCotizacion')
            ->find($id);

        if(!$cotizacionEncontrada){
            $this->requestStack->getSession()->getFlashBag()->add('error', sprintf('No se puede encontrar el objeto con el identificador : %s', $id));
            return [];
        }

        return $this->getDatos($cotizacionEncontrada);
    }

    function getDatos(CotizacionCotizacion $cotizacion): array
    {
        $datos = [];

        $this->cotizacion = $cotizacion;

        if($cotizacion->getCotservicios()->count() > 0){

            foreach($cotizacion->getCotservicios() as $servicio):

                $fotos = $this->cotizacionItinerario->getFotos($servicio);

                if($servicio->getCotcomponentes()->count() > 0){

                    foreach($servicio->getCotcomponentes() as $componente):

                        if($componente->getCottarifas()->count() > 0){

                            foreach($componente->getCottarifas() as $tarifa):

                                if($tarifa->getTipotarifa()->isOcultoenresumen()){
                                    //muestro todos
                                    //todo otro indicador
                                    //continue;
                                }

                                if(
                                    //hoteles: se toma en cuenta la fecha del componente
                                    $tarifa->getTarifa()->getComponente()->getTipocomponente()->getId() == ServicioTipocomponente::DB_VALOR_ALOJAMIENTO
                                    && $componente->getComponente()->getComponenteitems()->count() > 0
                                ){
                                    $tarifaId = $tarifa->getId();

                                    $tempArrayHotelItems = [];
                                    foreach($componente->getComponente()->getComponenteitems() as $item){
                                        $tempArrayHotelItems[] = $item->getTitulo();
                                    }
                                    $datos['alojamientos'][$tarifaId]['titulo'] = implode(', ', $tempArrayHotelItems);
                                    if(!empty($tarifa->getTarifa()->getTitulo())){
                                        $datos['alojamientos'][$tarifaId]['tarifaTitulo'] = $tarifa->getTarifa()->getTitulo();
                                    }

                                    if(!empty($tarifa->getProvider())){
                                        $datos['alojamientos'][$tarifaId]['proveedor'] = $tarifa->getProvider();
                                    }

                                    $datos['alojamientos'][$tarifaId]['fechahoraInicio'] = $componente->getFechahoraInicio();
                                    $datos['alojamientos'][$tarifaId]['fechahoraFin'] = $componente->getFechahoraFin();

                                    $datos['alojamientos'][$tarifaId]['fechaInicio'] = $componente->getFechaInicio();
                                    $datos['alojamientos'][$tarifaId]['fechaFin'] = $componente->getFechaFin();

                                    $datos['alojamientos'][$tarifaId]['tipoTarifa'] = $tarifa->getTipotarifa();
                                    if($tarifa->getCottarifadetalles()->count() > 0){
                                        foreach ($tarifa->getCottarifadetalles() as $detalle):
                                            if($detalle->getTipotarifaDetalle()->getId() == ServicioTipotarifadetalle::DB_VALOR_DETALLES){
                                                $datos['alojamientos'][$tarifaId]['detalles'][] = $detalle->getDetalle();
                                            }
                                        endforeach;
                                    }

                                    $duracionDiff = (int)date_diff($datos['alojamientos'][$tarifaId]['fechaInicio'], $datos['alojamientos'][$tarifaId]['fechaFin'])->format('%d');
                                    if($duracionDiff == 1){
                                        $diferenciaUnidadStr = $this->translator->trans('noche', [], 'messages');
                                    }else{
                                        $diferenciaUnidadStr = $this->translator->trans('noches', [], 'messages');
                                    }
                                    $datos['alojamientos'][$tarifaId]['duracionStr'] = $duracionDiff . ' ' . $diferenciaUnidadStr;

                                } elseif(
                                    //servicios con foto: se toma en cuenta la fecha del servicio
                                    !empty($this->cotizacionItinerario->getTituloItinerario($componente->getFechahorainicio(), $servicio))
                                    && $componente->getComponente()->getComponenteitems()->count() > 0
                                ){
                                    $servicioId = $servicio->getId();

                                    $datos['serviciosConTituloItinerario'][$servicioId]['tipoTarifas'][$tarifa->getTipotarifa()->getId()]['tituloTipotarifa'] = $tarifa->getTipotarifa()->getTitulo();

                                    foreach($componente->getComponente()->getComponenteitems() as $item){

                                        //agrupamos por tipo de tarifa
                                        $datos['serviciosConTituloItinerario'][$servicioId]['tipoTarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['titulo'] = $item->getTitulo();

                                        //para la agenda de resumen, sin  agrupacion, se sobrescribiran por el indice
                                        if(!isset($datos['serviciosConTituloItinerario'][$servicioId]['fechahorasdiferentes'])){
                                            $datos['serviciosConTituloItinerario'][$servicioId]['fechahorasdiferentes'] = false;
                                        }

                                        if($tarifa->getTarifa()->getComponente()->getTipocomponente()->isAgendable()){
                                            if($componente->getFechahoraInicio()->format('Y/m/d H:i') !=  $servicio->getFechahoraInicio()->format('Y/m/d H:i')){
                                                $datos['serviciosConTituloItinerario'][$servicioId]['fechahorasdiferentes'] = true;
                                            }
                                            $datos['serviciosConTituloItinerario'][$servicioId]['items'][$componente->getId() . '-' . $item->getId()]['titulo'] = $item->getTitulo();
                                            $datos['serviciosConTituloItinerario'][$servicioId]['items'][$componente->getId() . '-' . $item->getId()]['fechahoraInicio'] = $componente->getFechahoraInicio();
                                        }
                                    }

                                    //primero incluidos
                                    ksort($datos['serviciosConTituloItinerario'][$servicioId]['tipoTarifas']);
                                    
                                    $datos['serviciosConTituloItinerario'][$servicioId]['tituloItinerario'] = $this->cotizacionItinerario->getTituloItinerario($componente->getFechahorainicio(), $servicio);
                                    $datos['serviciosConTituloItinerario'][$servicioId]['fotos'] = $fotos;

                                    $datos['serviciosConTituloItinerario'][$servicioId]['fechahoraInicio'] = $servicio->getFechahoraInicio();
                                    $datos['serviciosConTituloItinerario'][$servicioId]['fechahoraFin'] = $servicio->getFechahoraFin();

                                    $datos['serviciosConTituloItinerario'][$servicioId]['fechaInicio'] = $servicio->getFechaInicio();
                                    $datos['serviciosConTituloItinerario'][$servicioId]['fechaFin'] = $servicio->getFechaFin();

                                    $duracionDiff = (int)date_diff($datos['serviciosConTituloItinerario'][$servicioId]['fechahoraInicio'], $datos['serviciosConTituloItinerario'][$servicioId]['fechahoraFin'])->format('%h');
                                    if($duracionDiff >= 24){
                                        //reemplazamos duración diff
                                        $duracionDiff = (int)date_diff($datos['serviciosConTituloItinerario'][$servicioId]['fechaInicio'], $datos['serviciosConTituloItinerario'][$servicioId]['fechaFin'])->format('%d');

                                        if($duracionDiff > 1){
                                            $diferenciaUnidadStr = $this->translator->trans('dias', [], 'messages');
                                        }else{
                                            $diferenciaUnidadStr = $this->translator->trans('dia', [], 'messages');
                                        }
                                        $datos['serviciosConTituloItinerario'][$servicioId]['duracionStr'] = $duracionDiff . ' ' . $diferenciaUnidadStr;

                                    }else{
                                        if($duracionDiff === 1){
                                            $diferenciaUnidadStr = $this->translator->trans('hora', [], 'messages');
                                        }else{
                                            $diferenciaUnidadStr = $this->translator->trans('horas', [], 'messages');
                                        }
                                        $datos['serviciosConTituloItinerario'][$servicioId]['duracionStr'] = $duracionDiff . ' ' . $diferenciaUnidadStr;
                                    }
                                } else{
                                    $datos['serviciosSinTituloItinerario']['tipoTarifas'][$tarifa->getTipotarifa()->getId()]['tituloTipotarifa'] = $tarifa->getTipotarifa()->getTitulo();
                                    foreach($componente->getComponente()->getComponenteitems() as $item){
                                        $datos['serviciosSinTituloItinerario']['tipoTarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['titulo'] = $item->getTitulo();
                                    }
                                }
                            endforeach;
                        }
                    endforeach;
                }
            endforeach;
        }
        return $datos;
    }
}