<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::prePersist, priority: 400)]
final class PmsEventoCalendarioCacheNormalizerListener
{
    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        // Esto solo aplica cuando INSERTAMOS un evento nuevo (típicamente desde UI).
        if (!$entity instanceof PmsEventoCalendario) {
            return;
        }

        // No pisa datos que ya vinieron seteados (pull/CLI/UI).
        $this->normalizeTituloCache($entity);
        $this->normalizeOrigenCache($entity);
    }

    /**
     * Título cache:
     * - No pisa datos que ya vinieron del pull
     * - Solo rellena si está vacío
     */
    private function normalizeTituloCache(PmsEventoCalendario $evento): bool
    {
        if (trim((string) ($evento->getTituloCache() ?? '')) !== '') {
            return false;
        }

        $reserva = $evento->getReserva();
        if ($reserva === null) {
            return false;
        }

        $nombre = trim(
            (string) ($reserva->getNombreCliente() ?? '') . ' ' .
            (string) ($reserva->getApellidoCliente() ?? '')
        );

        if ($nombre === '') {
            return false;
        }

        $evento->setTituloCache($nombre);
        return true;
    }

    /**
     * Origen cache:
     * - SIEMPRE usa beds24ChannelId
     * - Uniforme en UI / pull / CLI
     * - Fallback explícito: "direct"
     */
    private function normalizeOrigenCache(PmsEventoCalendario $evento): bool
    {
        if (trim((string) ($evento->getOrigenCache() ?? '')) !== '') {
            return false;
        }

        $channel = $evento->getReserva()?->getChannel();
        if ($channel === null) {
            return false;
        }

        $beds24ChannelId = trim((string) ($channel->getBeds24ChannelId() ?? ''));

        $evento->setOrigenCache(
            $beds24ChannelId !== ''
                ? $beds24ChannelId   // direct | booking | airbnb
                : 'direct'           // fallback único y explícito
        );

        return true;
    }
}