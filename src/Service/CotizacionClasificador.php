<?php

namespace App\Service;

use App\Entity\MaestroMoneda;
use App\Entity\MaestroTipocambio;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\CotizacionCotizacion;

class CotizacionClasificador
{

    private TranslatorInterface $translator;
    private CotizacionCotizacion $cotizacion;
    private RequestStack $requestStack;

    private int $edadMin = 0;
    private int $edadMax = 120;

    private array $tarifasClasificadas = [];
    //Es el resumen final de todos los pasajeros de tarifas costos v netas por tipo de tarifa incluido no incluido, etc 
    private array $resumenDeClasificado = [];

    private CotizacionItinerario $cotizacionItinerario;

    function __construct(TranslatorInterface $translator, CotizacionItinerario $cotizacionItinerario, RequestStack $requestStack)
    {
        $this->translator = $translator;
        $this->cotizacionItinerario = $cotizacionItinerario;
        $this->requestStack = $requestStack;
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
                                        ), 2, '.', '');
                                    $tempArrayTarifa['cantidad'] = (int)($this->cotizacion->getNumeropasajeros());
                                    $tempArrayTarifa['prorrateado'] = true;

                                }else{
                                    $tempArrayTarifa['montounitario'] = number_format(
                                        (float)($tarifa->getMonto() * $componente->getCantidad()
                                        ), 2, '.', '');
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
                                //dólares = 2
                                if($tarifa->getMoneda()->getId() == MaestroMoneda::DB_VALOR_DOLAR){
                                    $tempArrayTarifa['montosoles'] = number_format((float)($tempArrayTarifa['montounitario'] * (float)$tipocambio->getPromedio()), 2, '.', '');
                                    $tempArrayTarifa['montodolares'] = $tempArrayTarifa['montounitario'];
                                }elseif($tarifa->getMoneda()->getId() == MaestroMoneda::DB_VALOR_SOL){
                                    $tempArrayTarifa['montosoles'] = $tempArrayTarifa['montounitario'];
                                    $tempArrayTarifa['montodolares'] = number_format((float)($tempArrayTarifa['montounitario'] / (float)$tipocambio->getPromedio()), 2, '.', '');
                                }else{
                                    $this->requestStack->getSession()->getFlashBag()->add('error', 'La aplicación solo puede utilizar Soles y dólares en las tarifas.');

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
                                $tempArrayTarifa['tipoTarOcultoenresumen'] = $tarifa->getTipotarifa()->isOcultoenresumen();

                                $tempArrayComponente['tarifas'][] = $tempArrayTarifa;
                                unset($tempArrayTarifa);

                            endforeach;

//punto de ingreso a la clasificacion $this->obtenerTarifasComponente >>> $this->procesarTarifa  >>> $this->modificarClasificacion
                            //suspendemos la ejecución si encontramos error
                            if(!$this->obtenerTarifasComponente($tempArrayComponente['tarifas'], $this->cotizacion->getNumeropasajeros())){
                                return false;
                            }

                            unset($tempArrayComponente);

                            //no he sumado prorrateados puede ir en blanco para el caso de que solo exista prorrateado y cuadre con la cantidad de pasajeros
                            if($cantidadComponente > 0 && $cantidadComponente != $cotizacion->getNumeropasajeros()){
                                $this->requestStack->getSession()->getFlashBag()->add('error', sprintf('La cantidad de pasajeros por componente no coincide con la cantidad de pasajeros en %s %s %s.', $servicio->getFechahorainicio()->format('Y/m/d'), $servicio->getServicio()->getNombre(), $componente->getComponente()->getNombre()));

                                return false;
                            }
                            unset($cantidadComponente);

                        }else{
                            $this->requestStack->getSession()->getFlashBag()->add('error', sprintf('El componente no tiene tarifa en %s %s %s.', $servicio->getFechahorainicio()->format('Y/m/d'), $servicio->getServicio()->getNombre(), $componente->getComponente()->getNombre()));

                            return false;
                        }
                    endforeach;
                }else{
                    $this->requestStack->getSession()->getFlashBag()->add('error', sprintf('El servicio no tiene componente en %s %s.', $servicio->getFechahorainicio()->format('Y/m/d'), $servicio->getServicio()->getNombre()));

                    return false;
                }
            endforeach;
        }else{
            $this->requestStack->getSession()->getFlashBag()->add('error', 'La cotización no tiene servicios.');

            return false;
        }
