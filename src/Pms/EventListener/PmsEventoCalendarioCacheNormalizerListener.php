<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LifecycleEventArgs; // O PrePersistEventArgs en Doctrine > 2.13 recomendado
use Doctrine\ORM\Events;

/**
 * Normaliza los campos de caché (titulo) antes de insertar en BD.
 * * Se ejecuta con prioridad ALTA (400) para asegurar que los datos estén listos
 * antes que otros listeners de validación o workflow.
 */
#[AsDoctrineListener(event: Events::prePersist, priority: 400)]
final class PmsEventoCalendarioCacheNormalizerListener
{
    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof PmsEventoCalendario) {
            return;
        }

        // Normalizamos solo si no vienen ya seteados
        $this->normalizeTituloCache($entity);
    }

    private function normalizeTituloCache(PmsEventoCalendario $evento): void
    {
        // 1. Si ya tiene título manual o pre-cargado, no tocar.
        if (trim($evento->getTituloCache() ?? '') !== '') {
            return;
        }

        $reserva = $evento->getReserva();
        if ($reserva === null) {
            return;
        }

        // 2. Construir Nombre + Apellido
        $nombreCompleto = trim(
            ($reserva->getNombreCliente() ?? '') . ' ' . ($reserva->getApellidoCliente() ?? '')
        );

        // 3. Fallback final: Si no hay nombre, usar el ID de reserva o un texto genérico
        if ($nombreCompleto === '') {
            // Opcional: Usar ID si ya existe (en prePersist el ID de reserva suele existir si es relación)
            // Si $reserva->getId() es null (ambos nuevos), ponemos un texto genérico.
            $nombreCompleto = $reserva->getId() ? "Reserva #{$reserva->getId()}" : 'Sin Nombre';
        }

        $evento->setTituloCache($nombreCompleto);
    }

}