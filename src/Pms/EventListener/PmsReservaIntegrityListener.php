<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Phone\PhoneSanitizer;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Mantiene la integridad de los datos de la reserva antes de ir a BD.
 * Sanitiza y estandariza los números de teléfono delegando al PhoneSanitizer.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class PmsReservaIntegrityListener
{
    public function __construct(
        private readonly PhoneSanitizer $phoneSanitizer
    ) {}

    /**
     * Actúa cuando la entidad es completamente nueva.
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof PmsReserva) {
            return;
        }

        $paisIso = $entity->getPais() ? $entity->getPais()->getId() : 'PE';

        $tel1 = $entity->getTelefono();
        if ($tel1 !== null && $tel1 !== '') {
            $entity->setTelefono($this->phoneSanitizer->cleanPhoneNumber($tel1, $paisIso));
        }

        $tel2 = $entity->getTelefono2();
        if ($tel2 !== null && $tel2 !== '') {
            $entity->setTelefono2($this->phoneSanitizer->cleanPhoneNumber($tel2, $paisIso));
        }
    }

    /**
     * Actúa cuando la entidad ya existe y se está modificando.
     * En Doctrine, modificar la entidad por sus setters aquí es inútil porque
     * el ChangeSet ya fue calculado. Debe usarse el objeto de evento.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof PmsReserva) {
            return;
        }

        $paisIso = $entity->getPais() ? $entity->getPais()->getId() : 'PE';

        if ($args->hasChangedField('telefono')) {
            $newTel1 = (string) $args->getNewValue('telefono');
            if ($newTel1 !== '') {
                $clean1 = $this->phoneSanitizer->cleanPhoneNumber($newTel1, $paisIso);
                $args->setNewValue('telefono', $clean1);
                // Es buena práctica actualizar también la entidad en memoria
                $entity->setTelefono($clean1);
            }
        }

        if ($args->hasChangedField('telefono2')) {
            $newTel2 = (string) $args->getNewValue('telefono2');
            if ($newTel2 !== '') {
                $clean2 = $this->phoneSanitizer->cleanPhoneNumber($newTel2, $paisIso);
                $args->setNewValue('telefono2', $clean2);
                $entity->setTelefono2($clean2);
            }
        }
    }
}