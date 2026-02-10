<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Listener de seguridad para PmsEventoCalendario.
 * Gestiona la integridad de reservas XML y locales usando IDs naturales (minúsculas).
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsEventoCalendario::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsEventoCalendario::class)]
final class PmsEventoCalendarioSecurityListener
{
    public function preRemove(PmsEventoCalendario $evento, PreRemoveEventArgs $args): void
    {
        // Validación centralizada: OTA, sincronización y estados críticos.
        if (!$evento->isSafeToDelete()) {
            throw new AccessDeniedHttpException(
                sprintf(
                    'INTEGRIDAD BEDS24: No se puede eliminar el evento #%s. Razón: Es una reserva de OTA (Booking/Airbnb), ya existe en Beds24 o está en proceso de sincronización.',
                    // ✅ Corregido el sprintf: %s para tratar el UUID como string
                    (string) $evento->getId()
                )
            );
        }
    }

    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        // Solo aplicamos restricciones de integridad a reservas que vienen de canales (OTA)
        if (!$evento->isOta()) {
            return;
        }

        if ($args->hasChangedField('estado')) {
            /** @var PmsEventoEstado|null $nuevoEstado */
            $nuevoEstado = $args->getNewValue('estado');

            if (!$nuevoEstado) {
                return;
            }

            /** * ✅ ID NATURAL KEY:
             * Obtenemos el ID directamente (ej: 'cancelada', 'consulta').
             */
            $idEstado = (string) $nuevoEstado->getId();

            // Bloqueo de cancelación manual en reservas XML
            if ($idEstado === PmsEventoEstado::CODIGO_CANCELADA) {
                throw new AccessDeniedHttpException(
                    'SEGURIDAD OTA: Las reservas externas solo pueden ser canceladas automáticamente por el canal.'
                );
            }

            // Bloqueo de degradación a estados informativos
            if ($idEstado === PmsEventoEstado::CODIGO_CONSULTA) {
                throw new AccessDeniedHttpException(
                    'SEGURIDAD OTA: No se puede degradar una reserva de canal a un estado de consulta.'
                );
            }
        }
    }
}