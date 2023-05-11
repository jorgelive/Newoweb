<?php
namespace App\EventListener;

use App\Entity\CotizacionCotizacion;
use App\Service\CotizacionResumen;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Symfony\Component\HttpFoundation\RequestStack;

class CotizacionCotizacionDoctrineEventListener
{

    private CotizacionResumen $cotizacionResumen;

    private RequestStack $requestStack;

    public function __construct(CotizacionResumen $cotizacionResumen, RequestStack $requestStack)
    {
        $this->cotizacionResumen = $cotizacionResumen;

        $this->requestStack = $requestStack;

    }

    public function prePersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if($entity instanceof CotizacionCotizacion){
            if(!$entity->getToken()) {
                $entity->setToken(mt_rand());
            }

        }
    }

    public function postPersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if($entity instanceof CotizacionCotizacion){
            if(!$this->cotizacionResumen->procesar($entity->getId())){
                $this->requestStack->getSession()->getFlashBag()->add('error', $this->cotizacionResumen->getMensaje());
            }
        }
    }

    public function postUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();

        if($entity instanceof CotizacionCotizacion){
            if(!$this->cotizacionResumen->procesar($entity->getId())){
                $this->requestStack->getSession()->getFlashBag()->add('error', $this->cotizacionResumen->getMensaje());
            }
        }
    }
}