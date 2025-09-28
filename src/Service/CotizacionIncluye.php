<?php

namespace App\Service;

use App\Entity\ServicioTipocomponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\CotizacionCotizacion;

class CotizacionIncluye
{

    private TranslatorInterface $translator;

    private CotizacionCotizacion $cotizacion;

    private CotizacionItinerario $cotizacionItinerario;

    private RequestStack $requestStack;


    function __construct(TranslatorInterface $translator, CotizacionItinerario $cotizacionItinerario, RequestStack $requestStack)
    {
        $this->translator = $translator;
        $this->cotizacionItinerario = $cotizacionItinerario;
        $this->requestStack = $requestStack;
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

//$tempArrayTarifasIncluye solo sirve para la muestra de "incluye" al cliente no es para interno

                            foreach($componente->getCottarifas() as $tarifa):

                                $tempArrayTarifasIncluyeInterno = [];

                                if ($tarifa->getTarifa()->getComponente()->getId() != $componente->getComponente()->getId()){
                                    $this->requestStack->getSession()->getFlashBag()->add(
                                        'warning',
                                        sprintf('Tarifas que no corresponden al componente revise la tarifa %s que corresponde al componente %s pero se encuentra bajo %s.', $tarifa->getTarifa()->getNombre(), $tarifa->getTarifa()->getComponente()->getNombre(), $componente->getComponente()->getNombre())
                                    );
                                }
//Para los servicios que no tienen dias de itinerario los clasifico como varios y le pongo un id -1
                                if(
                                    $tarifa->getTarifa()->getComponente()->getTipocomponente()->getId() == ServicioTipocomponente::DB_VALOR_ALOJAMIENTO
                                ){
                                    $servicioId = -4; //se ordenara por este valor
                                    $datos['internoIncluidos'][$servicioId]['caso'] = 'hotel';
                                    $datos['internoIncluidos'][$servicioId]['tituloItinerario'] = ucfirst($this->translator->trans('alojamiento', [], 'messages'));

                                } elseif(!empty($this->cotizacionItinerario->getTituloItinerario($componente->getFechahorainicio(), $servicio))){
                                    $servicioId = $servicio->getId();
                                    $datos['internoIncluidos'][$servicioId]['caso'] = 'normal';
                                    $datos['internoIncluidos'][$servicioId]['tituloItinerario'] = $this->cotizacionItinerario->getTituloItinerario($componente->getFechahorainicio(), $servicio);
                                }else{
                                    $servicioId = -1;
                                    $datos['internoIncluidos'][$servicioId]['caso'] = 'varios';
                                    $datos['internoIncluidos'][$servicioId]['tituloItinerario'] = ucfirst($this->translator->trans('varios', [], 'messages'));
                                }

                                $datos['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['tituloTipotarifa'] = $tarifa->getTipotarifa()->getTitulo();
                                $datos['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['colorTipotarifa'] = $tarifa->getTipotarifa()->getListacolor();
                                $datos['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['claseTipotarifa'] = $tarifa->getTipotarifa()->getListaclase();
//Agrupo las tarifas incluidas para manejo interno
                                $tempArrayTarifasIncluyeInterno['nombre'] = $tarifa->getTarifa()->getNombre();
                                $tempArrayTarifasIncluyeInterno['cantidad'] = (int)($tarifa->getCantidad());
                                $tempArrayTarifasIncluyeInterno['monto'] = $tarifa->getMonto();
                                $tempArrayTarifasIncluyeInterno['moneda'] = $tarifa->getMoneda();
                                if(!empty($tarifa->getTarifa()->getValidezInicio())){
                                    $tempArrayTarifasIncluyeInterno['validezInicio'] = $tarifa->getTarifa()->getValidezInicio();
                                }

                                if(!empty($tarifa->getTarifa()->getValidezFin())){
                                    $tempArrayTarifasIncluyeInterno['validezFin'] = $tarifa->getTarifa()->getValidezFin();
                                }

                                if(!empty($tarifa->getTarifa()->getCapacidadmin())){
                                    $tempArrayTarifasIncluyeInterno['capacidadMin'] = $tarifa->getTarifa()->getCapacidadmin();
                                }

                                if(!empty($tarifa->getTarifa()->getCapacidadmax())){
                                    $tempArrayTarifasIncluyeInterno['capacidadMax'] = $tarifa->getTarifa()->getCapacidadmax();
                                }

                                if(!empty($tarifa->getTarifa()->getEdadmin())){
                                    $tempArrayTarifasIncluyeInterno['edadMin'] = $tarifa->getTarifa()->getEdadmin();
                                }

                                if(!empty($tarifa->getTarifa()->getEdadmax())){
                                    $tempArrayTarifasIncluyeInterno['edadMax'] = $tarifa->getTarifa()->getEdadmax();
                                }

                                if(!empty($tarifa->getTarifa()->getTipopax())){
                                    $tempArrayTarifasIncluyeInterno['tipoPaxId'] = $tarifa->getTarifa()->getTipopax()->getId();
                                    $tempArrayTarifasIncluyeInterno['tipoPaxNombre'] = $tarifa->getTarifa()->getTipopax()->getNombre();
                                    $tempArrayTarifasIncluyeInterno['tipoPaxTitulo'] = $tarifa->getTarifa()->getTipopax()->getTitulo();
                                }

                                $tempArrayDetalle = [];

                                foreach($tarifa->getCottarifadetalles() as $index => $detalle):
                                    $tempArrayDetalle[$index]['contenido'] = $detalle->getDetalle();
                                    $tempArrayDetalle[$index]['tipoId'] = $detalle->getTipotarifadetalle()->getId();
                                    $tempArrayDetalle[$index]['tipoNombre'] = $detalle->getTipotarifadetalle()->getNombre();
                                    $tempArrayDetalle[$index]['tipoTitulo'] = empty($detalle->getTipotarifadetalle()->getTitulo()) ? $tempArrayDetalle[$index]['tipoNombre'] : $detalle->getTipotarifadetalle()->getTitulo();

                                endforeach;

                                if(!empty($tempArrayDetalle)){
                                    $tempArrayTarifasIncluyeInterno['detalles'] = $tempArrayDetalle;
                                }

                                $datos['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['cantidadComponente'] = $componente->getCantidad();
                                $datos['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['nombre'] = $componente->getComponente()->getNombre();
                                $datos['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['listaclase'] = $tarifa->getTipotarifa()->getListaclase();
                                $datos['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['listacolor'] = !empty($tarifa->getTipotarifa()->getListacolor()) ? $tarifa->getTipotarifa()->getListacolor() : 'inherit';

                                if(!empty($componente->getFechahorainicio())){
                                    $datos['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['fecha'] = $componente->getFechahorainicio()->format('Y-m-d');
                                }

                                $datos['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['tarifas'][] = $tempArrayTarifasIncluyeInterno;

                                unset($tempArrayTarifasIncluyeInterno);

                                ksort($datos['internoIncluidos'][$servicioId]['tipotarifas']);
//Agrupo las tarifas incluidas para mostrar al cliente

                                $tempArrayTarifasIncluye = [];

                                if($componente->getComponente()->getComponenteitems()->count() > 0){
//Pongo el titulo del itinerario que ya defini para los internos

                                    $datos['incluidos'][$servicioId]['tituloItinerario'] = $datos['internoIncluidos'][$servicioId]['tituloItinerario'];
                                    $datos['incluidos'][$servicioId]['caso'] = $datos['internoIncluidos'][$servicioId]['caso'];
                                    $datos['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['tituloTipotarifa'] = $tarifa->getTipotarifa()->getTitulo();
                                    $datos['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['colorTipotarifa'] = $tarifa->getTipotarifa()->getListacolor();
                                    $datos['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['claseTipotarifa'] = $tarifa->getTipotarifa()->getListaclase();

                                    foreach($componente->getComponente()->getComponenteitems() as $item){
                                        //alguno de los 3 o titulo o modalidad o categoria
                                        if((!empty($tarifa->getTarifa()->getTitulo()) && !$item->isNomostrartarifa())
                                            ||(!empty($tarifa->getTarifa()->getModalidadtarifa()) && !$item->isNomostrarmodalidadtarifa())
                                            ||(!empty($tarifa->getTarifa()->getCategoriatour()) && !$item->isNomostrarcategoriatour())
                                        ){
                                            if(!empty($tarifa->getTarifa()->getTitulo()) && !$item->isNomostrartarifa()){
                                                $tempArrayTarifasIncluye['titulo'] = $tarifa->getTarifa()->getTitulo();
                                            }

                                            if(!empty($tarifa->getTarifa()->getModalidadtarifa()) && !$item->isNomostrarmodalidadtarifa()){
                                                $tempArrayTarifasIncluye['modalidad'] = $tarifa->getTarifa()->getModalidadtarifa()->getTitulo();
                                            }

                                            if(!empty($tarifa->getTarifa()->getCategoriatour()) && !$item->isNomostrarcategoriatour()){
                                                $tempArrayTarifasIncluye['categoria'] = $tarifa->getTarifa()->getCategoriatour()->getTitulo();
                                            }

                                            $tempArrayTarifasIncluye['cantidad'] = (int)($tarifa->getCantidad());
                                            if(!empty($tarifa->getTarifa()->getValidezInicio())){
                                                $tempArrayTarifasIncluye['validezInicio'] = $tarifa->getTarifa()->getValidezInicio();
                                            }

                                            if(!empty($tarifa->getTarifa()->getValidezFin())){
                                                $tempArrayTarifasIncluye['validezFin'] = $tarifa->getTarifa()->getValidezFin();
                                            }

                                            $tempArrayTarifasIncluye['mostrarcostoincluye'] = false;
                                            if($tarifa->getTipotarifa()->isMostrarcostoincluye() ===true && $tarifa->getMonto() != '0.00'){
                                                $tempArrayTarifasIncluye['mostrarcostoincluye'] = true;
                                                $tempArrayTarifasIncluye['simboloMoneda'] = $tarifa->getMoneda()->getSimbolo();
                                                $tempArrayTarifasIncluye['costo'] = $tarifa->getMonto();
                                            }

                                            if(!empty($tarifa->getTarifa()->getCapacidadmin())){
                                                $tempArrayTarifasIncluye['capacidadMin'] = $tarifa->getTarifa()->getCapacidadmin();
                                            }

                                            if(!empty($tarifa->getTarifa()->getCapacidadmax())){
                                                $tempArrayTarifasIncluye['capacidadMax'] = $tarifa->getTarifa()->getCapacidadmax();
                                            }

                                            if(!empty($tarifa->getTarifa()->getEdadmin())){
                                                $tempArrayTarifasIncluye['edadMin'] = $tarifa->getTarifa()->getEdadmin();
                                            }

                                            if(!empty($tarifa->getTarifa()->getEdadmax())){
                                                $tempArrayTarifasIncluye['edadMax'] = $tarifa->getTarifa()->getEdadmax();
                                            }

                                            if(!empty($tarifa->getTarifa()->getTipopax())){
                                                $tempArrayTarifasIncluye['tipoPaxId'] = $tarifa->getTarifa()->getTipopax()->getId();
                                                $tempArrayTarifasIncluye['tipoPaxNombre'] = $tarifa->getTarifa()->getTipopax()->getNombre();
                                                $tempArrayTarifasIncluye['tipoPaxTitulo'] = $tarifa->getTarifa()->getTipopax()->getTitulo();
                                            }
                                            $tempArrayDetalle = [];

                                            foreach($tarifa->getCottarifadetalles() as $index => $detalle):
                                                if(!$detalle->getTipotarifadetalle()->isInterno()){
                                                    $tempArrayDetalle[$index]['contenido'] = $detalle->getDetalle();
                                                    $tempArrayDetalle[$index]['tipoId'] = $detalle->getTipotarifadetalle()->getId();
                                                    $tempArrayDetalle[$index]['tipoNombre'] = $detalle->getTipotarifadetalle()->getNombre();
                                                    $tempArrayDetalle[$index]['tipoTitulo'] = empty($detalle->getTipotarifadetalle()->getTitulo()) ? $tempArrayDetalle[$index]['tipoNombre'] : $detalle->getTipotarifadetalle()->getTitulo();
                                                }
                                            endforeach;

                                            if(!empty($tempArrayDetalle)){
                                                $tempArrayTarifasIncluye['detalles'] = $tempArrayDetalle;
                                            }
                                        }

                                        $datos['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['cantidadComponente'] = $componente->getCantidad();
                                        $datos['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['titulo'] = $item->getTitulo();
                                        $datos['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['listaclase'] = $tarifa->getTipotarifa()->getListaclase();
                                        $datos['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['listacolor'] = !empty($tarifa->getTipotarifa()->getListacolor()) ? $tarifa->getTipotarifa()->getListacolor() : 'inherit';

                                        if(!empty($componente->getFechahorainicio())){
                                            $datos['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['fecha'] = $componente->getFechahorainicio()->format('Y-m-d');
                                        }

                                        if(!empty($tempArrayTarifasIncluye)){
                                            $datos['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['tarifas'][] = $tempArrayTarifasIncluye;
                                            unset($tempArrayTarifasIncluye);
                                        }
                                    }

                                    ksort($datos['incluidos'][$servicioId]['tipotarifas']);
                                }

                            endforeach;
                        }

                    endforeach;
                }
            endforeach;
//Ordenamos el varios al final
            if(isset($datos['incluidos'][-1])){
                $datos['incluidos'][] = $datos['incluidos'][-1];
                unset($datos['incluidos'][-1]);
            }
            if(isset($datos['internoIncluidos'][-1])){
                $datos['internoIncluidos'][] = $datos['internoIncluidos'][-1];
                unset($datos['internoIncluidos'][-1]);
            }
//ponemos los hoteles al inicio
            if(isset($datos['incluidos'][-4])){
                $hoteles = $datos['incluidos'][-4];
                unset($datos['incluidos'][-4]);
                array_unshift($datos['incluidos'], $hoteles);
            }
            if(isset($datos['internoIncluidos'][-4])){
                $hoteles = $datos['internoIncluidos'][-4];
                unset($datos['internoIncluidos'][-4]);
                array_unshift($datos['internoIncluidos'], $hoteles);
            }

        }

        return $datos;
    }
}