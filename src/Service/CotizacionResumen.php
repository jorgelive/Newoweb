<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use App\Service\MainTipocambio;
use Doctrine\ORM\EntityManagerInterface;

class CotizacionResumen implements ContainerAwareInterface
{

    use ContainerAwareTrait;

    private string $tl = 'es';
    private EntityManagerInterface $doctrine;

    private int $edadMin = 0;
    private int $edadMax = 120;

    private array $datosTabs;
    private $datosCotizacion;

    private $clasificacionTarifas = [];
    private $resumendeClasificado = [];

    private $mensaje;

    private $tipocambio;

    function getDoctrine(): EntityManagerInterface
    {
        return $this->doctrine;
    }

    function setTl($tl): CotizacionResumen
    {
        $this->tl = $tl;
        return $this;
    }

    function __construct(EntityManagerInterface $em, MainTipocambio $tipocambio)
    {
        $this->doctrine = $em;
        $this->tipocambio = $tipocambio;
    }

    function getTituloItinerario(\DateTime $fecha, $itinerarioFechaAux) : string
    {
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

        return '';

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


//para mostrar primero el itinerario
        $datosTabs['itinerario']['nombre'] = 'Itinerario';
        $datosTabs['itinerario']['icono'] = 'fa-map';
        $datosTabs['tarifas']['nombre'] = 'Precio';
        $datosTabs['tarifas']['icono'] = 'fa-dollar-sign';
        $datosTabs['incluye']['nombre'] = 'Incluidos';
        $datosTabs['incluye']['icono'] = 'fa-check';
        $datosTabs['agenda']['nombre'] = 'Agenda';
        $datosTabs['agenda']['icono'] = 'fa-calendar';
        $datosTabs['politica']['nombre'] = 'Términos';
        $datosTabs['politica']['icono'] = 'fa-exclamation';
        $datosTabs['politica']['contenido'] = $cotizacion->getCotpolitica()->getContenido();

//datos generales del encabezado
        $datosCotizacion = [];
        $datosCotizacion['file']['nombre'] = $cotizacion->getFile()->getNombre();
        $datosCotizacion['file']['paisid'] = $cotizacion->getFile()->getPais()->getId();
        $datosCotizacion['file']['paisnombre'] = $cotizacion->getFile()->getPais()->getNombre();
        $datosCotizacion['file']['idioma'] = $cotizacion->getFile()->getIdioma()->getNombre();

        $datosCotizacion['cotizacion']['tipocambiocompra'] = $tipoCambio->getCompra();
        $datosCotizacion['cotizacion']['tipocambioventa'] = $tipoCambio->getVenta();
        $datosCotizacion['cotizacion']['fechacotizacion'] = $cotizacion->getCreado()->format('Y-m-d');
        $datosCotizacion['cotizacion']['comision'] = $cotizacion->getComision();
        $datosCotizacion['cotizacion']['adelanto'] = $cotizacion->getAdelanto();
        $datosCotizacion['cotizacion']['nombre'] = $cotizacion->getNombre();
        $datosCotizacion['cotizacion']['titulo'] = $cotizacion->getTitulo();
        $datosCotizacion['cotizacion']['numeropasajeros'] = $cotizacion->getNumeropasajeros();
        $datosCotizacion['cotizacion']['estadocotizacion'] = $cotizacion->getEstadocotizacion()->getNombre();

//Archivos $datosCotizacion['archivos']
        if($cotizacion->getFile()->getFiledocumentos()->count() > 0) {
            $archivosAux = [];
            foreach ($cotizacion->getFile()->getFiledocumentos() as $documento):

                $archivosAux['webPath'] = $documento->getWebPath();     //$this->get('request')->getSchemeAndHttpHost();
                $archivosAux['nombre'] = $documento->getNombre();
                $archivosAux['tipo'] = $documento->getTipo();
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

//Lista de pasajeros $datosCotizacion['pasajeros']
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

        if($cotizacion->getCotservicios()->count() > 0){

//Notas de condiciones especiales solo las muestro si hay servicios
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

//1N bucle de servicios
            $primeraFecha = 0;
            foreach ($cotizacion->getCotservicios() as $keyServicio => $servicio):

                //Itinerarios $datosTabs['itinerario']['itinerarios'][FECHA]
                $itinerarioFechaAux = [];

                if($servicio->getItinerario()->getItinerariodias()->count() > 0){

//2N bucle de dias de itinerario
                    foreach ($servicio->getItinerario()->getItinerariodias() as $keyItinerariodia => $dia):

                        $fecha = clone($servicio->getFechahorainicio());
                        $fecha->add(new \DateInterval('P' . ($dia->getDia() - 1) . 'D'));
                        //Las claves son numericas y empiezan en 0
                        if($keyServicio == 0 && $keyItinerariodia == 0){
                            $nroDia = 1;
                            $primeraFecha = strtotime($fecha->format('Y-m-d'));
                        }else{
                            $nroDia = (strtotime($fecha->format('Y-m-d')) - $primeraFecha) / (60 * 60 * 24) + 1;

                        }

                        //se sobreescriben en cada iteracion
                        $datosTabs['itinerario']['itinerarios'][$fecha->format('ymd')]['fecha'] = $this->getFormatedDate(strtotime($fecha->format('Y-m-d')));
                        $datosTabs['itinerario']['itinerarios'][$fecha->format('ymd')]['nroDia'] = $nroDia;
                        $archivosTempArray = [];
                        if($dia->getItidiaarchivos()->count() > 0){
                            foreach ($dia->getItidiaarchivos() as $archivo):
                                $archivoTemp['nombre'] = $archivo->getMedio()->getNombre();
                                $archivoTemp['titulo'] = $archivo->getMedio()->getTitulo();
                                $archivoTemp['tipo'] = $archivo->getMedio()->getTipo();
                                $archivoTemp['webThumbPath'] = $archivo->getMedio()->getWebThumbPath();
                                $archivoTemp['webPath'] = $archivo->getMedio()->getWebPath();
                                $archivoTemp['inModal'] = $archivo->getMedio()->getInModal();
                                $archivosTempArray[] = $archivoTemp;
                            endforeach;
                        }
                        $datosTabs['itinerario']['itinerarios'][$fecha->format('ymd')]['fechaitems'][] = ['titulo' => $dia->getTitulo(), 'descripcion' => $dia->getContenido(), 'archivos' => $archivosTempArray];
                        unset($archivosTempArray);

                        //Auxiliar de titulos de itinerario por dia en caso de que sean los importantes
                        //para uso en agenda e incluye.
                        if($dia->getImportante() === true){
                            $itinerarioFechaAux[$fecha->format('ymd')] = $dia->getTitulo();
                        }

                    endforeach;
                }

                if($servicio->getCotcomponentes()->count() > 0){

//2N bucle de componenentes
                    foreach($servicio->getCotcomponentes() as $componente):

                        if($componente->getCottarifas()->count() > 0) {

                            $cantidadComponente = 0;
//$tempArrayComponente y $tempArrayTarifa son para clasificacion por rangos
//$tempArrayIncluye solo sirve para la muestra de "incluye" al cliente se deposita en $datosTabs['incluye']['servicios']
                            $tempArrayComponente = [];

                            if (!empty($itinerarioFechaAux)) {
                                $tempArrayComponente['tituloItinerario'] = $this->getTituloItinerario($componente->getFechahorainicio(), $itinerarioFechaAux);
                            }

                            $tempArrayComponente['nombre'] = $componente->getComponente()->getNombre();
                            $tempArrayComponente['tipoComponente'] = $componente->getComponente()->getTipocomponente()->getNombre();
                            $tempArrayComponente['fechahorainicio'] = $componente->getFechahorainicio();
                            $tempArrayComponente['fechahorafin'] = $componente->getFechahorafin();

//la presencia del titulo sera un indicador para mostrarlo o no en agenda ya que el tem array componente es interno para los demas procesos
                            $tempArrayItem=[];
                            if ($componente->getComponente()->getTipocomponente()->getAgendable() === true && $componente->getComponente()->getComponenteitems()->count() > 0) {
                                foreach ($componente->getComponente()->getComponenteitems() as $item) {
                                    if($item->getNomostrartarifa() !== true){
                                        $tempArrayItem[] = $item->getTitulo();

                                    }

                                }
                                $tempArrayComponente['titulo'] = implode(', ',  $tempArrayItem);
                            }

//3N bucle de tarifas
                            foreach ($componente->getCottarifas() as $tarifa):

//Incluye

                                $tempArrayInternoIncluye = [];
//Para los servicios que no tienen dias de itinerario los clasifico como varios y le pongo un id -1
                                if (isset($tempArrayComponente['tituloItinerario']) && !empty($tempArrayComponente['tituloItinerario'])) {
                                    $servicioId = $servicio->getId();
                                    $datosTabs['incluye']['internoIncluidos'][$servicioId]['tituloItinerario'] = $tempArrayComponente['tituloItinerario'];

                                } else {
                                    $servicioId = -1;
                                    $datosTabs['incluye']['internoIncluidos'][$servicioId]['tituloItinerario'] = 'Varios';
                                }

                                $datosTabs['incluye']['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['tituloTipotarifa'] = $tarifa->getTipotarifa()->getTitulo();
//Agrupo las tarifas incluidas para manejo interno
                                $tempArrayInternoIncluye['nombre'] = $tarifa->getTarifa()->getNombre();
                                $tempArrayInternoIncluye['cantidad'] = (int)($tarifa->getCantidad());
                                if (!empty($tarifa->getTarifa()->getValidezInicio())) {
                                    $tempArrayInternoIncluye['validezInicio'] = $tarifa->getTarifa()->getValidezInicio();
                                }

                                if (!empty($tarifa->getTarifa()->getValidezFin())) {
                                    $tempArrayInternoIncluye['validezFin'] = $tarifa->getTarifa()->getValidezFin();
                                }

                                if (!empty($tarifa->getTarifa()->getCapacidadmin())) {
                                    $tempArrayInternoIncluye['capacidadMin'] = $tarifa->getTarifa()->getCapacidadmin();
                                }

                                if (!empty($tarifa->getTarifa()->getCapacidadmax())) {
                                    $tempArrayInternoIncluye['capacidadMax'] = $tarifa->getTarifa()->getCapacidadmax();
                                }

                                if (!empty($tarifa->getTarifa()->getEdadmin())) {
                                    $tempArrayInternoIncluye['edadMin'] = $tarifa->getTarifa()->getEdadmin();
                                }

                                if (!empty($tarifa->getTarifa()->getEdadmax())) {
                                    $tempArrayInternoIncluye['edadMax'] = $tarifa->getTarifa()->getEdadmax();
                                }

                                if (!empty($tarifa->getTarifa()->getTipopax())) {
                                    $tempArrayInternoIncluye['tipoPaxId'] = $tarifa->getTarifa()->getTipopax()->getId();
                                    $tempArrayInternoIncluye['tipoPaxNombre'] = $tarifa->getTarifa()->getTipopax()->getNombre();
                                }

                                $tempArrayDetalle = [];
                                foreach($tarifa->getCottarifadetalles() as $id => $detalle):
                                    $tempArrayDetalle[$id]['contenido'] = $detalle->getDetalle();
                                    $tempArrayDetalle[$id]['tipoId'] = $detalle->getTipotarifadetalle()->getId();
                                    $tempArrayDetalle[$id]['tipoNombre'] = $detalle->getTipotarifadetalle()->getNombre();
                                    $tempArrayDetalle[$id]['tipoTitulo'] = empty($detalle->getTipotarifadetalle()->getTitulo()) ? $tempArrayDetalle[$id]['tipoNombre'] : $detalle->getTipotarifadetalle()->getTitulo();
                                endforeach;

                                if (!empty($tempArrayDetalle)) {
                                    $tempArrayInternoIncluye['detalles'] = $tempArrayDetalle;
                                }

                                $datosTabs['incluye']['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['cantidadComponente'] = $componente->getCantidad();

                                $datosTabs['incluye']['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['nombre'] = $componente->getComponente()->getNombre();

                                $datosTabs['incluye']['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['listaclase'] = $tarifa->getTipotarifa()->getListaclase();
                                $datosTabs['incluye']['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['listacolor'] = !empty($tarifa->getTipotarifa()->getListacolor()) ? $tarifa->getTipotarifa()->getListacolor() : 'inherit';

                                if (!empty($componente->getFechahorainicio())) {
                                    $datosTabs['incluye']['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['fecha'] = $componente->getFechahorainicio()->format('Y-m-d');
                                }

                                $datosTabs['incluye']['internoIncluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId()]['tarifas'][] = $tempArrayInternoIncluye;

                                unset($tempArrayInternoIncluye);

                                ksort($datosTabs['incluye']['internoIncluidos'][$servicioId]['tipotarifas']);

//Agrupo las tarifas incluidas para mostrar al cliente

                                $tempArrayIncluye = [];

                                if ($componente->getComponente()->getComponenteitems()->count() > 0) {
//Pongo el titulo del itinerario que ya defini para los internos

                                    $datosTabs['incluye']['incluidos'][$servicioId]['tituloItinerario'] = $datosTabs['incluye']['internoIncluidos'][$servicioId]['tituloItinerario'];
                                    $datosTabs['incluye']['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['tituloTipotarifa'] = $tarifa->getTipotarifa()->getTitulo();

//4N bucle de items, para cada item pongo la tarifa
                                    foreach ($componente->getComponente()->getComponenteitems() as $item) {
                                        if (!empty($tarifa->getTarifa()->getTitulo()) && $item->getNomostrartarifa() !== true) {
                                            $tempArrayIncluye['titulo'] = $tarifa->getTarifa()->getTitulo();
                                            $tempArrayIncluye['cantidad'] = (int)($tarifa->getCantidad());
                                            if (!empty($tarifa->getTarifa()->getValidezInicio())) {
                                                $tempArrayIncluye['validezInicio'] = $tarifa->getTarifa()->getValidezInicio();
                                            }

                                            if (!empty($tarifa->getTarifa()->getValidezFin())) {
                                                $tempArrayIncluye['validezFin'] = $tarifa->getTarifa()->getValidezFin();
                                            }

                                            $tempArrayIncluye['mostrarcostoincluye'] = false;
                                            if ($tarifa->getTipotarifa()->getMostrarcostoincluye() ===true && !empty($tarifa->getTarifa()->getMonto()) && !empty($tarifa->getTarifa()->getMoneda())) {
                                                $tempArrayIncluye['mostrarcostoincluye'] = true;
                                                $tempArrayIncluye['simboloMoneda'] = $tarifa->getTarifa()->getMoneda()->getSimbolo();
                                                $tempArrayIncluye['costo'] = $tarifa->getTarifa()->getMonto();
                                            }

                                            if (!empty($tarifa->getTarifa()->getCapacidadmin())) {
                                                $tempArrayIncluye['capacidadMin'] = $tarifa->getTarifa()->getCapacidadmin();
                                            }

                                            if (!empty($tarifa->getTarifa()->getCapacidadmax())) {
                                                $tempArrayIncluye['capacidadMax'] = $tarifa->getTarifa()->getCapacidadmax();
                                            }

                                            if (!empty($tarifa->getTarifa()->getEdadmin())) {
                                                $tempArrayIncluye['edadMin'] = $tarifa->getTarifa()->getEdadmin();
                                            }

                                            if (!empty($tarifa->getTarifa()->getEdadmax())) {
                                                $tempArrayIncluye['edadMax'] = $tarifa->getTarifa()->getEdadmax();
                                            }

                                            if (!empty($tarifa->getTarifa()->getTipopax())) {
                                                $tempArrayIncluye['tipoPaxId'] = $tarifa->getTarifa()->getTipopax()->getId();
                                                $tempArrayIncluye['tipoPaxNombre'] = $tarifa->getTarifa()->getTipopax()->getNombre();
                                            }
                                            $tempArrayDetalle = [];
                                            foreach($tarifa->getCottarifadetalles() as $id => $detalle):
                                                if(!$detalle->getTipotarifadetalle()->getInterno()) {
                                                    $tempArrayDetalle[$id]['contenido'] = $detalle->getDetalle();
                                                    $tempArrayDetalle[$id]['tipoId'] = $detalle->getTipotarifadetalle()->getId();
                                                    $tempArrayDetalle[$id]['tipoNombre'] = $detalle->getTipotarifadetalle()->getNombre();
                                                    $tempArrayDetalle[$id]['tipoTitulo'] = empty($detalle->getTipotarifadetalle()->getTitulo()) ? $tempArrayDetalle[$id]['tipoNombre'] : $detalle->getTipotarifadetalle()->getTitulo();
                                                }
                                            endforeach;

                                            if (!empty($tempArrayDetalle)) {
                                                $tempArrayIncluye['detalles'] = $tempArrayDetalle;
                                            }
                                        }

                                        $datosTabs['incluye']['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['cantidadComponente'] = $componente->getCantidad();

                                        $datosTabs['incluye']['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['titulo'] = $item->getTitulo();

                                        $datosTabs['incluye']['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['listaclase'] = $tarifa->getTipotarifa()->getListaclase();
                                        $datosTabs['incluye']['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['listacolor'] = !empty($tarifa->getTipotarifa()->getListacolor()) ? $tarifa->getTipotarifa()->getListacolor() : 'inherit';

                                        if (!empty($componente->getFechahorainicio())) {
                                            $datosTabs['incluye']['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['fecha'] = $componente->getFechahorainicio()->format('Y-m-d');
                                        }

                                        if (!empty($tempArrayIncluye)) {
                                            $datosTabs['incluye']['incluidos'][$servicioId]['tipotarifas'][$tarifa->getTipotarifa()->getId()]['componentes'][$componente->getId() . '-' . $item->getId()]['tarifas'][] = $tempArrayIncluye;
                                            unset($tempArrayIncluye);
                                        }

                                    }

                                    ksort($datosTabs['incluye']['incluidos'][$servicioId]['tipotarifas']);
                                }

//Tarifa por rango y el resumen por rango usa los temporales $tempArrayComponente['tarifas'][] = $tempArrayTarifa genera las variables $this->clasificacionTarifas y $this->resumendeClasificado; procesando los temporales
                                $tempArrayTarifa = [];
                                $tempArrayTarifa['id'] = $tarifa->getId();
                                $tempArrayTarifa['nombreServicio'] = $servicio->getServicio()->getNombre();
                                $tempArrayTarifa['cantidadComponente'] = $componente->getCantidad();
                                $tempArrayTarifa['nombreComponente'] = $componente->getComponente()->getNombre();
                                ////manejo interno no utilizo titulo
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
                                //manejo interno solo utilizo el titulo psra tituloPersistente
                                if(!empty($tarifa->getTarifa()->getTitulo())){
                                    $tempArrayTarifa['titulo'] = $tarifa->getTarifa()->getTitulo();
                                }

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

                                $tempArrayTarifa['monedaOriginal'] = $tarifa->getMoneda()->getNombre();
                                $tempArrayTarifa['montoOriginal'] = number_format((float)($tarifa->getMonto()), 2, '.', '');

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
//no muestra el precio al pasajero
                                $tempArrayTarifa['tipoTarOculto'] = $tarifa->getTipotarifa()->getOculto();

                                $tempArrayComponente['tarifas'][] = $tempArrayTarifa;
                                unset($tempArrayTarifa);

                            endforeach;

//punto de ingreso a la clasificacion $this->obtenerTarifasComponente >>> $this->procesarTarifa  >>> $this->modificarClasificacion
                            $this->obtenerTarifasComponente($tempArrayComponente['tarifas'], $datosCotizacion['cotizacion']['numeropasajeros']);

                            if(!empty($this->mensaje)){
                                return false;
                            }

//solo si tiene titulo lo pongo en agenda
                            if(isset($tempArrayComponente['titulo'])){
                                $datosTabs['agenda']['componentes'][] = $tempArrayComponente;
                            }

                            unset($tempArrayComponente);

                            //no he sumado prorrateados puede ir en blanco para el caso de que solo exista prorrateado y cuadre con la cantidad de pasajeros
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

//Ordenamos el varios al final
            if(isset($datosTabs['incluye']['incluidos'][-1])){
                $datosTabs['incluye']['incluidos'][] = $datosTabs['incluye']['incluidos'][-1];
                unset($datosTabs['incluye']['incluidos'][-1]);
            }


        }else{
            $this->mensaje = 'El la cotización no tiene servicios.';
            return false;
        }
//Hacemos disponible los datos de la cotización para el resumen de las tarifas.
        $this->datosCotizacion = $datosCotizacion;

        if(!empty($this->clasificacionTarifas)){
            $this->orderResumenTarifas();
            $datosTabs['tarifas']['rangos'] = $this->clasificacionTarifas;
//es el resumen final de todos los pasajeros de tarifas costos v netas por tipo de tarifa incluido no incluido, etc  $this->resumendeClasificado
            $datosTabs['tarifas']['resumen'] = $this->resumendeClasificado;

        }

        $this->datosTabs = $datosTabs;
        //ini_set('xdebug.var_display_max_depth', 50);
        //ini_set('xdebug.var_display_max_children', 50);
        //ini_set('xdebug.var_display_max_data', 10240);

        //var_dump($datosTabs['agenda']);
        //var_dump($this->clasificacionTarifas);
        //var_dump($this->resumendeClasificado);

        //die;

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
            if (!isset($b['edadMin'])){ $b['edadMin'] = $this->edadMin; }
            if (!isset($b['edadMax'])){ $b['edadMax'] = $this->edadMax; }
            return $b['edadMin'] <=> $a['edadMin']; //inverso
        });

        //el bucle esta pasado por referencia!!!!!
        foreach ($this->clasificacionTarifas as &$clase):

            foreach ($clase['tarifas'] as $tarifa):
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

                //se sobreescribem hasta el final del bucle

                $this->resumendeClasificado[$tarifa['tipoTarId']]['adelantosoles'] = $this->resumendeClasificado[$tarifa['tipoTarId']]['ventasoles'] * $this->datosCotizacion['cotizacion']['adelanto'] / 100;
                $this->resumendeClasificado[$tarifa['tipoTarId']]['adelantosoles'] = number_format((float)$this->resumendeClasificado[$tarifa['tipoTarId']]['adelantosoles'], '2', '.', '');

                $this->resumendeClasificado[$tarifa['tipoTarId']]['adelantodolares'] = $this->resumendeClasificado[$tarifa['tipoTarId']]['ventadolares'] * $this->datosCotizacion['cotizacion']['adelanto'] / 100;
                $this->resumendeClasificado[$tarifa['tipoTarId']]['adelantodolares'] = number_format((float)$this->resumendeClasificado[$tarifa['tipoTarId']]['adelantodolares'], '2', '.', '');

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

//todo reemplazar esta logica
//                if(isset($tarifa['tituloComponente']) && !empty($tarifa['tituloComponente'])){
//                    $parteTarifaTitulo = '';
//                    if(isset($tarifa['titulo']) && !empty($tarifa['titulo'])){
//                        $parteTarifaTitulo =  ' (' . $tarifa['titulo'] . ')';
//                    }
//                    $parteItinerarioTitulo = '';
//                    if(isset($tarifa['tituloItinerario'])){
//                        $parteItinerarioTitulo = ' en ' . $tarifa['tituloItinerario'];
//                    }
//                    $parteCantidad = '';
//                    if(isset($tarifa['cantidadComponente']) && $tarifa['cantidadComponente'] > 1 ){
//                        $parteCantidad = ' x' . $tarifa['cantidadComponente'] . ' (Dias o Noches en caso de alojamiento)';
//                    }
//
//                    $clase['resumen'][$tarifa['tipoTarId']]['detallepaxitems'][] = $tarifa['tituloComponente'] . $parteTarifaTitulo . $parteCantidad . $parteItinerarioTitulo;
//                }

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
            $temp = [];

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


//el titulo persistente es para mostrar el nombre de la tarifa en la clasificacion por rangos en caso por ejemplo de comunidad andina
            if(isset($tarifa['titulo'])){
                $temp['tituloONombre'] = $tarifa['titulo'];
            }else{
                //fallback pero no deberia ocurrir mostrar el nombre al pasajerpo
                $temp['tituloONombre'] = $tarifa['nombre'];
            }
            $temp['tituloPersistente'] = false;
            //si existe
            if(array_search($temp['tipo'], $tiposAux, true) !== false){
                $temp['tituloPersistente'] = true;
            }

            $temp['tarifa'] = $tarifa;
            $claseTarifas[] = $temp;

            $tiposAux[] = $tipo;


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
                    $this->modificarClasificacion($clase, $voterIndex, $clase['tituloPersistente']);
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
            } else {

                foreach ($this->clasificacionTarifas as &$clasificacionTarifa):
                    $clasificacionTarifa['tarifas'][] = $clase['tarifa'];
                endforeach;

                unset($claseTarifas[$keyClase]);

            }

        endforeach;

        if($ejecucion <= 10 && count($claseTarifas) > 0){
            $this->procesarTarifa($claseTarifas, $ejecucion, $cantidadTotalPasajeros);
        }

        //si despues del proceso hay tarifas muestro error
        if(count($claseTarifas) > 0 && $ejecucion == 10){
            $this->mensaje = sprintf('Hay tarifas que no pudieron ser clasificadas despues de %d ejecuciones, revise: %s.', $ejecucion, reset($claseTarifas)['tarifa']['nombreServicio'] . ' - ' . reset($claseTarifas)['tarifa']['nombreComponente'] . ' - ' . reset($claseTarifas)['tarifa']['nombre']);
        }
    }

    private function modificarClasificacion(&$clase, $voterIndex, $tituloPersistente = false){

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
        $temp['cantidad'] = $clase['cantidad'];
        $temp['cantidadRestante'] = $clase['cantidad'];

        if($tituloPersistente === true){
            $temp['tituloPersistente'] = $clase['tituloONombre'];
        }

        if($clase['cantidad'] == $this->clasificacionTarifas[$voterIndex]['cantidad']){
            $this->clasificacionTarifas[$voterIndex] = $temp;

        }elseif($clase['cantidad'] < $this->clasificacionTarifas[$voterIndex]['cantidad']){

            $this->clasificacionTarifas[] = $temp;

            $this->clasificacionTarifas[$voterIndex]['cantidad'] = $this->clasificacionTarifas[$voterIndex]['cantidad'] - $clase['cantidad'];
            $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] = $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] - $clase['cantidad'];

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
            $this->clasificacionTarifas[$voterIndex]['tarifas'][] = $clase['tarifa'];
        }elseif($clase['cantidad'] > $this->clasificacionTarifas[$voterIndex]['cantidadRestante']){
            $clase['cantidad'] = $clase['cantidad'] - $this->clasificacionTarifas[$voterIndex]['cantidadRestante'];
            $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] = 0;
            $this->clasificacionTarifas[$voterIndex]['tarifas'][] = $clase['tarifa'];
        }else{ //todo encontrar cuando se usa esto
            $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] = $this->clasificacionTarifas[$voterIndex]['cantidadRestante'] - $clase['cantidad'];
            $clase['cantidad'] = 0;
            $this->clasificacionTarifas[$voterIndex]['tarifas'][] = $clase['tarifa'];
        }
        unset($clase['tarifa']['cantidad']);
        unset($clase['tarifa']['montototal']);
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

    public function getFormatedDate($FechaStamp): string
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

            return $diassemanaN[$diasemana] . ', ' . $dia . ' de ' . $mesesN[$mes] . ' de ' . $ano;
        }elseif($this->tl == 'en'){
            $diassemanaN = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $mesesN = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

            return $diassemanaN[$diasemana] . ' ' . $mesesN[$mes] . ' ' . $dia . ', ' . $ano;

        }else{
            return 'Idioma no soportado aun.';
        }

    }
}