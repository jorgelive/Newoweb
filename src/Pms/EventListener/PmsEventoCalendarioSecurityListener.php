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

#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsEventoCalendario::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsEventoCalendario::class)]
final class PmsEventoCalendarioSecurityListener
{
    /**
     * REGLA DE SEGURIDAD PARA BORRADO:
     * Delegamos la decisión a $evento->isSafeToDelete().
     * * Casos que maneja:
     * 1. OTA -> Bloqueado siempre.
     * 2. Local (Sin ID) -> Permitido borrar siempre (limpieza de errores).
     * 3. Remoto (Con ID) -> Bloqueado, a menos que sea 'Cancelada'/'Bloqueo' y esté sync.
     * 4. En Vuelo (Pending/Processing) -> Bloqueado por seguridad.
     */
    public function preRemove(PmsEventoCalendario $evento, PreRemoveEventArgs $args): void
    {

        // VALIDACIÓN CENTRALIZADA
        // Aquí confiamos en la lógica de la Entidad. Si dice que no es seguro, bloqueamos.
        // Esto permite borrar reservas "Confirmadas" SI Y SOLO SI son locales (sin ID remoto).
        if (!$evento->isSafeToDelete()) {
            throw new AccessDeniedHttpException(
                sprintf(
                    'INTEGRIDAD BEDS24: No se puede eliminar el evento #%d. Razón: Ya existe en Beds24 (debe cancelarlo primero) o hay una sincronización activa.',
                    $evento->getId()
                )
            );
        }
    }

    /**
     * REGLA DE SEGURIDAD PARA EDICIÓN (OTA):
     * Protege la integridad de los datos que pertenecen al canal.
     */
    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        // Si no es OTA, permitimos libertad total de edición
        if (!$evento->isOta()) {
            return;
        }

        // Si es OTA, bloqueamos cambios de estado que rompan la lógica del canal
        if ($args->hasChangedField('estado')) {
            $nuevoEstado = $args->getNewValue('estado');
            if (!$nuevoEstado) return;

            $codigo = $nuevoEstado->getCodigo();

            // Bloqueo de cancelación manual en OTA
            if ($codigo === PmsEventoEstado::CODIGO_CANCELADA) {
                throw new AccessDeniedHttpException(
                    'SEGURIDAD OTA: Las reservas externas solo pueden ser canceladas automáticamente por el canal XML.'
                );
            }

            // Bloqueo de conversión a Requerimiento/Consulta
            if (in_array($codigo, [PmsEventoEstado::CODIGO_CONSULTA], true)) {
                throw new AccessDeniedHttpException(
                    'SEGURIDAD OTA: No se puede degradar una reserva confirmada de OTA a un estado consultivo.'
                );
            }
        }
    }
}