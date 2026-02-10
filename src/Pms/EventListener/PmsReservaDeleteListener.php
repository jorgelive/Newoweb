<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsReserva;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsReserva::class)]
final class PmsReservaDeleteListener
{
    /**
     * REGLA DE PROTECCIÓN DE RESERVA:
     * Una reserva es el padre de uno o varios eventos.
     * Si UN SOLO evento viola las reglas de seguridad (isSafeToDelete),
     * se prohíbe borrar la reserva entera.
     */
    public function preRemove(PmsReserva $reserva, PreRemoveEventArgs $args): void
    {
        $eventos = $reserva->getEventosCalendario();

        foreach ($eventos as $evento) {

            // ÚNICA VALIDACIÓN NECESARIA
            // isSafeToDelete() ya verifica internamente:
            // 1. Si es OTA (retorna false)
            // 2. Si tiene ID Beds24 y no está cancelada (retorna false)
            // 3. Si hay colas en curso (retorna false)
            // 4. Si es local/error (retorna TRUE)

            if (!$evento->isSafeToDelete()) {
                throw new AccessDeniedHttpException(sprintf(
                    'NO SE PUEDE ELIMINAR LA RESERVA #%s. El evento #%s bloquea la operación. ' .
                    'POSIBLES CAUSAS: Es una reserva de OTA (Booking/Airbnb), ya existe en Beds24 (debe cancelarla primero) o se está sincronizando en este momento.',
                    // ✅ CORRECCIÓN: %s para UUIDs y cast explícito a string
                    (string) $reserva->getId(),
                    (string) $evento->getId()
                ));
            }

        }
    }
}