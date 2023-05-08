<?php

namespace App\Service;


use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\CotizacionCotizacion;

class CotizacionAgenda
{

    private TranslatorInterface $translator;
    private CotizacionItinerario $cotizacionItinerario;


    function __construct(TranslatorInterface $translator, CotizacionItinerario $cotizacionItinerario)
    {
        $this->translator = $translator;
        $this->cotizacionItinerario = $cotizacionItinerario;
    }


    public function getAgenda(CotizacionCotizacion $cotizacion): array
    {
        $datos = [];
        if($cotizacion->getCotservicios()->count() > 0){
            foreach($cotizacion->getCotservicios() as $servicio):
                if($servicio->getCotcomponentes()->count() > 0){
                    foreach($servicio->getCotcomponentes() as $componente):
                        if($componente->getComponente()->getTipocomponente()->isAgendable()){

                            $tempArrayComponente = [];
                            $tempArrayComponente['tituloItinerario'] = $this->cotizacionItinerario->getTituloItinerario($componente->getFechahorainicio(), $servicio);
                            $tempArrayComponente['nombre'] = $componente->getComponente()->getNombre();
                            $tempArrayComponente['tipoComponente'] = $componente->getComponente()->getTipocomponente()->getNombre();
                            $tempArrayComponente['fechahorainicio'] = $componente->getFechahorainicio();
                            $tempArrayComponente['fechahorafin'] = $componente->getFechahorafin();

//la presencia del titulo sera un indicador para mostrarlo o no en horario ya que el item array componente es interno para los demas procesos
                            $tempArrayItem=[];
                            if($componente->getComponente()->getComponenteitems()->count() > 0){
                                foreach($componente->getComponente()->getComponenteitems() as $item){
                                    $tempArrayItem[] = $item->getTitulo();
                                }
                                $tempArrayComponente['titulo'] = implode(', ',  $tempArrayItem);
                            }

//Solo si tiene título lo pongo en horario
                            if(isset($tempArrayComponente['titulo'])){
                                $datos['componentes'][] = $tempArrayComponente;
                            }

                        }
                    endforeach;
                }
            endforeach;
        }

        return $datos;

    }
}