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
 * Gestiona la integridad de las reservas (especialmente las OTA) aplicando
 * el patrón "Defense in Depth" para proteger las transiciones de estado manuales.
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
     * Previene que los operadores borren reservas físicas que deberían ser canceladas o archivadas.
     *
     * @param PmsEventoCalendario $evento El evento a eliminar.
     * @param PreRemoveEventArgs $args Argumentos del evento de Doctrine.
     * @throws AccessDeniedHttpException Si el evento está protegido contra borrado.
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
     * Intercepta la actualización de un evento para proteger la Máquina de Estados.
     * Evalúa transiciones terminales, limpiezas permitidas y bloqueos estrictos.
     *
     * @param PmsEventoCalendario $evento El evento modificado.
     * @param PreUpdateEventArgs $args Contiene el ChangeSet de Doctrine.
     * @throws AccessDeniedHttpException Si se intenta una transición de estado ilegal.
     */
    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        // 1. Solo aplicamos restricciones de estado manual a reservas OTA
        if (!$evento->isOta()) {
            return;
        }

        // 2. Si el cambio viene del proceso automático (Webhook/Pull), tiene pase libre
        if ($this->syncContext->isPull()) {
            return;
        }

        // 3. NUEVA REGLA: INMUTABILIDAD DE FECHAS OTA
        // El calendario de la OTA es sagrado. Si el huésped quiere extender su
        // estadía, debe hacerlo a través de Booking/Airbnb.
        if ($args->hasChangedField('inicio') || $args->hasChangedField('fin')) {
            throw new AccessDeniedHttpException(
                'SEGURIDAD OTA: No puedes modificar las fechas de llegada o salida de una reserva externa. ' .
                'Cualquier cambio de fechas debe realizarse directamente en el canal (Booking, Airbnb, etc.).'
            );
        }

        // 4. Verificamos si cambió el estado
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

            // =================================================================
            // REGLA 1: ESTADO TERMINAL (Anti-Resurrección)
            // =================================================================
            if ($idAnterior === PmsEventoEstado::CODIGO_CANCELADA && $idNuevo !== PmsEventoEstado::CODIGO_CANCELADA) {
                throw new AccessDeniedHttpException(
                    'SEGURIDAD OTA: Una reserva cancelada por el canal es un estado terminal. No puedes reactivarla manualmente.'
                );
            }

            // =================================================================
            // REGLA 2: LIMPIEZA DE CONSULTAS (Inquiries)
            // =================================================================
            if ($idAnterior === PmsEventoEstado::CODIGO_ABIERTO && $idNuevo === PmsEventoEstado::CODIGO_CANCELADA) {
                return; // Excepción permitida para limpiar el calendario de no-shows
            }

            // =================================================================
            // REGLA 3: BLINDAJE HACIA ESTADOS RESTRINGIDOS
            // =================================================================
            if (in_array($idNuevo, PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES, true)) {

                if ($idNuevo === PmsEventoEstado::CODIGO_CANCELADA) {
                    throw new AccessDeniedHttpException(
                        'SEGURIDAD OTA: Solo puedes cancelar manualmente Consultas (Inquiries). ' .
                        'Las reservas en firme deben ser canceladas directamente en el canal (Booking/Airbnb).'
                    );
                }

                if ($idNuevo === PmsEventoEstado::CODIGO_ABIERTO) {
                    throw new AccessDeniedHttpException(
                        'SEGURIDAD OTA: No se puede degradar una reserva en firme a una consulta abierta.'
                    );
                }

                if ($idNuevo === PmsEventoEstado::CODIGO_BLOQUEO) {
                    throw new AccessDeniedHttpException(
                        'SEGURIDAD OTA: No se puede convertir una reserva externa en un bloqueo de calendario manual.'
                    );
                }

                // Fallback genérico por si en el futuro se agregan más estados a la constante OTA_ESTADOS_NO_SELECCIONABLES
                throw new AccessDeniedHttpException(
                    sprintf('SEGURIDAD OTA: No se permite transicionar manualmente una reserva hacia el estado "%s".', $idNuevo)
                );
            }
        }
    }
}