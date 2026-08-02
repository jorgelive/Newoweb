<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;
use LogicException;

/**
 * Listener de Integridad de Datos y Automatización de Negocio.
 * Valida la coherencia estructural de la reserva (fechas, canales) y automatiza
 * los cambios de estado vinculados a los pagos sin violar las reglas de seguridad.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: PmsEventoCalendario::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsEventoCalendario::class)]
final class PmsEventoCalendarioIntegrityListener
{
    public function __construct(
        private readonly SyncContext $syncContext
    ) {}

    /**
     * Se ejecuta antes de crear un nuevo registro en la base de datos.
     * Garantiza que la reserva nazca con integridad total.
     */
    public function prePersist(PmsEventoCalendario $evento, PrePersistEventArgs $args): void
    {
        $this->validarFechas($evento);
        $this->asegurarCanalDirecto($evento, $args->getObjectManager());
        $this->asegurarEstadoConfirmadoPorPago($evento, $args->getObjectManager());
    }

    /**
     * Se ejecuta antes de actualizar un registro existente.
     * Intercepta los cambios para aplicar reglas de negocio y re-calcula el UnitOfWork si es necesario.
     */
    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        $needsRecompute = false;

        // Optimización: Solo validamos si se tocaron las fechas.
        if ($args->hasChangedField('inicio') || $args->hasChangedField('fin')) {
            $this->validarFechas($evento);
        }

        // RED DE SEGURIDAD 1: Recuperación de Canal Directo
        if ($evento->getChannel() === null && !$evento->isOta()) {
            $this->asegurarCanalDirecto($evento, $args->getObjectManager());
            $needsRecompute = true;
        }

        // RED DE SEGURIDAD 2: Automatización de Confirmación por Pago.
        // Se revisa ante CUALQUIER cambio de estado O de estado de pago: mirando solo
        // `estadoPago` bastaba con reabrir una reserva ya pagada y bajarle el estado a
        // "pendiente" (sin tocar el pago) para dejarla guardada como pagada-no-confirmada.
        if ($args->hasChangedField('estadoPago') || $args->hasChangedField('estado')) {
            if ($this->asegurarEstadoConfirmadoPorPago($evento, $args->getObjectManager(), $args)) {
                $needsRecompute = true;
            }
        }

        // Propagar mutaciones internas a Doctrine
        if ($needsRecompute) {
            $em = $args->getObjectManager();
            $uow = $em->getUnitOfWork();
            $meta = $em->getClassMetadata(PmsEventoCalendario::class);
            $uow->recomputeSingleEntityChangeSet($meta, $evento);
        }
    }

    /**
     * Inyecta el Canal Directo por defecto si una reserva manual se quedó sin canal asignado.
     * Previene datos huérfanos en los reportes financieros.
     */
    private function asegurarCanalDirecto(PmsEventoCalendario $evento, ObjectManager $em): void
    {
        if (!$evento->isOta() && $evento->getChannel() === null) {
            // Usamos getReference para evitar una consulta SQL innecesaria
            $canalDirecto = $em->getReference(PmsChannel::class, PmsChannel::CODIGO_DIRECTO);

            if ($canalDirecto) {
                $evento->setChannel($canalDirecto);
            }
        }
    }

    /**
     * Regla de Negocio Automatizada: una estancia con pago registrado (total, parcial o de
     * alojamiento) queda "Confirmada". La decisión completa —incluidos los estados intocables
     * (cancelada, bloqueo)— vive en PmsEventoCalendario::requiereAutoConfirmacionPorPago(),
     * para que backend y editor Vue apliquen exactamente la misma regla.
     *
     * Durante un Pull de Beds24 no se toca nada: ahí el estado lo manda el canal y
     * BookingPullPersister::resolveEstado() ya resuelve la variante OTA (incluida la
     * protección de las consultas/inquiries "abiertas", que no se auto-confirman jamás).
     *
     * @param PreUpdateEventArgs|null $args ChangeSet vivo en preUpdate; null en prePersist.
     *
     * @return bool True si se realizó una mutación en la entidad, False en caso contrario.
     */
    private function asegurarEstadoConfirmadoPorPago(
        PmsEventoCalendario $evento,
        ObjectManager $em,
        ?PreUpdateEventArgs $args = null
    ): bool {
        if ($this->syncContext->isPull()) {
            return false;
        }

        if (!$evento->requiereAutoConfirmacionPorPago()) {
            return false;
        }

        // Usamos getReference para inyectar el estado sin disparar un SELECT a la BD
        $estadoConfirmada = $em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CONFIRMADA);
        $evento->setEstado($estadoConfirmada);

        // ⚠️ No basta con mutar la entidad si `estado` YA venía en el changeSet.
        // Doctrine recalcula solo por diferencia contra `originalEntityData` (aquí y en
        // UnitOfWork::executeUpdates, justo después de este listener) y FUSIONA el
        // resultado con el changeSet previo. Cuando el operador bajó una reserva ya
        // confirmada a "pendiente", devolverla a "confirmada" no produce diferencia
        // alguna contra el valor original -> no se genera entrada nueva -> sobrevive la
        // vieja ["confirmada" => "pendiente"] y el UPDATE grabaría el estado incorrecto.
        // setNewValue() corrige la entrada existente por referencia.
        if ($args?->hasChangedField('estado')) {
            $args->setNewValue('estado', $estadoConfirmada);
        }

        return true;
    }

    /**
     * Validación Estricta de Espacio-Tiempo.
     * Garantiza que Beds24 y el sistema local nunca reciban reservas con duración de cero o negativa.
     * * @throws LogicException Si la fecha de fin es menor o igual a la de inicio.
     */
    private function validarFechas(PmsEventoCalendario $evento): void
    {
        $inicio = $evento->getInicio();
        $fin = $evento->getFin();

        // Validamos solo si ambas fechas existen
        if ($inicio && $fin) {

            // REGLA: Fin debe ser estrictamente mayor que Inicio.
            if ($fin <= $inicio) {

                // Obtenemos el ID para el log (si es persist, podría ser null/nuevo)
                $id = $evento->getId() ? (string) $evento->getId() : 'NUEVO';
                $unidad = $evento->getPmsUnidad() ? $evento->getPmsUnidad()->getNombre() : 'Sin Unidad';

                throw new LogicException(sprintf(
                    'ERROR DE INTEGRIDAD (Evento #%s | %s): La fecha de fin (%s) no puede ser igual o anterior a la de inicio (%s). ' .
                    'Se requiere una duración mínima de 1 minuto/noche. Operación abortada.',
                    $id,
                    $unidad,
                    $fin->format('Y-m-d H:i'),
                    $inicio->format('Y-m-d H:i')
                ));
            }
        }
    }
}