<?php

namespace App\Service;

use \Symfony\Component\Filesystem\Filesystem;
use \Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use \Symfony\Component\DependencyInjection\ContainerAwareInterface;
use \Symfony\Component\DependencyInjection\ContainerAwareTrait;
use \App\Service\MainTipocambio;
use \Doctrine\ORM\EntityManagerInterface;

class CotizacionResumen implements ContainerAwareInterface
{

    use ContainerAwareTrait;

    private $tl = 'es';
    private $doctrine;

    private $edadMin = 0;
    private $edadMax = 120;

    private $datosTabs;
    private $datosCotizacion;

    private $clasificacionTarifas = [];
    private $resumendeClasificado = [];

    private $mensaje;

    private $tipocambio;

    function getDoctrine(){
        return $this->doctrine;
    }

    function setTl($tl){
        $this->tl = $tl;
        return $this;
    }

    function __construct(EntityManagerInterface $em, MainTipocambio $tipocambio)
    {
        $this->doctrine = $em;
        $this->tipocambio = $tipocambio;
    }

    function getTituloItinerario(\DateTime $fecha, $itinerarioFechaAux) : string {
        if(!empty($itinerarioFechaAux)){

            $diaAnterior = clone ($fecha);
            $diaAnterior->sub(new \DateInterval('P1D')) ;
            $diaPosterior = clone ($fecha);
            $diaPosterior->add(new \DateInterval('P1D')) ;

            if(isset($itinerarioFechaAux[$fecha->format('ymd')])){
                return $itinerarioFechaAux[$fecha->format('ymd')];
            }elseif((int)$fecha->format('H') > 12 && isset($itinerarioFechaAux[$diaPosterior->format('ymd')])){
                return $itinerarioFechaAux[$diaPosterior->format('ymd')];
            }elseif((int)$fecha->format('H') <= 12 && isset($itinerarioFechaAux[$diaAnterior->format('ymd')])){
                return $itinerarioFechaAux[$diaAnterior->format('ymd')];
            }else{
                return reset($itinerarioFechaAux);
            }
        }

        return null;

    }

