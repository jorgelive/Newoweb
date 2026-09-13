<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Normaliza los campos de caché (titulo) antes de insertar en BD.
 * Se ejecuta con prioridad ALTA (400) para asegurar que los datos estén listos.
 *
 * 🔥 **Y lo mantiene al día, que es lo que faltaba.** El título se escribía SÓLO al crear el
 * evento, así que cualquier corrección posterior del nombre no llegaba nunca: el corrector de
 * orden y caja arreglaba la reserva y el caché se quedaba con la versión cruzada o en minúsculas.
 * Medido el 12/09/2026 en producción: **23 de 423** eventos desincronizados, y los 23 eran
 * exactamente las reservas que el corrector había tocado — «uylenbroeck robin» contra «Robin
 * Uylenbroeck», «Morales Mena Alejandra» contra «Alejandra Morales Mena».
 *
 * ⚠️ No es cosmético: `PmsDisponibilidadService` lee `titulo_cache` **como el nombre del huésped**.
 * Un caché que nadie invalida no envejece mal, envejece **en silencio**.
 */
#[AsDoctrineListener(event: Events::prePersist, priority: 400)]
#[AsDoctrineListener(event: Events::onFlush)]
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

    /**
     * Arrastra al caché los cambios de nombre de la reserva.
     *
     * 🔑 **Sólo pisa el título si era el derivado del nombre ANTERIOR.** Ésa es la guarda entera:
     * si coincide con lo que había, es nuestro y se actualiza; si no coincide, alguien lo escribió
     * a mano y no se toca. Sin esto habría que elegir entre un caché podrido o pisar el trabajo de
     * una persona, y la comparación resuelve las dos cosas sin un campo nuevo que mantener.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadatos = $em->getClassMetadata(PmsEventoCalendario::class);

        foreach ($uow->getScheduledEntityUpdates() as $entidad) {
            if (!$entidad instanceof PmsReserva) {
                continue;
            }

            $cambios = $uow->getEntityChangeSet($entidad);

            if (!isset($cambios['nombreCliente']) && !isset($cambios['apellidoCliente'])) {
                continue;
            }

            $antes = self::tituloDe(
                is_string($cambios['nombreCliente'][0] ?? null) ? $cambios['nombreCliente'][0] : $entidad->getNombreCliente(),
                is_string($cambios['apellidoCliente'][0] ?? null) ? $cambios['apellidoCliente'][0] : $entidad->getApellidoCliente(),
            );
            $ahora = self::tituloDe($entidad->getNombreCliente(), $entidad->getApellidoCliente());

            if ($ahora === '' || $ahora === $antes) {
                continue;
            }

            foreach ($entidad->getEventosCalendario() as $evento) {
                if (trim($evento->getTituloCache() ?? '') !== $antes) {
                    continue;   // título puesto a mano: no es nuestro
                }

                $evento->setTituloCache(mb_substr($ahora, 0, 180));
                // Obligatorio en `onFlush`: sin esto el cambio no entra en el UPDATE ya calculado.
                $uow->recomputeSingleEntityChangeSet($metadatos, $evento);
            }
        }
    }

    private static function tituloDe(?string $nombre, ?string $apellido): string
    {
        return trim(trim((string) $nombre) . ' ' . trim((string) $apellido));
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