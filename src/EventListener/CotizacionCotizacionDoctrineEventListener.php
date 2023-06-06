<?php
namespace App\EventListener;

use App\Entity\CotizacionCotizacion;
use App\Service\CotizacionResumen;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
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
            $this->cotizacionResumen->procesar($entity->getId());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args)
    {
        $entity = $args->getObject();

        if($entity instanceof CotizacionCotizacion){
            //De haber error los mensajes iran al flashblag
            $this->cotizacionResumen->procesar($entity->getId());
        }
    }
}