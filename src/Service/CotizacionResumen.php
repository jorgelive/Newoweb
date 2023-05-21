<?php

namespace App\Service;

use App\Entity\CotizacionEstadocotizacion;
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

    private TipocambioManager $tipocambioManager;
    private MaestroTipocambio $tipocambio;
    private CotizacionItinerario $cotizacionItinerario;
    private CotizacionIncluye $cotizacionIncluye;
    private CotizacionClasificador $cotizacionClasificador;
    private CotizacionAgenda $cotizacionAgenda;

    function __construct(EntityManagerInterface $entityManager,
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
        $this->entityManager = $entityManager;
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
        $cotizacionEncontrada = $this->entityManager
            ->getRepository('App\Entity\CotizacionCotizacion')
            ->find($id);

        if(!$cotizacionEncontrada){
            $this->requestStack->getSession()->getFlashBag()->add('error', sprintf('No se puede encontrar el objeto con el identificador : %s', $id));

            return false;
        }

        $this->cotizacion = $cotizacionEncontrada;

        //si es plantilla sacamos es tipo de cambio del dia
        if($this->cotizacion->getEstadocotizacion()->getId() == CotizacionEstadocotizacion::DB_VALOR_PLANTILLA){
            $this->tipocambio = $this->tipocambioManager->getTipodecambio(new \DateTime('today'));
        }else{
            $this->tipocambio = $this->tipocambioManager->getTipodecambio($this->cotizacion->getFecha());
        }

        if(empty($this->tipocambio->getId())){
            $this->requestStack->getSession()->getFlashBag()->add('error', sprintf('No se puede obtener el tipo de cambio del dia %s.',  $this->cotizacion->getFecha()->format('Y-m-d')));
            return false;
        }

        $datosCotizacion['tipocambio'] = $this->tipocambio;

        if($this->cotizacionClasificador->clasificar($this->cotizacion, $this->tipocambio)){
            $datosTabs['tarifasClasificadas'] = $this->cotizacionClasificador->getTarifasClasificadas();
            $datosTabs['resumenDeClasificado'] = $this->cotizacionClasificador->getResumenDeClasificado();
        }else{
            //los mensajes de error ya estan el flash bag
            return false;
        }

        $datosTabs['itinerarios'] = $this->cotizacionItinerario->getItinerario($this->cotizacion);
        $datosTabs['proveedores'] = $this->mensajeProveedor->getMensajesParaCotizacion($id);
        $datosTabs['incluye'] = $this->cotizacionIncluye->getDatos($this->cotizacion);
        $datosTabs['agenda'] = $this->cotizacionAgenda->getAgenda($this->cotizacion);

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

}