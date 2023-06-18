<?php

namespace App\Service;

use App\Entity\ServicioTipocomponente;
use Doctrine\ORM\EntityManagerInterface;

class MensajeProveedor{

    private EntityManagerInterface $em;

    function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getMensajesParaComponente($id): array
    {
        $cotcomponete = $this->em
            ->getRepository('App\Entity\CotizacionCotcomponente')
            ->find($id);

        if(empty($cotcomponete)){
            return [];
        }
        $cotizacion = $cotcomponete->getCotservicio()->getCotizacion()->getId();

        return $this->getMensajesParaCotizacion($cotizacion, $id);
    }

    public function getMensajesParaCotizacion($id, $filterComponente = null): array
    {
        $cotizacion = $this->em
            ->getRepository('App\Entity\CotizacionCotizacion')
            ->find($id);

        $tempProveedores = [];
        $tempHoteles = [];

        if(empty($cotizacion)){
            return [];
        }

        if($cotizacion->getCotservicios()->count() > 0){
            foreach($cotizacion->getCotservicios() as $servicio):

                if($servicio->getCotcomponentes()->count() > 0){
                    foreach($servicio->getCotcomponentes() as $componente):

                        if($componente->getCottarifas()->count() > 0){
                            foreach($componente->getCottarifas() as $tarifa):

                                //procesamos servicios
                                if(!$tarifa->getTarifa()->isProvidernomostrable()
                                    && (!empty($filterComponente) && $filterComponente == $componente->getId()) || empty($filterComponente)
                                ){
                                    $indiceComponentesProveedor = $componente->getFechahorainicio()->format('Ymd')
                                        . sprintf('%04d',$tarifa->getTarifa()->getComponente()->getTipocomponente()->getPrioridadparaproveedor())
                                        . $componente->getFechahorainicio()->format('Hi')
                                        . sprintf('%010d', $componente->getId()); // agrupamos por componentes, ya no separamos por tarifa con: sprintf('%010d', $tarifa->getId());

                                    if(!empty($tarifa->getProvider())) {
                                        $providerId = $tarifa->getProvider()->getId();
                                        $providerName = $tarifa->getProvider()->getNombre();
                                        $providerNameMostrar = $tarifa->getProvider()->getNombremostrar();
                                        //se sobreescriben para cada tarifa
                                        if(!empty($tarifa->getProvider()->getTelefono())){
                                            $tempProveedores[$providerId]['telefono'] = $tarifa->getProvider()->getTelefono();
                                        }
                                        if(!empty($tarifa->getProvider()->getEmail())){
                                            $tempProveedores[$providerId]['email'] = $tarifa->getProvider()->getEmail();
                                        }

                                    }else{
                                        $providerId = -1;
                                        $providerName = 'No definido';
                                        $providerNameMostrar = 'No definido';
                                    }
                                    $tempProveedores[$providerId]['nombre'] = $providerName;
                                    $tempProveedores[$providerId]['nombreMostrar'] = $providerNameMostrar;

                                    //comunes que se sobreescriben si es que pertenecen al mismo componente, ya no queremos filas distintas ahora usan el mismo $indiceComponentesProveedor

                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['fechaHoraInicio'] = $componente->getFechahorainicio();
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['fechaHoraFin'] = $componente->getFechahorafin();
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tipoComponenteId'] = $tarifa->getTarifa()->getComponente()->getTipocomponente()->getId();
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tipoComponenteNombre'] = $tarifa->getTarifa()->getComponente()->getTipocomponente()->getNombre();
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tipoComponentePrioridad'] = $tarifa->getTarifa()->getComponente()->getTipocomponente()->getPrioridadparaproveedor();
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['componenteNombre'] = $componente->getComponente()->getNombre();
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['componenteEstadoNombre'] = $componente->getEstadoCotcomponente()->getNombre();
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['componenteEstadoColor'] = $componente->getEstadoCotcomponente()->getColor();
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['componenteCantidad'] = (int)($componente->getCantidad());

                                    if(!empty($tarifa->getTarifa()->getNombremostrar())){
                                        $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tarifas'][$tarifa->getId()]['tarifaNombre'] = $tarifa->getTarifa()->getNombremostrar();
                                    }else{
                                        $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tarifas'][$tarifa->getId()]['tarifaNombre'] = $tarifa->getTarifa()->getNombre();
                                    }
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tarifas'][$tarifa->getId()]['monto'] = $tarifa->getMonto();
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tarifas'][$tarifa->getId()]['moneda'] = $tarifa->getMoneda();


                                    if($tarifa->getTarifa()->isProrrateado() === false){
                                        $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tarifas'][$tarifa->getId()]['tarifaCantidad'] = (int)($tarifa->getCantidad());
                                    }
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tarifas'][$tarifa->getId()]['tipoTarifaId'] = $tarifa->getTipotarifa()->getId();
                                    $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tarifas'][$tarifa->getId()]['tipoTarifaNombre'] = $tarifa->getTipotarifa()->getNombre();

                                    $tempInfoOperativa = [];

                                    foreach($tarifa->getCottarifadetalles() as $detalle):
                                        if($detalle->getTipotarifadetalle()->getId() == 6) // $tempProveedores informacion operativa tipo 6 para proveedores
                                        {
                                            $tempInfoOperativa[] = $detalle->getDetalle();
                                        }

                                    endforeach;

                                    if (!empty($tempInfoOperativa)) {
                                        $tempProveedores[$providerId]['componentes'][$indiceComponentesProveedor]['tarifas'][$tarifa->getId()]['infoOperativa'] = implode(' ', $tempInfoOperativa);
                                    }
                                    unset($tempInfoOperativa);
                                }

                                // procesamos hoteles
                                if(
                                    $tarifa->getTarifa()->getComponente()->getTipocomponente()->getId() == ServicioTipocomponente::DB_VALOR_ALOJAMIENTO
                                ){
                                    $tempHoteles[$tarifa->getId()]['fechaHoraInicio'] = $componente->getFechahorainicio();
                                    $tempHoteles[$tarifa->getId()]['fechaHoraFin'] = $componente->getFechahorafin();
                                    $tempHoteles[$tarifa->getId()]['nombreComponente'] = $componente->getComponente()->getNombre();

                                    if(!empty($tarifa->getProvider())){
                                        $tempHoteles[$tarifa->getId()]['nombre'] = $tarifa->getProvider()->getNombre();
                                        $tempHoteles[$tarifa->getId()]['nombreMostrar'] = $tarifa->getProvider()->getNombremostrar();
                                        if(!empty($tarifa->getProvider()->getDireccion())){
                                            $tempHoteles[$tarifa->getId()]['direccion'] = $tarifa->getProvider()->getDireccion();
                                        }
                                        if(!empty($tarifa->getProvider()->getTelefono())){
                                            $tempHoteles[$tarifa->getId()]['telefono'] = $tarifa->getProvider()->getTelefono();
                                        }
                                        if(!empty($tarifa->getProvider()->getEmail())){
                                            $tempHoteles[$tarifa->getId()]['email'] = $tarifa->getProvider()->getEmail();
                                        }
                                    }else{
                                        $tempHoteles[$tarifa->getId()]['nombre'] = 'No Ingresado';
                                        $tempHoteles[$tarifa->getId()]['nombreMostrar'] = 'No Ingresado';
                                    }
                                }
                            endforeach;
                        }
                    endforeach;
                }
            endforeach;
        }

        return $this->procesarMensajes($tempProveedores, $tempHoteles);
    }

