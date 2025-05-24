<?php
namespace App\EventListener;

use App\Entity\CotizacionCotizacion;
use App\Service\CotizacionItinerario;
use App\Service\CotizacionProceso;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Symfony\Component\HttpFoundation\RequestStack;

class CotizacionCotizacionDoctrineEventListener
{

    private CotizacionProceso $cotizacionProceso;

    private RequestStack $requestStack;

    private CotizacionItinerario $cotizacionItinerario;



    public function __construct(CotizacionProceso $cotizacionProceso, RequestStack $requestStack, CotizacionItinerario $cotizacionItinerario)
    {
        $this->cotizacionProceso = $cotizacionProceso;

        $this->requestStack = $requestStack;

        $this->cotizacionItinerario = $cotizacionItinerario;
    }

    public function prePersist(PrePersistEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof CotizacionCotizacion){
            $entity->setToken(mt_rand());
            $entity->setTokenoperaciones(mt_rand());
        }
    }

    public function postPersist(PostPersistEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof CotizacionCotizacion){
            //Procesar para mostrar los errores, De haber error los mensajes iran al flashbag
            $this->cotizacionProceso->procesar($entity->getId());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args)
    {
        $entity = $args->getObject();

        if($entity instanceof CotizacionCotizacion){
            //Procesar para mostrar los errores, De haber error los mensajes iran al flashblag
            $this->cotizacionProceso->procesar($entity->getId());
        }
    }

    public function postLoad(PostLoadEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof CotizacionCotizacion){
            foreach ($entity->getCotServicios() as $servicio) :
                $mainFoto = $this->cotizacionItinerario->getMainPhoto($servicio);
                if(!empty($mainFoto)){
                    $entity->addPortadafoto($mainFoto);
                }
            endforeach;
        }
    }
}