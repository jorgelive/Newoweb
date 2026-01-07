<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsChannel;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::prePersist)]
final class PmsReservaDefaultChannelListener
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof PmsReserva) {
            return;
        }

        // Si ya viene seteado (Beds24 / API / import), no lo tocamos
        if ($entity->getChannel() !== null) {
            return;
        }

        $em = $args->getObjectManager();
        $repo = $em->getRepository(PmsChannel::class);

        // Lookup estable por código (no por ID)
        $directo = $repo->findOneBy([
            'codigo' => PmsChannel::CODIGO_DIRECTO,
        ]);

        if ($directo instanceof PmsChannel) {
            $entity->setChannel($directo);
        }
    }
}