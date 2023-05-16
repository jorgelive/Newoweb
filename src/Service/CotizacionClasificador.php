<?php

namespace App\Service;

use App\Entity\MaestroTipocambio;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\CotizacionCotizacion;

class CotizacionClasificador
{

    private TranslatorInterface $translator;
    private CotizacionCotizacion $cotizacion;

    private int $edadMin = 0;
    private int $edadMax = 120;

    private array $tarifasClasificadas = [];
    //Es el resumen final de todos los pasajeros de tarifas costos v netas por tipo de tarifa incluido no incluido, etc 
    private array $resumenDeClasificado = [];

    private string $mensaje;

    private CotizacionItinerario $cotizacionItinerario;

    function __construct(TranslatorInterface $translator, CotizacionItinerario $cotizacionItinerario)
    {
        $this->translator = $translator;
        $this->cotizacionItinerario = $cotizacionItinerario;
    }

    public function clasificar(CotizacionCotizacion $cotizacion, MaestroTipocambio $tipocambio): bool
    {
        $this->cotizacion = $cotizacion;
        if($cotizacion->getCotservicios()->count() > 0){
            foreach($cotizacion->getCotservicios() as $servicio):
                if($servicio->getCotcomponentes()->count() > 0){
                    foreach($servicio->getCotcomponentes() as $componente):
                        if($componente->getCottarifas()->count() > 0){

                            $cantidadComponente = 0;
                            $tempArrayComponente = [];
                            $tempArrayComponente['tituloItinerario'] = $this->cotizacionItinerario->getTituloItinerario($componente->getFechahorainicio(), $servicio);
                            $tempArrayComponente['nombre'] = $componente->getComponente()->getNombre();
                            $tempArrayComponente['tipoComponente'] = $componente->getComponente()->getTipocomponente()->getNombre();
                            $tempArrayComponente['fechahorainicio'] = $componente->getFechahorainicio();
                            $tempArrayComponente['fechahorafin'] = $componente->getFechahorafin();

                            foreach($componente->getCottarifas() as $tarifa):

//Tarifa por rango y el resumen por rango usa los temporales $tempArrayComponente['tarifas'][] = $tempArrayTarifa genera las variables $this->tarifasClasificadas y $this->resumenDeClasificado; procesando los temporales
                                $tempArrayTarifa = [];
                                $tempArrayTarifa['id'] = $tarifa->getId();
                                $tempArrayTarifa['nombreServicio'] = $servicio->getServicio()->getNombre();
                                $tempArrayTarifa['cantidadComponente'] = $componente->getCantidad();
                                $tempArrayTarifa['nombreComponente'] = $componente->getComponente()->getNombre();


                                if($tarifa->getTarifa()->isProrrateado() === true){
                                    $tempArrayTarifa['montounitario'] = number_format(
                                        (float)($tarifa->getMonto() * $tarifa->getCantidad() / $this->cotizacion->getNumeropasajeros() * $componente->getCantidad()
                                        ), 2, '.', '');
                                    $tempArrayTarifa['montototal'] = number_format(
                                        (float)($tarifa->getMonto() * $tarifa->getCantidad() * $componente->getCantidad()
                                        ),2, '.', '');
                                    $tempArrayTarifa['cantidad'] = (int)($this->cotizacion->getNumeropasajeros());
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
                                }

                                $tempArrayTarifa['nombre'] = $tarifa->getTarifa()->getNombre();
                                //manejo interno solo utilizo el titulo para tituloPersistente
                                if(!empty($tarifa->getTarifa()->getTitulo())){
                                    $tempArrayTarifa['titulo'] = $tarifa->getTarifa()->getTitulo();
                                }

                                $tempArrayTarifa['moneda'] = $tarifa->getMoneda()->getId();
                                //dolares = 2
                                if($tarifa->getMoneda()->getId() == 2){
                                    $tempArrayTarifa['montosoles'] = number_format((float)($tempArrayTarifa['montounitario'] * ($tipocambio->getCompra()/2 + $tipocambio->getVenta()/2)), 2, '.', '');
                                    $tempArrayTarifa['montodolares'] = $tempArrayTarifa['montounitario'];
                                }elseif($tarifa->getMoneda()->getId() == 1){
                                    $tempArrayTarifa['montosoles'] = $tempArrayTarifa['montounitario'];
                                    $tempArrayTarifa['montodolares'] = number_format((float)($tempArrayTarifa['montounitario'] / ($tipocambio->getCompra()/2 + $tipocambio->getVenta()/2)), 2, '.', '');
                                }else{
                                    $this->mensaje = 'La aplicación solo puede utilizar Soles y dólares en las tarifas.';
                                    return false;
                                }

                                $tempArrayTarifa['monedaOriginal'] = $tarifa->getMoneda()->getNombre();
                                $tempArrayTarifa['montoOriginal'] = number_format((float)($tarifa->getMonto()), 2, '.', '');

                                $factorComision = 1;
                                if($tarifa->getTipotarifa()->isComisionable() == true){
                                    $factorComision = 1 + ($cotizacion->getComision() / 100);
                                }

                                $tempArrayTarifa['ventasoles'] = number_format((float)($tempArrayTarifa['montosoles'] * $factorComision), 2, '.', '');
                                $tempArrayTarifa['ventadolares'] = number_format((float)($tempArrayTarifa['montodolares'] * $factorComision), 2, '.', '');
                                unset($factorComision);

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
                                    $tempArrayTarifa['tipoPaxTitulo'] = $tarifa->getTarifa()->getTipopax()->getTitulo();
                                }else{
                                    $tempArrayTarifa['tipoPaxId'] = 0;
                                    $tempArrayTarifa['tipoPaxNombre'] = 'Cualquier_nacionalidad';
                                    $tempArrayTarifa['tipoPaxTitulo'] = ucfirst($this->translator->trans('cualquier_nacionalidad', [], 'messages'));
                                }

                                $tempArrayTarifa['tipoTarId'] = $tarifa->getTipotarifa()->getId();
                                $tempArrayTarifa['tipoTarNombre'] = $tarifa->getTipotarifa()->getNombre();
                                $tempArrayTarifa['tipoTarTitulo'] = $tarifa->getTipotarifa()->getTitulo();
                                $tempArrayTarifa['tipoTarListacolor'] = $tarifa->getTipotarifa()->getListacolor();
//no muestra el precio al pasajero
                                $tempArrayTarifa['tipoTarOculto'] = $tarifa->getTipotarifa()->isOculto();

                                $tempArrayComponente['tarifas'][] = $tempArrayTarifa;
                                unset($tempArrayTarifa);

                            endforeach;

//punto de ingreso a la clasificacion $this->obtenerTarifasComponente >>> $this->procesarTarifa  >>> $this->modificarClasificacion
                            $this->obtenerTarifasComponente($tempArrayComponente['tarifas'], $this->cotizacion->getNumeropasajeros());

                            if(!empty($this->mensaje)){
                                return false;
                            }
                            
                            unset($tempArrayComponente);

                            //no he sumado prorrateados puede ir en blanco para el caso de que solo exista prorrateado y cuadre con la cantidad de pasajeros
                            if($cantidadComponente > 0 && $cantidadComponente != $cotizacion->getNumeropasajeros()){
                                $this->mensaje = sprintf('La cantidad de pasajeros por componente no coincide con la cantidad de pasajeros en %s %s %s.', $servicio->getFechahorainicio()->format('Y/m/d'), $servicio->getServicio()->getNombre(), $componente->getComponente()->getNombre());
                                return false;
                            }
                            unset($cantidadComponente);

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
            $this->mensaje = 'La cotización no tiene servicios.';
            return false;
        }
//Hacemos disponible los datos de la cotización para el resumen de las tarifas.


        if(empty($this->tarifasClasificadas)) {
            $this->mensaje = 'No se pudieron clasificar las tarifas.';
            return false;
            
        }
        //ordenar
        usort($this->tarifasClasificadas, function($a, $b){
            if(!isset($b['edadMin'])){ $b['edadMin'] = $this->edadMin; }
            if(!isset($b['edadMax'])){ $b['edadMax'] = $this->edadMax; }
            return $b['edadMin'] <=> $a['edadMin']; //inverso
        });
        
        $this->resumirTarifas();
            
        $this->datos['rangos'] = $this->tarifasClasificadas;
        $this->datos['tarifas']['resumen'] = $this->resumenDeClasificado;
        
        return true;
    }

    public function getMensaje(): string
    {
        return $this->mensaje;
    }

    public function getTarifasClasificadas(): array
    {
        return $this->tarifasClasificadas;
    }

    public function getResumenDeClasificado(): array
    {
        return $this->resumenDeClasificado;
    }

    private function obtenerTarifasComponente(array $componente, int $cantidadTotalPasajeros): void
    {
        $claseTarifas = [];
        $tiposAux = [];

//se ejecuta bucle para detectar tipo duplicado
        foreach($componente as $tarifa):
            $temp = [];

            $temp['cantidad'] = $tarifa['cantidad'];
            $temp['tipoPaxId'] = $tarifa['tipoPaxId'];

            $temp['tipoPaxNombre'] = $tarifa['tipoPaxNombre'];
            $temp['tipoPaxTitulo'] = $tarifa['tipoPaxTitulo'];
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
            //Al final de la ejecucion la cantidad restante sera la cantidad de la clase,
            $this->resetClasificacionTarifas();
        }
    }

