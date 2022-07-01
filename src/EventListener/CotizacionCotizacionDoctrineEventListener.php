<?php
namespace App\EventListener;

use App\Entity\CotizacionCotizacion;
use Doctrine\ORM\Event\LifecycleEventArgs;

class CotizacionCotizacionDoctrineEventListener
{

    public function prePersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if ($entity instanceof CotizacionCotizacion){
            if(!$entity->getToken()) {
                $entity->setToken(mt_rand());
            }
        }
    }
}