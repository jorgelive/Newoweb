<?php
namespace App\EventListener;

use App\Entity\ReservaReserva;
use Doctrine\ORM\Event\LifecycleEventArgs;

class ReservaReservaTokenUidListener
{

    public function prePersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if ($entity instanceof ReservaReserva && !$entity->getToken()) {
            $entity->setToken(mt_rand());
        }
        if ($entity instanceof ReservaReserva && !$entity->getUid()) {
            $entity->setUid(sprintf('%08d', $entity->getId()) . '_' . sprintf('%012d', mt_rand()) . '@openperu.pe');
        }
    }
}