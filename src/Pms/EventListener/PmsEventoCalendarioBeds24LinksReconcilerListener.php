<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;

#[AsDoctrineListener(event: Events::onFlush, priority: 700)]
final class PmsEventoCalendarioBeds24LinksReconcilerListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $touchedEvents = $this->getTouchedEvents($uow);
        if (!$touchedEvents) {
            return;
        }

        /** @var ClassMetadata $linkMeta */
        $linkMeta = $em->getClassMetadata(PmsEventoBeds24Link::class);

        $insertedLinksByEvento = $this->indexInsertedLinksByEvento($uow);

        foreach ($touchedEvents as $evento) {
            $this->reconcileLinks(
                $uow,
                $evento,
                $insertedLinksByEvento[spl_object_id($evento)] ?? []
            );

            $this->syncLinksWithDoctrine($uow, $evento, $linkMeta);
        }
    }

    /**
     * @param PmsEventoBeds24Link[] $insertedLinksForEvento
     */
    private function reconcileLinks(
        UnitOfWork $uow,
        PmsEventoCalendario $evento,
        array $insertedLinksForEvento
    ): void {
        $unidad = $evento->getPmsUnidad();
        if (!$unidad instanceof PmsUnidad) {
            return;
        }

        // 1) Inicializar colección completa
        $collection = $evento->getBeds24Links();
        if ($collection instanceof PersistentCollection && !$collection->isInitialized()) {
            $collection->initialize();
        }

        /** @var PmsUnidadBeds24Map[] $mapsActivos */
        $mapsActivos = array_values(array_filter(
            $unidad->getBeds24Maps()->toArray(),
            static fn ($m) => $m instanceof PmsUnidadBeds24Map && $m->isActivo()
        ));

        $now = new DateTimeImmutable();

        // 2) Descubrimiento completo: colección + UOW inserts
        $discovered = [];
        foreach ($collection as $link) {
            if ($link instanceof PmsEventoBeds24Link && !$uow->isScheduledForDelete($link)) {
                $discovered[spl_object_id($link)] = $link;
            }
        }
        foreach ($insertedLinksForEvento as $link) {
            if (!$uow->isScheduledForDelete($link)) {
                $discovered[spl_object_id($link)] = $link;
            }
        }

        // 3) Indexar por map (id o spl_object_id)
        $linksByMapKey = [];
        foreach ($discovered as $link) {
            $map = $link->getUnidadBeds24Map();
            if (!$map instanceof PmsUnidadBeds24Map) {
                continue;
            }
            $linksByMapKey[$this->getMapKey($map)] = $link;
        }

        // 4) Determinar root (orden EXACTO que definiste)
        $rootLink = $this->determineRootLink($linksByMapKey);

        // 5) Reconciliar: asegurar 1 link por map activo
        foreach ($mapsActivos as $map) {
            $mapKey = $this->getMapKey($map);

            if (isset($linksByMapKey[$mapKey])) {
                $l = $linksByMapKey[$mapKey];

                if ($rootLink && $l !== $rootLink && $l->getOriginLink() !== $rootLink) {
                    $l->setOriginLink($rootLink);
                }

                $l->setLastSeenAt($now);
                continue;
            }

            // Crear link faltante
            $l = new PmsEventoBeds24Link();
            $l->setUnidadBeds24Map($map);
            $l->setOriginLink($rootLink);
            $l->markActive();
            $l->setLastSeenAt($now);

            $evento->addBeds24Link($l);

            if (!$rootLink) {
                $rootLink = $l;
            }
        }
    }

    private function syncLinksWithDoctrine(
        UnitOfWork $uow,
        PmsEventoCalendario $evento,
        ClassMetadata $linkMeta
    ): void {
        foreach ($evento->getBeds24Links() as $link) {
            if (!$link instanceof PmsEventoBeds24Link) {
                continue;
            }
            if ($uow->isScheduledForDelete($link)) {
                continue;
            }

            if ($uow->isScheduledForInsert($link)) {
                $uow->computeChangeSet($linkMeta, $link);
                continue;
            }

            $state = $uow->getEntityState($link);
            if ($state === UnitOfWork::STATE_NEW) {
                $uow->scheduleForInsert($link);
                $uow->computeChangeSet($linkMeta, $link);
            } elseif ($state === UnitOfWork::STATE_MANAGED) {
                $uow->recomputeSingleEntityChangeSet($linkMeta, $link);
            }
        }
    }

    private function getTouchedEvents(UnitOfWork $uow): array
    {
        $touched = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PmsEventoCalendario) {
                $touched[spl_object_id($entity)] = $entity;
            }
            if ($entity instanceof PmsEventoBeds24Link) {
                $ev = $entity->getEvento();
                if ($ev instanceof PmsEventoCalendario) {
                    $touched[spl_object_id($ev)] = $ev;
                }
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof PmsEventoCalendario) {
                $touched[spl_object_id($entity)] = $entity;
            }
        }

        return $touched;
    }

    /**
     * @return array<int, PmsEventoBeds24Link[]>
     */
    private function indexInsertedLinksByEvento(UnitOfWork $uow): array
    {
        $byEvento = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof PmsEventoBeds24Link) {
                continue;
            }
            $evento = $entity->getEvento();
            if ($evento instanceof PmsEventoCalendario) {
                $byEvento[spl_object_id($evento)][] = $entity;
            }
        }
        return $byEvento;
    }

    private function determineRootLink(array $linksByMapKey): ?PmsEventoBeds24Link
    {
        // helpers (ajusta getters si el nombre real difiere)
        $getBookingId = static function (PmsEventoBeds24Link $l) {
            return method_exists($l, 'getBeds24BookingId')
                ? $l->getBeds24BookingId()
                : (method_exists($l, 'getBookingId') ? $l->getBookingId() : null);
        };

        $isMapPrincipal = static function (?PmsUnidadBeds24Map $map): bool {
            if (!$map) return false;
            if (method_exists($map, 'isPrincipal')) return (bool) $map->isPrincipal();
            if (method_exists($map, 'getEsPrincipal')) return (bool) $map->getEsPrincipal();
            return false;
        };

        // 1) originLink === null
        foreach ($linksByMapKey as $l) {
            if ($l->getOriginLink() === null) return $l;
        }

        // 2) bookingId + map principal
        foreach ($linksByMapKey as $l) {
            if ($getBookingId($l) && $isMapPrincipal($l->getUnidadBeds24Map())) {
                return $l;
            }
        }

        // 3) bookingId
        foreach ($linksByMapKey as $l) {
            if ($getBookingId($l)) return $l;
        }

        // 4) map principal
        foreach ($linksByMapKey as $l) {
            if ($isMapPrincipal($l->getUnidadBeds24Map())) return $l;
        }

        // fallback estable
        return reset($linksByMapKey) ?: null;
    }

    private function getMapKey(PmsUnidadBeds24Map $map): string
    {
        return $map->getId() !== null
            ? 'id:' . $map->getId()
            : 'obj:' . spl_object_id($map);
    }
}