<?php

namespace App\Service;

use App\Entity\ServicioTipocomponente;
use App\Entity\ServicioTipotarifa;
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

                if($servicio->getCotcomponentes()->count() > 0){

                    foreach($servicio->getCotcomponentes() as $componente):

                        if($componente->getCottarifas()->count() > 0){

                            foreach($componente->getCottarifas() as $tarifa):

                                if($tarifa->getTipotarifa()->getId() != ServicioTipotarifa::DB_VALOR_NORMAL){
                                    continue;
                                }
//Para los servicios que no tienen dias de itinerario los clasifico como varios y le pongo un id -1
                                if(
                                    $tarifa->getTarifa()->getComponente()->getTipocomponente()->getId() == ServicioTipocomponente::DB_VALOR_ALOJAMIENTO
                                ){
                                    $servicioId = -4; //se ordenara por este valor
                                    $datos['resumen'][$servicioId]['caso'] = 'hotel';
                                    $datos['resumen'][$servicioId]['tituloItinerario'] = ucfirst($this->translator->trans('alojamiento', [], 'messages'));

                                } elseif(!empty($this->cotizacionItinerario->getTituloItinerario($componente->getFechahorainicio(), $servicio))){
                                    $servicioId = $servicio->getId();
                                    $datos['resumen'][$servicioId]['caso'] = 'normal';
                                    $datos['resumen'][$servicioId]['tituloItinerario'] = $this->cotizacionItinerario->getTituloItinerario($componente->getFechahorainicio(), $servicio);
                                }else{
                                    $servicioId = -1;
                                    $datos['resumen'][$servicioId]['caso'] = 'varios';
                                    $datos['resumen'][$servicioId]['tituloItinerario'] = ucfirst($this->translator->trans('varios', [], 'messages'));
                                }


                                $datos['resumen'][$servicioId]['fechaHoraInicio'] = $servicio->getFechaHoraInicio();
                                $datos['resumen'][$servicioId]['fechaHoraFin'] = $servicio->getFechaHoraFin();


                                $datos['resumen'][$servicioId]['tituloTipotarifa'] = $tarifa->getTipotarifa()->getTitulo();

                                if($componente->getComponente()->getComponenteitems()->count() > 0){
//Pongo el título del itinerario que ya definí para los internos

                                    foreach($componente->getComponente()->getComponenteitems() as $item){

                                        $datos['resumen'][$servicioId]['componentes'][$componente->getId() . '-' . $item->getId()]['cantidadComponente'] = $componente->getCantidad();
                                        $datos['resumen'][$servicioId]['componentes'][$componente->getId() . '-' . $item->getId()]['titulo'] = $item->getTitulo();
                                        $datos['resumen'][$servicioId]['componentes'][$componente->getId() . '-' . $item->getId()]['listaclase'] = $tarifa->getTipotarifa()->getListaclase();
                                        $datos['resumen'][$servicioId]['componentes'][$componente->getId() . '-' . $item->getId()]['listacolor'] = !empty($tarifa->getTipotarifa()->getListacolor()) ? $tarifa->getTipotarifa()->getListacolor() : 'inherit';

                                        if(!empty($componente->getFechahorainicio())){
                                            $datos['resumen'][$servicioId]['componentes'][$componente->getId() . '-' . $item->getId()]['fecha'] = $componente->getFechahorainicio()->format('Y-m-d');
                                        }
                                    }

                                }

                            endforeach;
                        }

                    endforeach;
                }
            endforeach;
//Ordenamos el varios al final
            if(isset($datos['resumen'][-1])){
                $datos['resumen'][] = $datos['resumen'][-1];
                unset($datos['resumen'][-1]);
            }

//ponemos los hoteles al inicio
            if(isset($datos['resumen'][-4])){
                $hoteles = $datos['resumen'][-4];
                unset($datos['resumen'][-4]);
                array_unshift($datos['resumen'], $hoteles);
            }

        }

        return $datos;
    }
}