<?php
namespace App\EventListener;

use App\Entity\CotizacionFile;
use Doctrine\ORM\Event\PrePersistEventArgs;

class CotizacionFileDoctrineEventListener
{
    public function prePersist(PrePersistEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof CotizacionFile){
            $entity->setToken(mt_rand());

        }
    }
}