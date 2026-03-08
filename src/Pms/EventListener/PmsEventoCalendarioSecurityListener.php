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
        // Solo aplicamos restricciones de integridad a reservas que vienen de canales (OTA)
        if (!$evento->isOta()) {
            return;
        }

        // ✅ LÓGICA CLAVE: Permitimos que los procesos automáticos de sincronización (pull)
        // salten esta validación, ya que ellos SÍ tienen autoridad para cancelar reservas.
        if ($this->syncContext->isPull()) {
            return;
        }

        // Verificamos si, y solo si, la propiedad 'estado' sufrió una mutación real en este request
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

            // Blindaje estricto usando la constante centralizada de la entidad
            if (in_array($idEstado, PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES, true)) {

                // Mantenemos los mensajes de error específicos para mejorar el feedback al usuario
                if ($idEstado === PmsEventoEstado::CODIGO_CANCELADA) {
                    throw new AccessDeniedHttpException(
                        'SEGURIDAD OTA: Las reservas externas solo pueden ser canceladas automáticamente por el canal.'
                    );
                }

                if ($idEstado === PmsEventoEstado::CODIGO_ABIERTO) {
                    throw new AccessDeniedHttpException(
                        'SEGURIDAD OTA: No se puede degradar una reserva de canal a un estado de consulta.'
                    );
                }

                if ($idEstado === PmsEventoEstado::CODIGO_BLOQUEO) {
                    throw new AccessDeniedHttpException(
                        'SEGURIDAD OTA: No se puede convertir una reserva de canal en un bloqueo manual.'
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