//Hacemos disponible los datos de la cotización para el resumen de las tarifas.

        if(empty($this->tarifasClasificadas)) {
            $this->requestStack->getSession()->getFlashBag()->add('error', 'No se pudieron clasificar las tarifas.');

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

    public function getTarifasClasificadas(): array
    {
        return $this->tarifasClasificadas;
    }

    public function getResumenDeClasificado(): array
    {
        return $this->resumenDeClasificado;
    }

    private function obtenerTarifasComponente(array $componente, int $cantidadTotalPasajeros): bool
    {
        $tarifasParaClasificar = [];
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

//el título persistente es para mostrar el nombre de la tarifa en la clasificación por rangos en caso por ejemplo de comunidad andina
            if(isset($tarifa['titulo'])){
                $temp['tituloOTipoTarifa'] = $tarifa['titulo'];
            }else{
                //fallback posiblemente el motivo sera un opcional
                $temp['tituloOTipoTarifa'] = $tarifa['tipoTarTitulo'];
            }
            $temp['tituloPersistente'] = false;
            //si existe
            if(array_search($temp['tipo'], $tiposAux, true) !== false){
                $temp['tituloPersistente'] = true;
            }

            $temp['tarifa'] = $tarifa;
            $tarifasParaClasificar[] = $temp;

            $tiposAux[] = $tipo;

        endforeach;

        if(count($tarifasParaClasificar) > 0){
            if($this->procesarTarifa($tarifasParaClasificar, $cantidadTotalPasajeros)){
                $this->resetClasificacionTarifas();
                return true;
            };
            //Al final de la ejecución la cantidad restante sera la cantidad de la clase,
        }

        return false;
    }

    private function resetClasificacionTarifas(): void
    {
        foreach($this->tarifasClasificadas as &$clase):
            $clase['cantidadRestante'] = $clase['cantidad'];
        endforeach;
        //destruimos la referencia
        unset($clase);
    }

    private function procesarTarifa(array $tarifasParaClasificar, int $cantidadTotalPasajeros): bool
    {

        if(empty($this->tarifasClasificadas)){

            $cantidadTemporal = 0;
            foreach($tarifasParaClasificar as &$tarifaParaClasificar):

                $auxClase = [];
                $auxClase['tipo'] = $tarifaParaClasificar['tipo'];
                $auxClase['cantidad'] = $tarifaParaClasificar['cantidad'];
                $auxClase['cantidadRestante'] = $tarifaParaClasificar['cantidad'];
                $auxClase['tipoPaxId'] = $tarifaParaClasificar['tipoPaxId'];
                $auxClase['tipoPaxNombre'] = $tarifaParaClasificar['tipoPaxNombre'];
                $auxClase['tipoPaxTitulo'] = $tarifaParaClasificar['tipoPaxTitulo'];
                if(isset($tarifaParaClasificar['edadMin'])){
                    $auxClase['edadMin'] = $tarifaParaClasificar['edadMin'];
                }
                if(isset($tarifaParaClasificar['edadMax'])){
                    $auxClase['edadMax'] = $tarifaParaClasificar['edadMax'];
                }

                unset($tarifaParaClasificar['tarifa']['cantidad']);
                unset($tarifaParaClasificar['tarifa']['montototal']);
                if($cantidadTemporal > 0 && $cantidadTotalPasajeros == $tarifaParaClasificar['cantidad']){
                    continue;
                }

                $this->tarifasClasificadas[] = $auxClase;
                $cantidadTemporal += $tarifaParaClasificar['cantidad'];

                if($cantidadTemporal >= $cantidadTotalPasajeros){
                    break;
                }
            endforeach;
            unset($tarifaParaClasificar);
        }

        foreach($tarifasParaClasificar as $keyClase => &$tarifaParaClasificar):

            $ejecucion = 0;
            //paso el array principal para adicionar elemento como esta por referencia
            //es función recursiva
            $this->clasificarTarifas($tarifaParaClasificar, $ejecucion, $tarifaParaClasificar['tituloPersistente']);
            if($tarifaParaClasificar['cantidad'] < 1){
                unset($tarifasParaClasificar[$keyClase]);
            }

        endforeach;
        //destruimos la referencia
        unset($tarifaParaClasificar);
        
        //si después del proceso quedan tarifas sin clasificar muestro error
        if(count($tarifasParaClasificar) > 0){
            $tarifasdisplay = '';
            //hacemos el resumen de las tarifas
            //suponemos que no hay espacio
            $menorCantidadRestante = 0;
            foreach ($this->tarifasClasificadas as $currentTarifa):
                $tarifasdisplayArray = [];
                if(isset($currentTarifa['edadMin'])) {
                    $tarifasdisplayArray[] = 'E min:' . $currentTarifa['edadMin'];
                }
                if(isset($currentTarifa['edadMax'])) {
                    $tarifasdisplayArray[] = 'E max :' . $currentTarifa['edadMax'];
                }
                if(isset($currentTarifa['tipoPaxNombre'])){
                    $tarifasdisplayArray[] = 'tipo: ' . $currentTarifa['tipoPaxNombre'];
                }
                if(isset($currentTarifa['cantidad'])){
                    $tarifasdisplayArray[] = 'cantidad: ' . $currentTarifa['cantidad'];
                }
                if(isset($currentTarifa['cantidadRestante'])){
                    $tarifasdisplayArray[] = 'cantidad restante: ' . $currentTarifa['cantidadRestante'];
                }
                $tarifasdisplay .= '[' . implode(', ', $tarifasdisplayArray) . '] ';
                //
                if ($currentTarifa['cantidadRestante'] > $menorCantidadRestante){
                    $menorCantidadRestante = $currentTarifa['cantidadRestante'];
                }
            endforeach;

            if($menorCantidadRestante == 0){
                $this->requestStack->getSession()->getFlashBag()->add('error', 'No hay espacio en las tarifas, verifique la cantidad total de pasajeros del componente.');
            }else{
                $this->requestStack->getSession()->getFlashBag()->add('error', 'Hay tarifas que no se acomodan a las clases actuales.');
            }

            $tarifaEnError = reset($tarifasParaClasificar);

            $tarifaEnErrorDisplay = $tarifaEnError['tarifa']['nombreServicio']
                . ' - ' . $tarifaEnError['tarifa']['nombreComponente']
                . ' - ' . $tarifaEnError['tarifa']['nombre'];

            if (isset($tarifaEnError['tarifa']['edadMin'])){
                $tarifaEnErrorDisplay .= ' - E min: ' . $tarifaEnError['tarifa']['edadMin'];
            }
            if (isset($tarifaEnError['tarifa']['edadMax'])){
                $tarifaEnErrorDisplay .= ' - E max: ' . $tarifaEnError['tarifa']['edadMax'];
            }
            if (isset($tarifaEnError['tarifa']['tipoPaxNombre'])){
                $tarifaEnErrorDisplay .= ' - tipo: ' . $tarifaEnError['tarifa']['tipoPaxNombre'];
            }

            $tarifaEnErrorDisplay .= ' - cantidad a clasificar: ' . $tarifaEnError['cantidad'];

            $this->requestStack->getSession()->getFlashBag()->add('error', sprintf('No se pudo clasificar: %s.', $tarifaEnErrorDisplay));
            if (!empty($tarifasdisplay)){
                $this->requestStack->getSession()->getFlashBag()->add('error', 'Clasificación actual: ' . $tarifasdisplay);
            }
            return false;
        }

        return true;
    }

    private function clasificarTarifas(array &$tarifaParaClasificar, int $ejecucion, bool $tituloPersistente = false): int
    {
        $ejecucion++;

        $voterIndex = $this->voter($tarifaParaClasificar);

        if($voterIndex < 0
            && $this->cotizacion->getNumeropasajeros() == $tarifaParaClasificar['cantidad']
        ) {
            //si hubieran dos tarifas prorrateadas en el mismo componente
            //entonces es prorrateado y le damos una segunda oportunidad y lo distribuimos.
            foreach ($this->tarifasClasificadas as &$claseTarifa) {
                $claseTarifa['tarifas'][] = $tarifaParaClasificar['tarifa'];
            }
            $tarifaParaClasificar['cantidad'] = 0;
            //eliminamos la referencia
            unset ($claseTarifa);
            return $tarifaParaClasificar['cantidad'];
        }elseif($voterIndex < 0 ){
            //no procesamos
            $this->requestStack->getSession()->getFlashBag()->add('error', 'Existen tarifas que no se puedieron clasificar.');
            return $tarifaParaClasificar['cantidad'];
        }

        if($tituloPersistente === true){
            //si hubiera ya un titulo lo concatenamos
            if(isset($this->tarifasClasificadas[$voterIndex]['tituloPersistente'])){
                $this->tarifasClasificadas[$voterIndex]['tituloPersistente'] = sprintf('%s %s', $this->tarifasClasificadas[$voterIndex]['tituloPersistente'], $tarifaParaClasificar['tituloOTipoTarifa']);
            }else{
                $this->tarifasClasificadas[$voterIndex]['tituloPersistente'] = $tarifaParaClasificar['tituloOTipoTarifa'];
            }
        }

        //copia de tarifa para mofificar y crear una nueva o reemplazar la existente
        $copiaDeTarifaSeleccionada = $this->tarifasClasificadas[$voterIndex];
        $edadMaxima = $this->edadMax;
        $edadMinima = $this->edadMin;

        if(isset($this->tarifasClasificadas[$voterIndex]['edadMin'])){
            $edadMinima = $this->tarifasClasificadas[$voterIndex]['edadMin'];
        }
        if(isset($this->tarifasClasificadas[$voterIndex]['edadMax'])){
            $edadMaxima = $this->tarifasClasificadas[$voterIndex]['edadMax'];
        }

        if(isset($tarifaParaClasificar['edadMin']) && $tarifaParaClasificar['edadMin'] > $edadMinima){
            $copiaDeTarifaSeleccionada['edadMin'] = $tarifaParaClasificar['edadMin'];
        }
        if(isset($tarifaParaClasificar['edadMax']) && $tarifaParaClasificar['edadMax'] < $edadMaxima){
            $copiaDeTarifaSeleccionada['edadMax'] = $tarifaParaClasificar['edadMax'];
        }
        //cambio de genérico a nacionalidad
        if($tarifaParaClasificar['tipoPaxId'] != 0){
            $copiaDeTarifaSeleccionada['tipoPaxId'] = $tarifaParaClasificar['tipoPaxId'];
            $copiaDeTarifaSeleccionada['tipoPaxNombre'] = $tarifaParaClasificar['tipoPaxNombre'];
            $copiaDeTarifaSeleccionada['tipoPaxTitulo'] = $tarifaParaClasificar['tipoPaxTitulo'];
        }

        $copiaDeTarifaSeleccionada['tipo'] = $tarifaParaClasificar['tipo'];
        $copiaDeTarifaSeleccionada['cantidad'] = $tarifaParaClasificar['cantidad'];
        $copiaDeTarifaSeleccionada['cantidadRestante'] = $tarifaParaClasificar['cantidad'];

        if($tarifaParaClasificar['cantidad'] == $this->tarifasClasificadas[$voterIndex]['cantidad']){
            //la cantidad del proceso actual, no queda nada
            $tarifaParaClasificar['cantidad'] = 0;

            //reemplazamos con la version modificada
            $copiaDeTarifaSeleccionada['cantidadRestante'] = 0;
            $copiaDeTarifaSeleccionada['tarifas'][] = $tarifaParaClasificar['tarifa'];
            $this->tarifasClasificadas[$voterIndex] = $copiaDeTarifaSeleccionada;


        }elseif($tarifaParaClasificar['cantidad'] < $this->tarifasClasificadas[$voterIndex]['cantidad']){
            //Creamos una nueva con la version modificada
            $copiaDeTarifaSeleccionada['cantidadRestante'] = 0;
            $copiaDeTarifaSeleccionada['tarifas'][] = $tarifaParaClasificar['tarifa'];
            $this->tarifasClasificadas[] = $copiaDeTarifaSeleccionada;

            //a lo que resta le quitamos la cantidad
            $this->tarifasClasificadas[$voterIndex]['cantidad'] = $this->tarifasClasificadas[$voterIndex]['cantidad'] - $tarifaParaClasificar['cantidad'];
            $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] = $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] - $tarifaParaClasificar['cantidad'];

            //la cantidad del proceso actual, no queda nada (lo devolvemos al final porque se usa)
            $tarifaParaClasificar['cantidad'] = 0;

        }elseif($tarifaParaClasificar['cantidad'] > $this->tarifasClasificadas[$voterIndex]['cantidad']){
            //lo que quedara después de clasificar
            $tarifaParaClasificar['cantidad'] = $tarifaParaClasificar['cantidad'] - $this->tarifasClasificadas[$voterIndex]['cantidadRestante'];

            //solo ajusto los valores
            if(isset($tarifaParaClasificar['edadMin']) && $tarifaParaClasificar['edadMin'] > $edadMinima){
                $this->tarifasClasificadas[$voterIndex]['edadMin'] = $tarifaParaClasificar['edadMin'];
            }

            if(isset($tarifaParaClasificar['edadMax']) && $tarifaParaClasificar['edadMax'] < $edadMaxima){
                $this->tarifasClasificadas[$voterIndex]['edadMax'] = $tarifaParaClasificar['edadMax'];
            }

            if($tarifaParaClasificar['tipoPaxId'] != 0){
                $this->tarifasClasificadas[$voterIndex]['tipoPaxId'] = $tarifaParaClasificar['tipoPaxId'];
                $this->tarifasClasificadas[$voterIndex]['tipoPaxNombre'] = $tarifaParaClasificar['tipoPaxNombre'];
                $this->tarifasClasificadas[$voterIndex]['tipoPaxTitulo'] = $tarifaParaClasificar['tipoPaxTitulo'];
            }

            $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] = 0;
            $this->tarifasClasificadas[$voterIndex]['tarifas'][] = $tarifaParaClasificar['tarifa'];
        }

        if($tarifaParaClasificar['cantidad'] > 0 && $ejecucion < 10){
            $tarifaParaClasificar['cantidad'] = $this->clasificarTarifas($tarifaParaClasificar, $ejecucion, $tituloPersistente);
        }

        return $tarifaParaClasificar['cantidad'];
    }

    private function voter(array $tarifaParaClasificar): int
    {
        $clasificacionActual = $this->tarifasClasificadas;

        $voterArray = [];

        foreach($clasificacionActual as $keyTarifa => $tarifaClasificada):

            $voterArray[$keyTarifa] = 0;

            //completamos con los valores por defecto
            if(!isset($tarifaClasificada['edadMin'])){
                $tarifaClasificada['edadMin'] = $this->edadMin;
            }

            if(!isset($tarifaClasificada['edadMax'])){
                $tarifaClasificada['edadMax'] = $this->edadMax;
            }

            if(!isset($tarifaParaClasificar['edadMin'])){
                $tarifaParaClasificar['edadMin'] = $this->edadMin;
            }

            if(!isset($tarifaParaClasificar['edadMax'])){
                $tarifaParaClasificar['edadMax'] = $this->edadMax;
            }

            if(($tarifaClasificada['cantidadRestante'] > 0) &&
                (
                    $tarifaParaClasificar['tipoPaxId'] == $tarifaClasificada['tipoPaxId'] ||
                    $tarifaParaClasificar['tipoPaxId'] == 0 ||
                    $tarifaClasificada['tipoPaxId'] == 0
                )
                && $tarifaParaClasificar['edadMin'] <= $tarifaClasificada['edadMax']
                && $tarifaParaClasificar['edadMax'] >= $tarifaClasificada['edadMin']

            ){
                $voterArray[$keyTarifa] += 0.1;
                if($tarifaParaClasificar['edadMin'] == $tarifaClasificada['edadMin']){
                    $voterArray[$keyTarifa] += 1;
                }else{
                    $voterArray[$keyTarifa] += 1 / abs($tarifaParaClasificar['edadMin'] - $tarifaClasificada['edadMin']);
                }

                if($tarifaParaClasificar['edadMax'] == $tarifaClasificada['edadMax']){
                    $voterArray[$keyTarifa] += 1;
                }else{
                    $voterArray[$keyTarifa] += 1 / abs($tarifaParaClasificar['edadMax'] - $tarifaClasificada['edadMax']);
                }

                if($tarifaClasificada['cantidad'] == $tarifaParaClasificar['cantidad']){
                    $voterArray[$keyTarifa] += 0.3;
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
                $clase['resumen'][$tarifa['tipoTarId']]['tipoTarOcultoenresumen'] = $tarifa['tipoTarOcultoenresumen'];

                $this->resumenDeClasificado[$tarifa['tipoTarId']]['nombre'] = $tarifa['tipoTarNombre'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['titulo'] = $tarifa['tipoTarTitulo'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['listacolor'] = $tarifa['tipoTarListacolor'];
                $this->resumenDeClasificado[$tarifa['tipoTarId']]['ocultoenresumen'] = $tarifa['tipoTarOcultoenresumen'];

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