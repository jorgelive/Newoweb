<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use DateTimeInterface;
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
        // 0. Si el cambio viene del proceso automático (Webhook/Pull), tiene pase libre
        // (Beds24 asigna/actualiza el canal real de la reserva durante la sincronización).
        if ($this->syncContext->isPull()) {
            return;
        }

        // 0b. NUEVA REGLA: INMUTABILIDAD DEL CANAL
        // El canal se define una sola vez (al crear el evento) y nunca puede
        // reasignarse manualmente después, sea el evento OTA o directo. Evita que
        // el frontend/API reclasifique una reserva Airbnb como directa (o viceversa).
        // Se excluye el caso "valor anterior null" para no romper el saneamiento
        // automático de PmsEventoCalendarioIntegrityListener::asegurarCanalDirecto().
        if ($args->hasChangedField('channel') && $args->getOldValue('channel') !== null) {
            throw new AccessDeniedHttpException(
                'SEGURIDAD: El canal de un evento no se puede modificar una vez asignado. ' .
                'Si el canal es incorrecto, corrígelo desde el proceso de sincronización o soporte técnico.'
            );
        }

        // 0c. NUEVA REGLA: INMUTABILIDAD DE LA RESERVA PADRE
        // `reserva` solo se puede asignar al crear el evento (grupo pms_evento:write_create,
        // exclusivo del Post). Aquí blindamos que ningún PATCH pueda re-parentar un evento
        // ya vinculado hacia otra reserva. Se permite null -> reserva (ver
        // PmsEventoCalendarioIntegrityListener y flujos de EasyAdmin que vinculan en dos pasos).
        if ($args->hasChangedField('reserva') && $args->getOldValue('reserva') !== null) {
            throw new AccessDeniedHttpException(
                'SEGURIDAD: Un evento no se puede reasignar a otra reserva una vez vinculado.'
            );
        }

        // 1. Solo aplicamos restricciones de estado manual a reservas OTA
        if (!$evento->isOta()) {
            return;
        }

        // 3. NUEVA REGLA: INMUTABILIDAD DE FECHAS OTA
        // El calendario de la OTA es sagrado. Si el huésped quiere extender su
        // estadía, debe hacerlo a través de Booking/Airbnb.
        //
        // Se compara el DÍA, no el instante: la HORA sí se puede tocar. Lo que
        // vende el canal son noches, y a Beds24 sólo le viajan `arrival` y
        // `departure` en formato `Y-m-d` (§7.2), así que ajustar el check-out a
        // las 17:00 —un late check-out pactado por WhatsApp— no contradice nada
        // de lo que dice Booking. Bloquear la hora impedía justo eso.
        if ($this->cambiaDeDia($args, 'inicio') || $this->cambiaDeDia($args, 'fin')) {
            throw new AccessDeniedHttpException(
                'SEGURIDAD OTA: No puedes cambiar el DÍA de llegada o salida de una reserva externa ' .
                '(la hora sí). Cualquier cambio de fechas debe realizarse directamente en el canal ' .
                '(Booking, Airbnb, etc.).'
            );
        }

        // 3b. NUEVA REGLA: INMUTABILIDAD DE LA UNIDAD FÍSICA OTA
        // Antes solo el controller de EasyAdmin revertía este cambio en memoria (UX).
        // Lo blindamos aquí también para que cualquier cliente (API, consola, etc.)
        // quede protegido, no solo el panel.
        if ($args->hasChangedField('pmsUnidad')) {
            throw new AccessDeniedHttpException(
                'SEGURIDAD OTA: No puedes reasignar la unidad física de una reserva externa. ' .
                'El movimiento de habitación debe hacerse directamente en el canal (Booking, Airbnb, etc.).'
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

            // =================================================================
            // REGLA 4: RED FINAL — la regla completa, tal cual la declara el maestro
            // =================================================================
            //
            // Las tres reglas de arriba miran el estado DESTINO y dejaban pasar dos casos que
            // sí importan, porque el destino es inocente y el origen no:
            //
            //   · `abierto` → confirmada    ascender una consulta que el canal no ha vendido
            //   · `bloqueo` → confirmada    convertir calendario cerrado en una reserva
            //
            // Los destapó la matriz de transiciones al comparar esta clase con
            // `PmsEventoEstado::transicionOtaPermitida()` y con el desplegable de `util`: el
            // listener era MÁS PERMISIVO que el desplegable, así que no se veían por el panel
            // —pero la API y la consola sí llegaban—.
            //
            // Se deja como red al final y no sustituyendo a las tres anteriores para no perder
            // sus mensajes, que le dicen al operador qué hacer en su caso concreto.
            if (!PmsEventoEstado::transicionOtaPermitida($idAnterior ?: null, $idNuevo)) {
                throw new AccessDeniedHttpException(sprintf(
                    'SEGURIDAD OTA: no se permite pasar de "%s" a "%s" en una reserva de canal. '
                    . 'Entre pendiente, confirmada y requerimiento sí puedes moverte libremente.',
                    $idAnterior !== '' ? $idAnterior : 'sin estado',
                    $idNuevo,
                ));
            }
        }
    }

    /**
     * ¿El campo de fecha cambió de DÍA? Un cambio de sólo hora devuelve `false`.
     */
    private function cambiaDeDia(PreUpdateEventArgs $args, string $campo): bool
    {
        if (!$args->hasChangedField($campo)) {
            return false;
        }

        $viejo = $args->getOldValue($campo);
        $nuevo = $args->getNewValue($campo);

        if (!$viejo instanceof DateTimeInterface || !$nuevo instanceof DateTimeInterface) {
            return true; // null ↔ fecha: eso sí es un cambio de calendario
        }

        return $viejo->format('Y-m-d') !== $nuevo->format('Y-m-d');
    }
}