<?php

namespace App\Oweb\Service;

use App\Entity\MaestroTipocambio;
use App\Oweb\Entity\CotizacionCotizacion;
use App\Service\TipocambioManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class CotizacionProceso
{
    private RequestStack $requestStack;
    private EntityManagerInterface $entityManager;
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
    private CotizacionResumen $cotizacionResumen;
    private CotizacionClasificador $cotizacionClasificador;

    function __construct(EntityManagerInterface $entityManager,
                         TipocambioManager $tipocambioManager,
                         RequestStack $requestStack,
                         TranslatorInterface $translator,
                         MensajeProveedor $mensajeProveedor,
                         CotizacionItinerario $cotizacionItinerario,
                         CotizacionIncluye $cotizacionIncluye,
                         CotizacionResumen $cotizacionResumen,
                         CotizacionClasificador $cotizacionClasificador,
    )
    {
        $this->entityManager = $entityManager;
        $this->tipocambioManager = $tipocambioManager;
        $this->requestStack = $requestStack;
        $this->translator = $translator;
        $this->mensajeProveedor = $mensajeProveedor;
        $this->cotizacionItinerario = $cotizacionItinerario;
        $this->cotizacionIncluye = $cotizacionIncluye;
        $this->cotizacionResumen = $cotizacionResumen;
        $this->cotizacionClasificador = $cotizacionClasificador;
    }

    function procesar(int $id): bool
    {
        $cotizacionEncontrada = $this->entityManager
            ->getRepository(CotizacionCotizacion::class)
            ->find($id);

        if(!$cotizacionEncontrada){
            $this->requestStack->getSession()->getFlashBag()->add('error', sprintf('No se puede encontrar el objeto con el identificador : %s', $id));
            return false;
        }

        $this->cotizacion = $cotizacionEncontrada;

        $diaAUsar = $this->cotizacion->getFecha();

        //si es plantilla sacamos es tipo de cambio del dia
        if($this->cotizacion->getFile()->isCatalogo() === true){
            $diaAUsar = new \DateTime('today');
        }

        $this->tipocambio = $this->tipocambioManager->getTipodecambio($diaAUsar);

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

        $datosTabs['itinerarios'] = $this->cotizacionItinerario->getItinerarioConAgenda($this->cotizacion);
        $datosTabs['proveedores'] = $this->mensajeProveedor->getMensajesParaCotizacion($id);
        $datosTabs['incluye'] = $this->cotizacionIncluye->getDatos($this->cotizacion);
        $datosTabs['resumen'] = $this->cotizacionResumen->getDatos($this->cotizacion);

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