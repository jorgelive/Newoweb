<?php
namespace App\EventListener;

use App\Entity\CotizacionCotizacion;
use App\Service\CotizacionProceso;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Symfony\Component\HttpFoundation\RequestStack;

class CotizacionCotizacionDoctrineEventListener
{

    private CotizacionProceso $cotizacionProceso;

    private RequestStack $requestStack;

    public function __construct(CotizacionProceso $cotizacionProceso, RequestStack $requestStack)
    {
        $this->cotizacionProceso = $cotizacionProceso;

        $this->requestStack = $requestStack;

    }

    public function prePersist(PrePersistEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof CotizacionCotizacion){
            $entity->setToken(mt_rand());
        }
    }

    public function postPersist(PostPersistEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof CotizacionCotizacion){
            //De haber error los mensajes iran al flashblag
            $this->cotizacionProceso->procesar($entity->getId());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args)
    {
        $entity = $args->getObject();

        if($entity instanceof CotizacionCotizacion){
            //De haber error los mensajes iran al flashblag
            $this->cotizacionProceso->procesar($entity->getId());
        }
    }
}