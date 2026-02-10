<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsReserva;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Asigna el canal 'DIRECTO' por defecto si la reserva no tiene origen.
 * Se ejecuta antes de guardar (PrePersist).
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class PmsReservaDefaultChannelListener
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        // 1. Filtro rápido: Solo nos interesan las Reservas
        if (!$entity instanceof PmsReserva) {
            return;
        }

        // 2. Si ya tiene canal (Beds24, Airbnb, Importación), no tocamos nada.
        if ($entity->getChannel() !== null) {
            return;
        }

        $em = $args->getObjectManager();

        // 3. OPTIMIZACIÓN: getReference() vs find()
        // Al usar getReference, Doctrine crea un objeto "Proxy" con el ID 'directo'
        // SIN hacer una consulta SELECT a la base de datos.
        // Esto ahorra 1 query por cada reserva nueva insertada.
        /** @var PmsChannel $canalDirecto */
        $canalDirecto = $em->getReference(PmsChannel::class, PmsChannel::CODIGO_DIRECTO);

        $entity->setChannel($canalDirecto);
    }
}