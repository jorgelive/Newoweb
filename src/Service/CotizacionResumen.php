<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\CotizacionCotizacion;
use App\Entity\MaestroTipocambio;

class CotizacionResumen
{
    private RequestStack $requestStack;
    private EntityManagerInterface $em;
    private TranslatorInterface $translator;
    private MensajeProveedor $mensajeProveedor;
    private CotizacionCotizacion $cotizacion;

    private int $edadMin = 0;
    private int $edadMax = 120;

    private array $datosTabs;
    private array $datosCotizacion;

    private array $clasificacionTarifas = [];
    private array $resumendeClasificado = [];

    private string $mensaje;

    private TipocambioManager $tipocambioManager;
    private MaestroTipocambio $tipocambio;
    private CotizacionItinerario $cotizacionItinerario;
    private CotizacionIncluye $cotizacionIncluye;
    private CotizacionClasificador $cotizacionClasificador;
    private CotizacionAgenda $cotizacionAgenda;

    function __construct(EntityManagerInterface $em,
                         TipocambioManager $tipocambioManager,
                         RequestStack $requestStack,
                         TranslatorInterface $translator,
                         MensajeProveedor $mensajeProveedor,
                         CotizacionItinerario $cotizacionItinerario,
                         CotizacionIncluye $cotizacionIncluye,
                         CotizacionClasificador $cotizacionClasificador,
                         CotizacionAgenda $cotizacionAgenda
    )
    {
        $this->em = $em;
        $this->tipocambioManager = $tipocambioManager;
        $this->requestStack = $requestStack;
        $this->translator = $translator;
        $this->mensajeProveedor = $mensajeProveedor;
        $this->cotizacionItinerario = $cotizacionItinerario;
        $this->cotizacionIncluye = $cotizacionIncluye;
        $this->cotizacionClasificador = $cotizacionClasificador;
        $this->cotizacionAgenda = $cotizacionAgenda;
    }

    function procesar(int $id): bool
    {
        $cotizacion = $this->em
            ->getRepository('App\Entity\CotizacionCotizacion')
            ->find($id);

        if(!$cotizacion){
            $this->mensaje = sprintf('No se puede encontrar el objeto con el identificador : %s', $id);
            return false;
        }

        $this->cotizacion = $cotizacion;

        $this->tipocambio = $this->tipocambioManager->getTipodecambio($cotizacion->getFecha());

        if(empty($this->tipocambio->getId())){
            $this->mensaje = sprintf('No se puede obtener el tipo de cambio del dia %s.',  $cotizacion->getFecha()->format('Y-m-d') );
            return false;
        }

        //datos generales del encabezado
        $datosCotizacion['tipocambio'] = $this->tipocambio;

        if($this->cotizacionClasificador->clasificar($cotizacion, $this->tipocambio)){
            $datosTabs['tarifasClasificadas'] = $this->cotizacionClasificador->getTarifasClasificadas();
            $datosTabs['resumenDeClasificado'] = $this->cotizacionClasificador->getResumenDeClasificado();
        }else{
            $this->mensaje = $this->cotizacionClasificador->getMensaje();
            return false;
        }
        $datosTabs['itinerarios'] = $this->cotizacionItinerario->getItinerario($cotizacion);
        $datosTabs['proveedores'] = $this->mensajeProveedor->getMensajesParaCotizacion($id);//$tempProveedores;
        $datosTabs['incluye'] = $this->cotizacionIncluye->getDatos($cotizacion);
        $datosTabs['agenda'] = $this->cotizacionAgenda->getAgenda($cotizacion);

        $this->datosCotizacion = $datosCotizacion;

        $this->datosTabs = $datosTabs;

        return true;
    }

    public function getDatosTabs(): array
    {
        return $this->datosTabs;
    }

    public function getDatosCotizacion(): array
    {
        return $this->datosCotizacion;
    }

    public function getMensaje(): string
    {
        return $this->mensaje;
    }

}