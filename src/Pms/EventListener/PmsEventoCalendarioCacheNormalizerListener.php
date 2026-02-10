<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Normaliza los campos de caché (titulo) antes de insertar en BD.
 * Se ejecuta con prioridad ALTA (400) para asegurar que los datos estén listos.
 */
#[AsDoctrineListener(event: Events::prePersist, priority: 400)]
final class PmsEventoCalendarioCacheNormalizerListener
{
    public function prePersist(PrePersistEventArgs $args): void
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
        // Si no hay reserva asociada (es un bloqueo manual sin reserva), no hacemos nada.
        if (!$reserva instanceof PmsReserva) {
            return;
        }

        // 2. Construir Nombre + Apellido
        $nombre = $reserva->getNombreCliente() ?? '';
        $apellido = $reserva->getApellidoCliente() ?? '';

        $nombreCompleto = trim($nombre . ' ' . $apellido);

        // 3. Fallback: Si no hay nombre, usar el ID de reserva o texto genérico
        if ($nombreCompleto === '') {
            // ✅ CORRECCIÓN UUID: Cast explícito a string para evitar errores de objeto
            $id = $reserva->getId();
            $nombreCompleto = $id ? sprintf('Reserva #%s', (string) $id) : 'Sin Nombre';
        }

        // 4. Truncado de Seguridad (asumiendo VARCHAR(180) o 255)
        // Evita que un nombre excesivamente largo rompa el INSERT.
        $tituloFinal = mb_substr($nombreCompleto, 0, 180);

        $evento->setTituloCache($tituloFinal);
    }
}