    private function resetClasificacionTarifas(): void
    {
        foreach($this->tarifasClasificadas as &$clase):
            $clase['cantidadRestante'] = $clase['cantidad'];
        endforeach;
        //destruimos la referencia
        unset($clase);
    }

    private function procesarTarifa(array $claseTarifas, int $ejecucion, int $cantidadTotalPasajeros): void
    {
        $ejecucion++;

        if(empty($this->tarifasClasificadas)){

            $cantidadTemporal = 0;
            foreach($claseTarifas as &$clase):

                $auxClase = [];
                $auxClase['tipo'] = $clase['tipo'];
                $auxClase['cantidad'] = $clase['cantidad'];
                $auxClase['cantidadRestante'] = $clase['cantidad'];
                $auxClase['tipoPaxId'] = $clase['tipoPaxId'];
                $auxClase['tipoPaxNombre'] = $clase['tipoPaxNombre'];
                $auxClase['tipoPaxTitulo'] = $clase['tipoPaxTitulo'];
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

                $this->tarifasClasificadas[] = $auxClase;
                $cantidadTemporal += $clase['cantidad'];

                if($cantidadTemporal >= $cantidadTotalPasajeros){
                    break;
                }
            endforeach;
            unset($clase);
        }

        foreach($claseTarifas as &$clase):
            //los prorrateados no modifican los rangos
            if($clase['cantidad'] <= $cantidadTotalPasajeros){
                $voterIndex = $this->voter($clase);
                //es -1 si no encuentra
                if($voterIndex >= 0){
                    //paso el array principal para adicionar elemento como esta por referencia
                    $this->modificarClasificacion($clase, $voterIndex);
                }
            }

        endforeach;
        //destruimos la referencia
        unset($clase);

        foreach($claseTarifas as $keyClase => &$clase):
            //los prorrateados se distribuyen
            if($clase['prorrateado'] === false){
                $voterIndex = $this->voter($clase);
                //es -1 si no encuentra
                if($voterIndex >= 0){
                    $this->match($clase, $voterIndex, $clase['tituloPersistente']);

                    if($clase['cantidad'] < 1){
                        unset($claseTarifas[$keyClase]);
                    }
                }
            }else{
                foreach($this->tarifasClasificadas as &$clasificacionTarifa):
                    $clasificacionTarifa['tarifas'][] = $clase['tarifa'];
                endforeach;

                unset($claseTarifas[$keyClase]);
            }

        endforeach;
        //destruimos la referencia
        unset($clase);

        if($ejecucion <= 10 && count($claseTarifas) > 0){
            $this->procesarTarifa($claseTarifas, $ejecucion, $cantidadTotalPasajeros);
        }

        //si despues del proceso hay tarifas muestro error
        if(count($claseTarifas) > 0 && $ejecucion == 10){

            $tarifasdisplay = '';
            foreach ($this->tarifasClasificadas as $currentTarifa):
                $tarifasdisplay .= '[';
                if(isset($currentTarifa['edadMin'])) {
                    $tarifasdisplay .= 'min:' . $currentTarifa['edadMin'];
                }
                if(isset($currentTarifa['edadMax'])) {
                    $tarifasdisplay .= ', max :' . $currentTarifa['edadMax'];
                }
                if(isset($currentTarifa['tipoPaxNombre'])){
                    $tarifasdisplay .= ', tipo: ' . $currentTarifa['tipoPaxNombre'];
                }
                if(isset($currentTarifa['cantidadRestante'])){
                    $tarifasdisplay .= ', restante: ' . $currentTarifa['cantidadRestante'];
                }
                if(isset($currentTarifa['tarifas'])){
                    $tarifasdisplay .= ', contenido: ' . count($currentTarifa['tarifas']);
                }
                $tarifasdisplay .= '] ';
            endforeach;

            $tarifaEnError = reset($claseTarifas);

            $tarifaEnErrorDisplay = $tarifaEnError['tarifa']['nombreServicio']
                . ' - ' . $tarifaEnError['tarifa']['nombreComponente']
                . ' - ' . $tarifaEnError['tarifa']['nombre'];

            if (isset($tarifaEnError['tarifa']['edadMin'])){
                $tarifaEnErrorDisplay .= ' - min: ' . $tarifaEnError['tarifa']['edadMin'];
            }
            if (isset($tarifaEnError['tarifa']['edadMax'])){
                $tarifaEnErrorDisplay .= ' - max: ' . $tarifaEnError['tarifa']['edadMax'];
            }
            if (isset($tarifaEnError['tarifa']['tipoPaxNombre'])){
                $tarifaEnErrorDisplay .= ' - tipo: ' . $tarifaEnError['tarifa']['tipoPaxNombre'];
            }

            if (!empty($tarifasdisplay)){
                $tarifaEnErrorDisplay .= ' - clasificación actual: ' . $tarifasdisplay;
            }


            $this->mensaje = sprintf('Hay tarifas que no pudieron ser clasificadas despues de %d ejecuciones, revise: %s.', $ejecucion, $tarifaEnErrorDisplay);
        }
    }

