<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsReservaHuesped;
use App\Pms\Service\Reserva\PmsReservaRecalculoService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Listener PmsReservaRecalculoListener.
 * Automatiza el recálculo de los totales de la reserva cuando cambian sus componentes.
 * Adaptado para UUID v7 y tipado estricto (Sin lógica mágica).
 */
#[AsDoctrineListener(event: Events::onFlush, priority: -1000)]
#[AsDoctrineListener(event: Events::postFlush, priority: -1000)]
final class PmsReservaRecalculoListener
{
    /** * Almacenamos los UUIDs afectados como strings para garantizar unicidad y compatibilidad.
     * @var array<string, true>
     */
    private array $reservaIds = [];

    /**
     * Bandera para evitar bucles infinitos durante el flush.
     */
    private bool $isFlushing = false;

    public function __construct(
        private readonly PmsReservaRecalculoService $service,
    ) {}

    /**
     * Fase 1: Detección.
     * Escaneamos todos los cambios pendientes para identificar qué reservas necesitan recálculo.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->isFlushing) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        // 1. Inserciones (UUID v7 ya tiene ID aquí, no necesitamos esperar)
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->collectReservaId($entity);
        }

        // 2. Actualizaciones
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->collectReservaId($entity);
        }

        // 3. Eliminaciones
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->collectReservaId($entity);
        }

        // 4. Cambios en Colecciones (Ej: borrar un evento de la lista de la reserva)
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $this->processCollectionOwner($collection->getOwner());
        }

        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            $this->processCollectionOwner($collection->getOwner());
        }
    }

    /**
     * Fase 2: Ejecución.
     * Llamamos al servicio de recálculo solo para los IDs recolectados.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->isFlushing || $this->reservaIds === []) {
            return;
        }

        // Extraemos los IDs únicos
        $ids = array_keys($this->reservaIds);

        // Limpiamos la memoria INMEDIATAMENTE para evitar efectos secundarios
        $this->reservaIds = [];

        $this->isFlushing = true;
        try {
            /** @var EntityManagerInterface $em */
            $em = $args->getObjectManager();

            // Ejecutamos el servicio de recálculo (UPDATE pms_reserva SET monto = SUM(...))
            $this->service->recalcularDesdeEventos($ids, $em);
        } finally {
            $this->isFlushing = false;
        }
    }

    /**
     * Identifica estrictamente si la entidad afecta el total de una reserva.
     */
    private function collectReservaId(object $entity): void
    {
        // CASO 1: La Reserva misma (Cambio de datos directos)
        if ($entity instanceof PmsReserva) {
            $this->reservaIds[(string) $entity->getId()] = true;
            return;
        }

        // CASO 2: Eventos del Calendario (Cambio de precios, fechas o unidad)
        if ($entity instanceof PmsEventoCalendario) {
            $reserva = $entity->getReserva();
            if ($reserva) {
                $this->reservaIds[(string) $reserva->getId()] = true;
            }
            return;
        }

        // CASO 3: Huéspedes (Puede afectar impuestos o conteo de pax)
        if ($entity instanceof PmsReservaHuesped) {
            $reserva = $entity->getReserva();
            if ($reserva) {
                $this->reservaIds[(string) $reserva->getId()] = true;
            }
            return;
        }

        // NOTA: Se eliminaron los fallbacks genéricos con method_exists.
        // Si se añaden Pagos o Extras en el futuro, agregarlos explícitamente aquí.
    }

    /**
     * Helper para procesar el dueño de una colección modificada.
     */
    private function processCollectionOwner(object|null $owner): void
    {
        if ($owner) {
            $this->collectReservaId($owner);
        }
    }
}