    function procesar($id)
    {

        $cotizacion = $this->getDoctrine()
            ->getRepository('App:CotizacionCotizacion')
            ->find($id);

        if (!$cotizacion) {
            $this->mensaje = sprintf('No se puede encontrar el objeto con el identificador : %s', $id);
            return false;
        }

        $tipoCambio = $this->tipocambio->getTipodecambio($cotizacion->getCreado());

        if(!$tipoCambio){
            $this->mensaje = sprintf('No se puede obtener la el tipo de cambio del dia %s.',  $cotizacion->getCreado()->format('Y-m-d') );
            return false;
        }

        $datosCotizacion = [];

        //para mostrar primero el itinerario
        $datosTabs['itinerario']['nombre'] = 'Itinerario';
        $datosTabs['tarifas']['nombre'] = 'Tarifas';
        $datosTabs['agenda']['nombre'] = 'Agenda';
        $datosTabs['incluye']['nombre'] = 'Detalle';
        $datosTabs['politica']['nombre'] = $cotizacion->getCotpolitica()->getTitulo();
        $datosTabs['politica']['contenido'] = $cotizacion->getCotpolitica()->getContenido();


        $datosCotizacion['file']['nombre'] = $cotizacion->getFile()->getNombre();
        $datosCotizacion['file']['paisid'] = $cotizacion->getFile()->getPais()->getId();
        $datosCotizacion['file']['paisnombre'] = $cotizacion->getFile()->getPais()->getNombre();
        $datosCotizacion['file']['idioma'] = $cotizacion->getFile()->getIdioma()->getNombre();

        if($cotizacion->getFile()->getFiledocumentos()->count() > 0) {
            $archivosAux = [];
            foreach ($cotizacion->getFile()->getFiledocumentos() as $documento):

                $archivosAux['webPath'] = $documento->getWebPath();     //$this->get('request')->getSchemeAndHttpHost();
                $archivosAux['nombre'] = $documento->getNombre();
                $archivosAux['webThumbPath'] = $documento->getWebThumbPath();
                $archivosAux['webPath'] = $documento->getWebPath();
                $archivosAux['inModal'] = $documento->getInModal();
                $archivosAux['tipo'] = $documento->getTipofiledocumento()->getNombre();
                if($documento->getTipofiledocumento()->getInterno() === true){
                    $archivosAux['interno'] = true;
                }else{
                    $archivosAux['interno'] = false;
                }
                $datosCotizacion['archivos'][] = $archivosAux;
            endforeach;
        }

        if($cotizacion->getFile()->getFilepasajeros()->count() > 0) {
            $pasajerosAux = [];
            foreach ($cotizacion->getFile()->getFilepasajeros() as $pasajero):
                $pasajerosAux['nombre'] = $pasajero->getNombre();
                $pasajerosAux['apellido'] = $pasajero->getApellido();
                $pasajerosAux['pais'] = $pasajero->getPais()->getNombre();
                $pasajerosAux['sexo'] = $pasajero->getSexo()->getNombre();
                $pasajerosAux['tipodocumento'] = $pasajero->getTipodocumento()->getNombre();
                $pasajerosAux['numerodocumento'] = $pasajero->getNumerodocumento();
                $pasajerosAux['fechanacimiento'] = $pasajero->getFechanacimiento();
                $pasajerosAux['edad'] = $pasajero->getEdad();
                $datosCotizacion['pasajeros'][] = $pasajerosAux;
            endforeach;
        }

        $datosCotizacion['cotizacion']['tipocambiocompra'] = $tipoCambio->getCompra();
        $datosCotizacion['cotizacion']['tipocambioventa'] = $tipoCambio->getVenta();
        $datosCotizacion['cotizacion']['comision'] = $cotizacion->getComision();
        $datosCotizacion['cotizacion']['nombre'] = $cotizacion->getNombre();
        $datosCotizacion['cotizacion']['titulo'] = $cotizacion->getTitulo();
        $datosCotizacion['cotizacion']['numeropasajeros'] = $cotizacion->getNumeropasajeros();
        $datosCotizacion['cotizacion']['estadocotizacion'] = $cotizacion->getEstadocotizacion()->getNombre();

        if($cotizacion->getCotservicios()->count() > 0){
            if($cotizacion->getCotnotas()->count() > 0){
                $auxNotas = [];
                foreach ($cotizacion->getCotnotas() as $nota):
                    $auxNotas['nombre'] = $nota->getNombre();
                    $auxNotas['titulo'] = $nota->getTitulo();
                    $auxNotas['contenido'] = $nota->getContenido();
                    $datosTabs['itinerario']['notas'][] = $auxNotas;
                    unset($auxNotas);
                endforeach;
            }

            //Itinerarios
            foreach ($cotizacion->getCotservicios() as $servicio):
                $itinerarioFechaAux = [];
                if($servicio->getItinerario()->getItinerariodias()->count() > 0){
                    foreach ($servicio->getItinerario()->getItinerariodias() as $dia):

                        $fecha = clone($servicio->getFechahorainicio());
                        $fecha->add(new \DateInterval('P' . ($dia->getDia() - 1) . 'D'));

                        $datosTabs['itinerario']['itinerarios'][$fecha->format('ymd')]['fecha'] = $this->getFormatedDate(strtotime($fecha->format('Y-m-d')));
                        $archivosTempArray = [];
                        if($dia->getItidiaarchivos()->count() > 0){
                            foreach ($dia->getItidiaarchivos() as $archivo):
                                $archivoTemp['nombre'] = $archivo->getMedio()->getNombre();
                                $archivoTemp['titulo'] = $archivo->getMedio()->getTitulo();
                                $archivoTemp['webThumbPath'] = $archivo->getMedio()->getWebThumbPath();
                                $archivoTemp['webPath'] = $archivo->getMedio()->getWebPath();
                                $archivoTemp['inModal'] = $archivo->getMedio()->getInModal();
                                $archivosTempArray[] = $archivoTemp;
                            endforeach;
                        }
                        $datosTabs['itinerario']['itinerarios'][$fecha->format('ymd')]['fechaitems'][] = ['titulo' => $dia->getTitulo(), 'descripcion' => $dia->getContenido(), 'archivos' => $archivosTempArray];
                        unset($archivosTempArray);
                        //para uso en agenda e incluye.
                        if($dia->getImportante() === true){
                            $itinerarioFechaAux[$fecha->format('ymd')] = $dia->getTitulo();
                        }

                    endforeach;
                }

                if($servicio->getCotcomponentes()->count() > 0){
                    foreach($servicio->getCotcomponentes() as $componente):

                        if($componente->getCottarifas()->count() > 0){

                            $cantidadComponente = 0;

                            $tempArrayComponente = [];

                            if(!empty($itinerarioFechaAux)){
                                $tempArrayComponente['tituloItinerario'] = $this->getTituloItinerario($componente->getFechahorainicio(), $itinerarioFechaAux);
                            }

                            $tempArrayComponente['nombre'] = $componente->getComponente()->getNombre();
                            $tempArrayComponente['titulo'] = $componente->getComponente()->getTitulo();
                            $tempArrayComponente['fechahorainicio'] = $componente->getFechahorainicio();
                            $tempArrayComponente['fechahorafin'] = $componente->getFechahorafin();

                            foreach ($componente->getCottarifas() as $tarifa):

                                ////////Incluye//////

                                $tempArrayIncluye = [];

                                if(!empty($componente->getComponente()->getTitulo())){

                                    if(!empty($tarifa->getTarifa()->getTitulo())) {
                                        $tempArrayIncluye['titulo'] = $tarifa->getTarifa()->getTitulo();
                                        $tempArrayIncluye['cantidad'] = (int)($tarifa->getCantidad());
                                        if(!empty($tarifa->getTarifa()->getValidezInicio())){
                                            $tempArrayIncluye['validezInicio'] = $tarifa->getTarifa()->getValidezInicio();
                                        }

                                        if(!empty($tarifa->getTarifa()->getValidezFin())){
                                            $tempArrayIncluye['validezFin'] = $tarifa->getTarifa()->getValidezFin();
                                        }

                                        if(!empty($tarifa->getTarifa()->getCapacidadmin())){
                                            $tempArrayIncluye['capacidadMin'] = $tarifa->getTarifa()->getCapacidadmin();
                                        }

                                        if(!empty($tarifa->getTarifa()->getCapacidadmax())){
                                            $tempArrayIncluye['capacidadMax'] = $tarifa->getTarifa()->getCapacidadmax();
                                        }

                                        if(!empty($tarifa->getTarifa()->getEdadmin())){
                                            $tempArrayIncluye['edadMin'] = $tarifa->getTarifa()->getEdadmin();
                                        }

                                        if(!empty($tarifa->getTarifa()->getEdadmax())){
                                            $tempArrayIncluye['edadMax'] = $tarifa->getTarifa()->getEdadmax();
                                        }

                                        if(!empty($tarifa->getTarifa()->getTipopax())){
                                            $tempArrayIncluye['tipoPaxId'] = $tarifa->getTarifa()->getTipopax()->getId();
                                            $tempArrayIncluye['tipoPaxNombre'] = $tarifa->getTarifa()->getTipopax()->getNombre();
                                        }
                                    }

                                    //TODO ordenar tarifas primero incluye

                                    $datosTabs['incluye']['tipos'][$tarifa->getTipotarifa()->getId()]['titulo'] = $tarifa->getTipotarifa()->getTitulo();
                                    $datosTabs['incluye']['tipos'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['cantidadcomponente'] = $componente->getCantidad();
                                    $datosTabs['incluye']['tipos'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['titulo'] = $componente->getComponente()->getTitulo();
                                    if(!empty($componente->getFechahorainicio())){
                                        $datosTabs['incluye']['tipos'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['fecha'] = $componente->getFechahorainicio()->format('Y-m-d');
                                    }

                                    if(isset($tempArrayComponente['tituloItinerario'])){
                                        $datosTabs['incluye']['tipos'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['tituloItinerario'] = $tempArrayComponente['tituloItinerario'];
                                    }

                                    if(!empty($tempArrayIncluye)){
                                        $datosTabs['incluye']['tipos'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['tarifas'][] = $tempArrayIncluye;
                                        unset($tempArrayIncluye);
                                    }
                                }

                                /////Tarifas////////

                                $tempArrayTarifa = [];
                                $tempArrayTarifa['id'] = $tarifa->getId();
                                $tempArrayTarifa['nombreServicio'] = $servicio->getServicio()->getNombre();
                                //dentro de la tarifa tambien el titulo del itinerario y la cantidad de componente
                                if(isset($tempArrayComponente['tituloItinerario'])){
                                    $tempArrayTarifa['tituloItinerario'] = $tempArrayComponente['tituloItinerario'];
                                }
                                $tempArrayTarifa['cantidadComponente'] = $componente->getCantidad();
                                $tempArrayTarifa['nombreComponente'] = $componente->getComponente()->getNombre();
                                $tempArrayTarifa['tituloComponente'] = $componente->getComponente()->getTitulo();

                                if($tarifa->getTarifa()->getProrrateado() === true){
                                    $tempArrayTarifa['montounitario'] = number_format(
                                        (float)($tarifa->getMonto() * $tarifa->getCantidad() / $datosCotizacion['cotizacion']['numeropasajeros'] * $componente->getCantidad()
                                        ), 2, '.', '');
                                    $tempArrayTarifa['montototal'] = number_format(
                                        (float)($tarifa->getMonto() * $tarifa->getCantidad() * $componente->getCantidad()
                                        ),2, '.', '');
                                    $tempArrayTarifa['cantidad'] = (int)($datosCotizacion['cotizacion']['numeropasajeros']);
                                    $tempArrayTarifa['prorrateado'] = true;

                                }else{
                                    $tempArrayTarifa['montounitario'] = number_format(
                                        (float)($tarifa->getMonto() * $componente->getCantidad()
                                        ),2, '.', '');
                                    $tempArrayTarifa['montototal'] = number_format(
                                        (float)($tarifa->getMonto() * $componente->getCantidad() * $tarifa->getCantidad()
                                        ), 2, '.', '');
                                    $tempArrayTarifa['cantidad'] = $tarifa->getCantidad();
                                    //solo sumo prorrateados
                                    $cantidadComponente += $tempArrayTarifa['cantidad'];
                                    $tempArrayTarifa['prorrateado'] = false;
                                };

                                $tempArrayTarifa['nombre'] = $tarifa->getTarifa()->getNombre();
                                $tempArrayTarifa['titulo'] = $tarifa->getTarifa()->getTitulo();

                                $tempArrayTarifa['moneda'] = $tarifa->getMoneda()->getId();

                                //dolares = 2
                                if($tarifa->getMoneda()->getId() == 2){
                                    $tempArrayTarifa['montosoles'] = number_format((float)($tempArrayTarifa['montounitario'] * $tipoCambio->getVenta()), 2, '.', '');
                                    $tempArrayTarifa['montodolares'] = $tempArrayTarifa['montounitario'];
                                }elseif ($tarifa->getMoneda()->getId() == 1){
                                    $tempArrayTarifa['montosoles'] = $tempArrayTarifa['montounitario'];
                                    $tempArrayTarifa['montodolares'] = number_format((float)($tempArrayTarifa['montounitario'] / $tipoCambio->getCompra()), 2, '.', '');
                                }else{
                                    $this->mensaje = 'La aplicación solo puede utilizar Soles y dólares en las tarifas.';
                                    return false;
                                }

                                $factorComision = 1;
                                if($tarifa->getTipotarifa()->getComisionable() == true){
                                    $factorComision = 1 + ($cotizacion->getComision() / 100);
                                }

                                $tempArrayTarifa['ventasoles'] = number_format((float)($tempArrayTarifa['montosoles'] * $factorComision), 2, '.', '');
                                $tempArrayTarifa['ventadolares'] = number_format((float)($tempArrayTarifa['montodolares'] * $factorComision), 2, '.', '');


                                if(!empty($tarifa->getTarifa()->getValidezInicio())){
                                    $tempArrayTarifa['validezInicio'] = $tarifa->getTarifa()->getValidezInicio();
                                }

                                if(!empty($tarifa->getTarifa()->getValidezFin())){
                                    $tempArrayTarifa['validezFin'] = $tarifa->getTarifa()->getValidezFin();
                                }

                                if(!empty($tarifa->getTarifa()->getCapacidadmin())){
                                    $tempArrayTarifa['capacidadMin'] = $tarifa->getTarifa()->getCapacidadmin();
                                }

                                if(!empty($tarifa->getTarifa()->getCapacidadmax())){
                                    $tempArrayTarifa['capacidadMax'] = $tarifa->getTarifa()->getCapacidadmax();
                                }

                                if(!empty($tarifa->getTarifa()->getEdadmin())){
                                    $tempArrayTarifa['edadMin'] = $tarifa->getTarifa()->getEdadmin();
                                }

                                if(!empty($tarifa->getTarifa()->getEdadmax())){
                                    $tempArrayTarifa['edadMax'] = $tarifa->getTarifa()->getEdadmax();
                                }

                                if(!empty($tarifa->getTarifa()->getTipopax())){
                                    $tempArrayTarifa['tipoPaxId'] = $tarifa->getTarifa()->getTipopax()->getId();
                                    $tempArrayTarifa['tipoPaxNombre'] = $tarifa->getTarifa()->getTipopax()->getNombre();
                                }else{
                                    $tempArrayTarifa['tipoPaxId'] = 0;
                                    $tempArrayTarifa['tipoPaxNombre'] = 'Cualquier nacionalidad';
                                }

                                $tempArrayTarifa['tipoTarId'] = $tarifa->getTipotarifa()->getId();
                                $tempArrayTarifa['tipoTarNombre'] = $tarifa->getTipotarifa()->getNombre();
                                $tempArrayTarifa['tipoTarTitulo'] = $tarifa->getTipotarifa()->getTitulo();
                                $tempArrayTarifa['tipoTarOculto'] = $tarifa->getTipotarifa()->getOculto();

                                $tempArrayComponente['tarifas'][] = $tempArrayTarifa;
                                unset($tempArrayTarifa);

                            endforeach;
                            //dentro del componenete el titulo del itinerario

                            $this->obtenerTarifasComponente($tempArrayComponente['tarifas'], $datosCotizacion['cotizacion']['numeropasajeros']);

                            if(!empty($this->mensaje)){
                                return false;
                            }
                            $datosTabs['agenda']['componentes'][] = $tempArrayComponente;
                            unset($tempArrayComponente);

                            //no he sumado prorrateados puede ir en blanco para el caso de que solo exista prorrateado
                            if($cantidadComponente > 0 && $cantidadComponente != $cotizacion->getNumeropasajeros()){
                                $this->mensaje = sprintf('La cantidad de pasajeros por componente no coincide con la cantidad de pasajeros en %s %s %s.', $servicio->getFechahorainicio()->format('Y/m/d'), $servicio->getServicio()->getNombre(), $componente->getComponente()->getNombre());
                                return false;
                            }

                        }else{
                            $this->mensaje = sprintf('El componente no tiene tarifa en %s %s %s.', $servicio->getFechahorainicio()->format('Y/m/d'), $servicio->getServicio()->getNombre(), $componente->getComponente()->getNombre());
                            return false;
                        }

                    endforeach;

                }else{
                    $this->mensaje = sprintf('El servicio no tiene componente en %s %s.', $servicio->getFechahorainicio()->format('Y/m/d'), $servicio->getServicio()->getNombre());
                    return false;
                }

            endforeach;

        }else{
            $this->mensaje = 'El la cotización no tiene servicios.';
            return false;
        }

        if(!empty($this->clasificacionTarifas)){
            $this->orderResumenTarifas();
            $datosTabs['tarifas']['rangos'] = $this->clasificacionTarifas;
            $datosTabs['tarifas']['resumen'] = $this->resumendeClasificado;

        }

        $this->datosTabs = $datosTabs;
        $this->datosCotizacion = $datosCotizacion;

        return true;
    }

    public function getMensaje(){
        return $this->mensaje;
    }

    public function getDatosTabs(){
        return $this->datosTabs;
    }

    public function getDatosCotizacion(){
        return $this->datosCotizacion;
    }

    public function orderResumenTarifas(){

        usort($this->clasificacionTarifas, function($a, $b) {
            return $b['edadMin'] <=> $a['edadMin']; //inverso
        });

        foreach ($this->clasificacionTarifas as &$clase):

            foreach ($clase['tarifa'] as $tarifa):
                $clase['resumen'][$tarifa['tipoTarId']]['tipoTarNombre'] = $tarifa['tipoTarNombre'];
                $clase['resumen'][$tarifa['tipoTarId']]['tipoTarTitulo'] = $tarifa['tipoTarTitulo'];
                $clase['resumen'][$tarifa['tipoTarId']]['tipoTarOculto'] = $tarifa['tipoTarOculto'];

                $this->resumendeClasificado[$tarifa['tipoTarId']]['nombre'] = $tarifa['tipoTarNombre'];
                $this->resumendeClasificado[$tarifa['tipoTarId']]['titulo'] = $tarifa['tipoTarTitulo'];
                $this->resumendeClasificado[$tarifa['tipoTarId']]['oculto'] = $tarifa['tipoTarOculto'];

                if(!isset($this->resumendeClasificado[$tarifa['tipoTarId']]['montosoles'])){
                    $this->resumendeClasificado[$tarifa['tipoTarId']]['montosoles'] = 0;
                }

                $this->resumendeClasificado[$tarifa['tipoTarId']]['montosoles'] += $tarifa['montosoles'] * $clase['cantidad'];
                $this->resumendeClasificado[$tarifa['tipoTarId']]['montosoles'] = number_format((float)$this->resumendeClasificado[$tarifa['tipoTarId']]['montosoles'], '2', '.', '');

                if(!isset($this->resumendeClasificado[$tarifa['tipoTarId']]['montodolares'])){
                    $this->resumendeClasificado[$tarifa['tipoTarId']]['montodolares'] = 0;
                }

                $this->resumendeClasificado[$tarifa['tipoTarId']]['montodolares'] += $tarifa['montodolares'] * $clase['cantidad'];
                $this->resumendeClasificado[$tarifa['tipoTarId']]['montodolares'] = number_format((float)$this->resumendeClasificado[$tarifa['tipoTarId']]['montodolares'], '2', '.', '');

                if(!isset($this->resumendeClasificado[$tarifa['tipoTarId']]['ventasoles'])){
                    $this->resumendeClasificado[$tarifa['tipoTarId']]['ventasoles'] = 0;
                }

                $this->resumendeClasificado[$tarifa['tipoTarId']]['ventasoles'] += $tarifa['ventasoles'] * $clase['cantidad'];
                $this->resumendeClasificado[$tarifa['tipoTarId']]['ventasoles'] = number_format((float)$this->resumendeClasificado[$tarifa['tipoTarId']]['ventasoles'], '2', '.', '');

                if(!isset($this->resumendeClasificado[$tarifa['tipoTarId']]['ventadolares'])){
                    $this->resumendeClasificado[$tarifa['tipoTarId']]['ventadolares'] = 0;
                }

                $this->resumendeClasificado[$tarifa['tipoTarId']]['ventadolares'] += $tarifa['ventadolares'] * $clase['cantidad'];
                $this->resumendeClasificado[$tarifa['tipoTarId']]['ventadolares'] = number_format((float)$this->resumendeClasificado[$tarifa['tipoTarId']]['ventadolares'], '2', '.', '');

                //se sobreescribe hasta el final del bucle
                $this->resumendeClasificado[$tarifa['tipoTarId']]['gananciasoles'] = $this->resumendeClasificado[$tarifa['tipoTarId']]['ventasoles'] - $this->resumendeClasificado[$tarifa['tipoTarId']]['montosoles'];
                $this->resumendeClasificado[$tarifa['tipoTarId']]['gananciasoles'] = number_format((float)$this->resumendeClasificado[$tarifa['tipoTarId']]['gananciasoles'], '2', '.', '');

                $this->resumendeClasificado[$tarifa['tipoTarId']]['gananciadolares'] = $this->resumendeClasificado[$tarifa['tipoTarId']]['ventadolares'] - $this->resumendeClasificado[$tarifa['tipoTarId']]['montodolares'];
                $this->resumendeClasificado[$tarifa['tipoTarId']]['gananciadolares'] = number_format((float)$this->resumendeClasificado[$tarifa['tipoTarId']]['gananciadolares'], '2', '.', '');


                if(!isset($clase['resumen'][$tarifa['tipoTarId']]['montosoles'])){
                    $clase['resumen'][$tarifa['tipoTarId']]['montosoles'] = 0;
                }
                $clase['resumen'][$tarifa['tipoTarId']]['montosoles'] += $tarifa['montosoles'];

                if(!isset($clase['resumen'][$tarifa['tipoTarId']]['montodolares'])){
                    $clase['resumen'][$tarifa['tipoTarId']]['montodolares'] = 0;
                }
                $clase['resumen'][$tarifa['tipoTarId']]['montodolares'] += $tarifa['montodolares'];

                if(!isset($clase['resumen'][$tarifa['tipoTarId']]['ventasoles'])){
                    $clase['resumen'][$tarifa['tipoTarId']]['ventasoles'] = 0;
                }
                $clase['resumen'][$tarifa['tipoTarId']]['ventasoles'] += $tarifa['ventasoles'];

                if(!isset($clase['resumen'][$tarifa['tipoTarId']]['ventadolares'])){
                    $clase['resumen'][$tarifa['tipoTarId']]['ventadolares'] = 0;
                }
                $clase['resumen'][$tarifa['tipoTarId']]['ventadolares'] += $tarifa['ventadolares'];

                //si no tiene titulo el componente no se muestra
                if(isset($tarifa['tituloComponente']) && !empty($tarifa['tituloComponente'])){
                    $parteTarifaTitulo = '';
                    if(isset($tarifa['titulo']) && !empty($tarifa['titulo'])){
                        $parteTarifaTitulo =  ' (' . $tarifa['titulo'] . ')';
                    }
                    $parteItinerarioTitulo = '';
                    if(isset($tarifa['tituloItinerario'])){
                        $parteItinerarioTitulo = ' en ' . $tarifa['tituloItinerario'];
                    }
                    $parteCantidad = '';
                    if(isset($tarifa['cantidadComponente']) && $tarifa['cantidadComponente'] > 1 ){
                        $parteCantidad = ' x' . $tarifa['cantidadComponente'] . ' (Dias o Noches en caso de alojamiento)';
                    }




                    $clase['resumen'][$tarifa['tipoTarId']]['detallepaxitems'][] = $tarifa['tituloComponente'] . $parteTarifaTitulo . $parteCantidad . $parteItinerarioTitulo;
                }

            endforeach;

            ksort($clase['resumen']);

        endforeach;

        ksort($this->resumendeClasificado);
    }

    private function obtenerTarifasComponente($componente, $cantidadTotalPasajeros){

        $claseTarifas = [];

        $tiposAux=[];

        //se ejecuta bucle para detectar tipo duplicado
        foreach ($componente as $id => $tarifa):
            $titulo = [];
            $nombre = [];

            $temp = [];
            if(!empty($tarifa['titulo'])){
                $titulo[] = $tarifa['titulo'];
            }
            if(!empty($tarifa['tituloComponente'])){
                $titulo[] = $tarifa['tituloComponente'];
            }

            if(!empty($tarifa['nombre'])){
                $nombre[] = $tarifa['nombre'];
            }
            if(!empty($tarifa['nombreComponente'])){
                $nombre[] = $tarifa['nombreComponente'];
            }

            $temp['titulo'] = implode(' - ', $titulo);
            $temp['nombre'] = implode(' - ', $nombre);
            $temp['cantidad'] = $tarifa['cantidad'];
            $temp['tipoPaxId'] = $tarifa['tipoPaxId'];
            $temp['tipoPaxNombre'] = $tarifa['tipoPaxNombre'];
            $temp['prorrateado'] = $tarifa['prorrateado'];

            $min = $this->edadMin;
            $max = $this->edadMax;

            if(isset($tarifa['edadMin'])){
                $temp['edadMin'] = $tarifa['edadMin'];
                $min = $tarifa['edadMin'];
            }

            if(isset($tarifa['edadMax'])){
                $temp['edadMax'] = $tarifa['edadMax'];
                $max = $tarifa['edadMax'];
            }
            $tipo = 'r' . $min . '-' . $max . 't' . $tarifa['tipoPaxId'];

            $temp['tipo'] = $tipo;
            $temp['generarNuevo'] = false;

            if(array_search($temp['tipo'], $tiposAux, true) != false){
                $temp['generarNuevo'] = true;
            }

            $temp['tarifa'] = $tarifa;

            $claseTarifas[] = $temp;

            if($tarifa['cantidad'] == $cantidadTotalPasajeros){
                $tiposAux[] = $tipo;
            }
        endforeach;

        if(count($claseTarifas) > 0){
            $this->procesarTarifa($claseTarifas, 0, $cantidadTotalPasajeros);
            $this->resetClasificacionTarifas();

        }
    }

    private function resetClasificacionTarifas(){

        foreach ($this->clasificacionTarifas as &$clase):
            $clase['cantidadRestante'] = $clase['cantidad'];

        endforeach;
    }

    private function procesarTarifa($claseTarifas, $ejecucion, $cantidadTotalPasajeros){

        $ejecucion++;

        if(empty($this->clasificacionTarifas)){

            $cantidadTemporal = 0;
            foreach ($claseTarifas as $keyClase => &$clase):

                $auxClase = [];
                $auxClase['tipo'] = $clase['tipo'];
                $auxClase['nombre'] = $clase['nombre'];
                $auxClase['titulo'] = $clase['titulo'];
                $auxClase['cantidad'] = $clase['cantidad'];
                $auxClase['cantidadRestante'] = $clase['cantidad'];
                $auxClase['tipoPaxId'] = $clase['tipoPaxId'];
                $auxClase['tipoPaxNombre'] = $clase['tipoPaxNombre'];
                if(isset($clase['edadMin'])){
                    $auxClase['edadMin'] = $clase['edadMin'];
                }
                if(isset($clase['edadMax'])){
                    $auxClase['edadMax'] = $clase['edadMax'];
                }

                unset($clase['tarifa']['cantidad']);
                unset($clase['tarifa']['montototal']);
                if($cantidadTemporal > 0 && $cantidadTotalPasajeros == $clase['cantidad']){
                    continue;
                }

                $this->clasificacionTarifas[] = $auxClase;
                $cantidadTemporal += $clase['cantidad'];

                if($cantidadTemporal >= $cantidadTotalPasajeros){
                    break;
                }
            endforeach;

        }

        foreach ($claseTarifas as $keyClase => &$clase):

            //los prorrateados no modifican los rangos
            if($clase['cantidad'] <= $cantidadTotalPasajeros) {
                $voterIndex = $this->voter($clase, $cantidadTotalPasajeros);

                if ($voterIndex !== false) {

                    //paso el array principal para adicionar elemento como esta por referencia
                    $this->modificarClasificacion($clase, $voterIndex, $clase['generarNuevo']);
                }
            }

        endforeach;

        //$cantidadTarifas = count($claseTarifas);
        foreach ($claseTarifas as $keyClase => &$clase):

            //los prorrateados se distribuyen
            if($clase['prorrateado'] === false){
                $voterIndex = $this->voter($clase, $cantidadTotalPasajeros);

                if($voterIndex !== false){
                    $this->match($clase, $voterIndex, $cantidadTotalPasajeros);

                    if($clase['cantidad'] < 1){
                        unset($claseTarifas[$keyClase]);
                    }
                }
            }else{

                foreach ($this->clasificacionTarifas as &$clasificacionTarifa):

                    $clasificacionTarifa['tarifa'][] = $clase['tarifa'];

                endforeach;

                unset($claseTarifas[$keyClase]);

            }

        endforeach;

        if($ejecucion <= 10 && count($claseTarifas) > 0){
            $this->procesarTarifa($claseTarifas, $ejecucion, $cantidadTotalPasajeros);
        }

        //si despues del proceso hay tarifas muestro error
        if(count($claseTarifas) > 0 && $ejecucion == 10){

            /*ini_set('xdebug.var_display_max_depth', 5);
            ini_set('xdebug.var_display_max_children', 256);
            ini_set('xdebug.var_display_max_data', 1024);

            var_dump($voterIndex);
            var_dump($claseTarifas);
            var_dump($this->clasificacionTarifas);
            die;
            */
            $this->mensaje = sprintf('Hay tarifas que no pudieron ser clasificadas despues de %d ejecuciones, revise: %s.', $ejecucion, reset($claseTarifas)['nombre']);
        }


    }

    private function modificarClasificacion(&$clase, $voterIndex, $forzarNuevo = false){

        $temp = $this->clasificacionTarifas[$voterIndex];

        $edadMaxima = $this->edadMax;
        $edadMinima = $this->edadMin;

        if(isset($this->clasificacionTarifas[$voterIndex]['edadMin'])){
            $edadMinima = $this->clasificacionTarifas[$voterIndex]['edadMin'];
        }
        if(isset($this->clasificacionTarifas[$voterIndex]['edadMax'])){
            $edadMaxima = $this->clasificacionTarifas[$voterIndex]['edadMax'];
        }

        if(isset($clase['edadMin']) && $clase['edadMin'] > $edadMinima){
            $temp['edadMin'] = $clase['edadMin'];
        }

        if(isset($clase['edadMax']) && $clase['edadMax'] < $edadMaxima){
            $temp['edadMax'] = $clase['edadMax'];
        }
        //cambio de generico a nacionalidad
        if($clase['tipoPaxId'] != 0){
            $temp['tipoPaxId'] = $clase['tipoPaxId'];
            $temp['tipoPaxNombre'] = $clase['tipoPaxNombre'];
        }

        $temp['tipo'] = $clase['tipo'];
        $temp['nombre'] = $clase['nombre'];
        $temp['titulo'] = $clase['titulo'];
        $temp['cantidad'] = $clase['cantidad'];
        $temp['cantidadRestante'] = $clase['cantidad'];

        if($clase['cantidad'] == $this->clasificacionTarifas[$voterIndex]['cantidad']){
            $this->clasificacionTarifas[$voterIndex] = $temp;

        }elseif($clase['cantidad'] < $this->clasificacionTarifas[$voterIndex]['cantidad']){
            if($forzarNuevo === true){
                $temp['nombrePersistente'] = $clase['nombre'];
                $temp['tituloPeristente'] = $clase['titulo'];
            }

            $this->clasificacionTarifas[] = $temp;

            //todo ver si sera necesario
            /*
            if(isset($clase['edadMin']) && $clase['edadMin'] < $edadMaxima){
                $this->clasificacionTarifas[$voterIndex]['edadMax'] = $clase['edadMin'] - 1;
            }*/

            $this->clasificacionTarifas[$voterIndex]['cantidad'] = $this->clasificacionTarifas[$voterIndex]['cantidad'] - $clase['cantidad'];
            $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] = $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] - $clase['cantidad'];


            if($forzarNuevo === true){
                $this->clasificacionTarifas[$voterIndex]['nombrePersistente'] = $this->clasificacionTarifas[$voterIndex]['nombre'];
                $this->clasificacionTarifas[$voterIndex]['tituloPeristente'] = $this->clasificacionTarifas[$voterIndex]['titulo'];
            }
        }else{
            //solo modifico tipo
            if(isset($clase['edadMin']) && $clase['edadMin'] > $edadMinima){
                $this->clasificacionTarifas[$voterIndex]['edadMin'] = $clase['edadMin'];
            }

            if(isset($clase['edadMax']) && $clase['edadMax'] < $edadMaxima){
                $this->clasificacionTarifas[$voterIndex]['edadMax'] = $clase['edadMax'];
            }

            if($clase['tipoPaxId'] != 0){
                $this->clasificacionTarifas[$voterIndex]['tipoPaxId'] = $clase['tipoPaxId'];
                $this->clasificacionTarifas[$voterIndex]['tipoPaxNombre'] = $clase['tipoPaxNombre'];
            }

        }
    }

    private function match(&$clase, $voterIndex, $cantidadTotalPasajeros){
        if($clase['cantidad'] == $this->clasificacionTarifas[$voterIndex]['cantidadRestante']){
            $clase['cantidad'] = 0;
            $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] = 0;
            unset($clase['tarifa']['cantidad']);
            unset($clase['tarifa']['montototal']);
            $this->clasificacionTarifas[$voterIndex]['tarifa'][] = $clase['tarifa'];
        }elseif($clase['cantidad'] < $this->clasificacionTarifas[$voterIndex]['cantidadRestante']){
            $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] = $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] - $clase['cantidad'];
            $clase['cantidad'] = 0;
            unset($clase['tarifa']['cantidad']);
            unset($clase['tarifa']['montototal']);
            $this->clasificacionTarifas[$voterIndex]['tarifa'][] = $clase['tarifa'];
        }else{
            $clase['cantidad'] = $clase['cantidad'] - $this->clasificacionTarifas[$voterIndex]['cantidadRestante'];
            $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] = 0;
            unset($clase['tarifa']['cantidad']);
            unset($clase['tarifa']['montototal']);
            $this->clasificacionTarifas[$voterIndex]['tarifa'][] = $clase['tarifa'];
        }
    }

    private function voter($clase, $cantidadTotalPasajeros){

        $clasificacion = $this->clasificacionTarifas;

        $voter = [];

        foreach ($clasificacion as $keyTarifa => $tarifaClasificada):

            $voter[$keyTarifa] = 0;

            if(!isset($tarifaClasificada['edadMin'])){
                $tarifaClasificada['edadMin'] = 0;
            }

            if(!isset($tarifaClasificada['edadMax'])){
                $tarifaClasificada['edadMax'] = 120;
            }

            if(!isset($clase['edadMin'])){
                $clase['edadMin'] = 0;
            }

            if(!isset($clase['edadMax'])){
                $clase['edadMax'] = 120;
            }

            /*
            var_dump($clase['tipoPaxId']);
            var_dump($tarifaClasificada['tipoPaxId']);
            var_dump($clase['edadMin']);
            var_dump($tarifaClasificada['edadMax']);
            var_dump($clase['edadMax']);
            var_dump($tarifaClasificada['edadMin']);
            */

            if(($tarifaClasificada['cantidadRestante'] > 0) &&
                (
                    $clase['tipoPaxId'] == $tarifaClasificada['tipoPaxId'] ||
                    $clase['tipoPaxId'] == 0 ||
                    $tarifaClasificada['tipoPaxId'] == 0
                )
                && $clase['edadMin'] <= $tarifaClasificada['edadMax']
                && $clase['edadMax'] >= $tarifaClasificada['edadMin']

            ){

                $voter[$keyTarifa] += 0.1;

                if($clase['edadMin'] == $tarifaClasificada['edadMin']){
                    $voter[$keyTarifa] += 1.5;
                }else{
                    $voter[$keyTarifa] = 1 / abs($clase['edadMin'] - $tarifaClasificada['edadMin']);
                }

                if($clase['edadMax'] == $tarifaClasificada['edadMax']){
                    $voter[$keyTarifa] += 1.5;
                }else{
                    $voter[$keyTarifa] = 1 / abs($clase['edadMax'] - $tarifaClasificada['edadMax']);
                }

                if($tarifaClasificada['cantidad'] == $clase['cantidad']){
                    $voter[$keyTarifa] += 0.5;
                }

            }

        endforeach;

        if(empty($voter) || max($voter) <= 0 ){
            return false;
        }

        return array_search(max($voter), $voter);
    }

    public function getFormatedDate($FechaStamp)
    {

        if($this->tl != 'es' && $this->tl != 'en'){
            $this->tl = 'es';
        }

        $ano = date('Y',$FechaStamp);
        $mes = date('n',$FechaStamp);
        $dia = date('d',$FechaStamp);
        $diasemana = date('w',$FechaStamp);

        if($this->tl == 'es'){
            $diassemanaN = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
            $mesesN = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'];

            return $diassemanaN[$diasemana] . ', ' . $dia . ' de ' . $mesesN[$mes] . ' de ' . $ano . '.';
        }elseif($this->tl == 'en'){
            $diassemanaN = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $mesesN = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

            return $diassemanaN[$diasemana] . ' ' . $mesesN[$mes] . ' ' . $dia . ', ' . $ano . '.';

        }else{
            return 'Formato de fecha no soportado aun.';
        }

    }

}