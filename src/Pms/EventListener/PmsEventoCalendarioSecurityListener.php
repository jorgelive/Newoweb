<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Listener de seguridad y estado para PmsEventoCalendario.
 * Gestiona la integridad de reservas XML y locales usando IDs naturales (minúsculas).
 * Aplica el patrón "Defense in Depth" para proteger las transiciones de estado.
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsEventoCalendario::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsEventoCalendario::class)]
final class PmsEventoCalendarioSecurityListener
{
    public function __construct(
        private readonly SyncContext $syncContext
    ) {}

    /**
     * Intercepta la eliminación de un evento antes de que ocurra en la base de datos.
     *
     * @param PmsEventoCalendario $evento
     * @param PreRemoveEventArgs $args
     * @throws AccessDeniedHttpException Si el evento no es seguro de eliminar.
     */
    public function preRemove(PmsEventoCalendario $evento, PreRemoveEventArgs $args): void
    {
        // Validación centralizada: OTA, sincronización y estados críticos.
        // No le dejo borrar nada al channel manager
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

    /**
     * Intercepta la actualización de un evento para proteger los cambios de estado manuales.
     *
     * @param PmsEventoCalendario $evento
     * @param PreUpdateEventArgs $args
     * @throws AccessDeniedHttpException Si se intenta un cambio de estado ilegal.
     */
    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        // 1. Solo aplicamos restricciones a reservas OTA
        if (!$evento->isOta()) {
            return;
        }

        // 2. Si es el proceso automático (PULL), tiene permiso total
        if ($this->syncContext->isPull()) {
            return;
        }

        // 3. Verificamos si cambió el estado
        if ($args->hasChangedField('estado')) {
            /** @var PmsEventoEstado|null $nuevoEstado */
            $nuevoEstado = $args->getNewValue('estado');
            /** @var PmsEventoEstado|null $estadoAnterior */
            $estadoAnterior = $args->getOldValue('estado');

            if (!$nuevoEstado) {
                return;
            }

            $idNuevo = (string) $nuevoEstado->getId();
            $idAnterior = $estadoAnterior ? (string) $estadoAnterior->getId() : '';

            // 🔥 LA EXCEPCIÓN: Si el estado de origen es "ABIERTO",
            // permitimos que el usuario lo pase a "CANCELADA" para limpiar el calendario.
            if ($idAnterior === PmsEventoEstado::CODIGO_ABIERTO && $idNuevo === PmsEventoEstado::CODIGO_CANCELADA) {
                return; // Permitido: Limpieza de Inquiries no concretados.
            }

            // 4. BLINDAJE PARA EL RESTO DE CASOS
            if (in_array($idNuevo, PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES, true)) {

                if ($idNuevo === PmsEventoEstado::CODIGO_CANCELADA) {
                    throw new AccessDeniedHttpException(
                        'SEGURIDAD OTA: Solo puedes cancelar manualmente Consultas (Inquiries). ' .
                        'Las reservas en firme deben ser canceladas por el canal.'
                    );
                }

                if ($idNuevo === PmsEventoEstado::CODIGO_ABIERTO) {
                    throw new AccessDeniedHttpException(
                        'SEGURIDAD OTA: No se puede degradar una reserva activa a consulta.'
                    );
                }

                if ($idNuevo === PmsEventoEstado::CODIGO_BLOQUEO) {
                    throw new AccessDeniedHttpException(
                        'SEGURIDAD OTA: No se puede convertir una reserva externa en un bloqueo manual.'
                    );
                }

                // Fallback genérico por si en el futuro se agregan más estados a la constante OTA_ESTADOS_NO_SELECCIONABLES
                throw new AccessDeniedHttpException(
                    sprintf('SEGURIDAD OTA: No se permite transicionar manualmente una reserva hacia el estado "%s".', $idEstado)
                );
            }
        }
    }
}