    private function modificarClasificacion(array &$clase, int $voterIndex): void
    {
        $temp = $this->tarifasClasificadas[$voterIndex];
        $edadMaxima = $this->edadMax;
        $edadMinima = $this->edadMin;

        if(isset($this->tarifasClasificadas[$voterIndex]['edadMin'])){
            $edadMinima = $this->tarifasClasificadas[$voterIndex]['edadMin'];
        }
        if(isset($this->tarifasClasificadas[$voterIndex]['edadMax'])){
            $edadMaxima = $this->tarifasClasificadas[$voterIndex]['edadMax'];
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
            $temp['tipoPaxTitulo'] = $clase['tipoPaxTitulo'];
        }

        $temp['tipo'] = $clase['tipo'];
        $temp['cantidad'] = $clase['cantidad'];
        $temp['cantidadRestante'] = $clase['cantidad'];

        if($clase['cantidad'] == $this->tarifasClasificadas[$voterIndex]['cantidad']){
            $this->tarifasClasificadas[$voterIndex] = $temp;
        }elseif($clase['cantidad'] < $this->tarifasClasificadas[$voterIndex]['cantidad']){
            $this->tarifasClasificadas[] = $temp;
            $this->tarifasClasificadas[$voterIndex]['cantidad'] = $this->tarifasClasificadas[$voterIndex]['cantidad'] - $clase['cantidad'];
            $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] = $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] - $clase['cantidad'];
        }else{
            //solo modifico tipo
            if(isset($clase['edadMin']) && $clase['edadMin'] > $edadMinima){
                $this->tarifasClasificadas[$voterIndex]['edadMin'] = $clase['edadMin'];
            }

            if(isset($clase['edadMax']) && $clase['edadMax'] < $edadMaxima){
                $this->tarifasClasificadas[$voterIndex]['edadMax'] = $clase['edadMax'];
            }

            if($clase['tipoPaxId'] != 0){
                $this->tarifasClasificadas[$voterIndex]['tipoPaxId'] = $clase['tipoPaxId'];
                $this->tarifasClasificadas[$voterIndex]['tipoPaxNombre'] = $clase['tipoPaxNombre'];
                $this->tarifasClasificadas[$voterIndex]['tipoPaxTitulo'] = $clase['tipoPaxTitulo'];
            }
        }
    }

    private function match(array &$clase, int $voterIndex, bool $tituloPersistente = false): void
    {
        if($tituloPersistente === true){
            //si hubiera ya un titulo lo concatenamos
            if(isset($this->tarifasClasificadas[$voterIndex]['tituloPersistente'])){
                $this->tarifasClasificadas[$voterIndex]['tituloPersistente'] = sprintf('%s %s', $this->tarifasClasificadas[$voterIndex]['tituloPersistente'], $clase['tituloONombre']);
            }else{
                $this->tarifasClasificadas[$voterIndex]['tituloPersistente'] = $clase['tituloONombre'];
            }
        }

        if($clase['cantidad'] == $this->tarifasClasificadas[$voterIndex]['cantidadRestante']){
            $clase['cantidad'] = 0;
            $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] = 0;
            $this->tarifasClasificadas[$voterIndex]['tarifas'][] = $clase['tarifa'];
        }elseif($clase['cantidad'] > $this->tarifasClasificadas[$voterIndex]['cantidadRestante']){
            $clase['cantidad'] = $clase['cantidad'] - $this->tarifasClasificadas[$voterIndex]['cantidadRestante'];
            $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] = 0;
            $this->tarifasClasificadas[$voterIndex]['tarifas'][] = $clase['tarifa'];
        }else{ //todo encontrar cuando se usa esto
            $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] = $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] - $clase['cantidad'];
            $clase['cantidad'] = 0;
            $this->tarifasClasificadas[$voterIndex]['tarifas'][] = $clase['tarifa'];
        }
        unset($clase['tarifa']['cantidad']);
        unset($clase['tarifa']['montototal']);
    }

    private function voter(array $clase): int
    {
        $clasificacion = $this->tarifasClasificadas;

        $voterArray = [];

        foreach($clasificacion as $keyTarifa => $tarifaClasificada):

            $voterArray[$keyTarifa] = 0;

            if(!isset($tarifaClasificada['edadMin'])){
                $tarifaClasificada['edadMin'] = $this->edadMin;
            }

            if(!isset($tarifaClasificada['edadMax'])){
                $tarifaClasificada['edadMax'] = $this->edadMax;
            }

            if(!isset($clase['edadMin'])){
                $clase['edadMin'] = $this->edadMin;
            }

            if(!isset($clase['edadMax'])){
                $clase['edadMax'] = $this->edadMax;
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
                $voterArray[$keyTarifa] += 0.1;

                if($clase['edadMin'] == $tarifaClasificada['edadMin']){
                    $voterArray[$keyTarifa] += 1.5;
                }else{
                    $voterArray[$keyTarifa] += 1 / abs($clase['edadMin'] - $tarifaClasificada['edadMin']);
                }

                if($clase['edadMax'] == $tarifaClasificada['edadMax']){
                    $voterArray[$keyTarifa] += 1.5;
                }else{
                    $voterArray[$keyTarifa] += 1 / abs($clase['edadMax'] - $tarifaClasificada['edadMax']);
                }

                if($tarifaClasificada['cantidad'] == $clase['cantidad']){
                    $voterArray[$keyTarifa] += 0.5;
                }
            }
        endforeach;

        if(empty($voterArray) || max($voterArray) <= 0 ){
            return -1;
        }

        return array_search(max($voterArray), $voterArray); //retornamos el mas alto
    }

    public function resumirTarifas(): void
    {
        //el bucle esta pasado por referencia!!!!!
        foreach($this->tarifasClasificadas as &$clase):

            foreach($clase['tarifas'] as $tarifa):
                $clase['resumen'][$tarifa['tipoTarId']]['tipoTarNombre'] = $tarifa['tipoTarNombre'];
                $clase['resumen'][$tarifa['tipoTarId']]['tipoTarTitulo'] = $tarifa['tipoTarTitulo'];
                $clase['resumen'][$tarifa['tipoTarId']]['tipoTarListacolor'] = $tarifa['tipoTarListacolor'];
                $clase['resumen'][$tarifa['tipoTarId']]['tipoTarOculto'] = $tarifa['tipoTarOculto'];

                $this->resumenDeClasificado[$tarifa['tipoTarId']]['nombre'] = $tarifa['tipoTarNombre'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['titulo'] = $tarifa['tipoTarTitulo'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['listacolor'] = $tarifa['tipoTarListacolor'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['oculto'] = $tarifa['tipoTarOculto'];

                if(!isset($this->resumenDeClasificado[$tarifa['tipoTarId']]['montosoles'])){
                    $this->resumenDeClasificado[$tarifa['tipoTarId']]['montosoles'] = 0;
                }

                $this->resumenDeClasificado[$tarifa['tipoTarId']]['montosoles'] += $tarifa['montosoles'] * $clase['cantidad'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['montosoles'] = number_format((float)$this->resumenDeClasificado[$tarifa['tipoTarId']]['montosoles'], '2', '.', '');

                if(!isset($this->resumenDeClasificado[$tarifa['tipoTarId']]['montodolares'])){
                    $this->resumenDeClasificado[$tarifa['tipoTarId']]['montodolares'] = 0;
                }

                $this->resumenDeClasificado[$tarifa['tipoTarId']]['montodolares'] += $tarifa['montodolares'] * $clase['cantidad'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['montodolares'] = number_format((float)$this->resumenDeClasificado[$tarifa['tipoTarId']]['montodolares'], '2', '.', '');

                if(!isset($this->resumenDeClasificado[$tarifa['tipoTarId']]['ventasoles'])){
                    $this->resumenDeClasificado[$tarifa['tipoTarId']]['ventasoles'] = 0;
                }

                $this->resumenDeClasificado[$tarifa['tipoTarId']]['ventasoles'] += $tarifa['ventasoles'] * $clase['cantidad'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['ventasoles'] = number_format((float)$this->resumenDeClasificado[$tarifa['tipoTarId']]['ventasoles'], '2', '.', '');

                if(!isset($this->resumenDeClasificado[$tarifa['tipoTarId']]['ventadolares'])){
                    $this->resumenDeClasificado[$tarifa['tipoTarId']]['ventadolares'] = 0;
                }

                $this->resumenDeClasificado[$tarifa['tipoTarId']]['ventadolares'] += $tarifa['ventadolares'] * $clase['cantidad'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['ventadolares'] = number_format((float)$this->resumenDeClasificado[$tarifa['tipoTarId']]['ventadolares'], '2', '.', '');

                //se sobreescriben hasta el final del bucle
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['adelantosoles'] = $this->resumenDeClasificado[$tarifa['tipoTarId']]['ventasoles'] * $this->cotizacion->getAdelanto() / 100;
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['adelantosoles'] = number_format((float)$this->resumenDeClasificado[$tarifa['tipoTarId']]['adelantosoles'], '2', '.', '');

                $this->resumenDeClasificado[$tarifa['tipoTarId']]['adelantodolares'] = $this->resumenDeClasificado[$tarifa['tipoTarId']]['ventadolares'] * $this->cotizacion->getAdelanto() / 100;
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['adelantodolares'] = number_format((float)$this->resumenDeClasificado[$tarifa['tipoTarId']]['adelantodolares'], '2', '.', '');

                $this->resumenDeClasificado[$tarifa['tipoTarId']]['gananciasoles'] = $this->resumenDeClasificado[$tarifa['tipoTarId']]['ventasoles'] - $this->resumenDeClasificado[$tarifa['tipoTarId']]['montosoles'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['gananciasoles'] = number_format((float)$this->resumenDeClasificado[$tarifa['tipoTarId']]['gananciasoles'], '2', '.', '');

                $this->resumenDeClasificado[$tarifa['tipoTarId']]['gananciadolares'] = $this->resumenDeClasificado[$tarifa['tipoTarId']]['ventadolares'] - $this->resumenDeClasificado[$tarifa['tipoTarId']]['montodolares'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['gananciadolares'] = number_format((float)$this->resumenDeClasificado[$tarifa['tipoTarId']]['gananciadolares'], '2', '.', '');

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

            endforeach;

            ksort($clase['resumen']);

        endforeach;

        //destruimos la referencia
        unset($clase);

        ksort($this->resumenDeClasificado);
    }

}