    private function procesarMensajes(array $tempProveedores, array $tempHoteles): array
    {
        //procesamos la informacion para enviar a proveedor
        foreach ($tempProveedores as &$proveedor):
            //ordenamos por el indice
            ksort($proveedor['componentes']);
            foreach($proveedor['componentes'] as $componente):
                if($componente['tipoComponenteId'] == ServicioTipocomponente::DB_VALOR_ALOJAMIENTO){
                    //no modificamos si es hotel
                    continue;
                }
                $inicio = new \DateTime($componente['fechaHoraInicio']->format('Y-m-d'));
                $fin = new \DateTime($componente['fechaHoraFin']->format('Y-m-d'));
                foreach ($tempHoteles as $idHotel => $hotel):
                    $inicioHotel = new \Datetime($hotel['fechaHoraInicio']->format('Y-m-d'));
                    $finHotel = new \Datetime($hotel['fechaHoraFin']->format('Y-m-d'));
                    if($inicio >= $inicioHotel && $fin <= $finHotel){
                        //para que no se repita usamos el idhotel
                        $proveedor['hoteles'][$idHotel] = $hotel;
                    }
                endforeach;
                unset($inicioHotel);
                unset($finHotel);
            endforeach;
            unset($inicio);
            unset($fin);

        endforeach;
        //como esta por referencia es mejor destruir
        unset($proveedor);

        //ordenamos
        if(isset($tempProveedores[-1])){
            $tempProveedores[] = $tempProveedores[-1];
            unset($tempProveedores[-1]);
        }
        return $tempProveedores;
    }

}