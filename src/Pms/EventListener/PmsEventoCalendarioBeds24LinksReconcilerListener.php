<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Asegura y reconcilia los links Beds24 (PmsEventoBeds24Link) para cada PmsEventoCalendario tocado en el flush.
 *
 * Responsabilidad real:
 * - Crear/ajustar links según los maps activos de la unidad.
 * - Mantener un rootLink (originLink = null) y sublinks (originLink = root).
 * - Reapuntar links cuando cambia la unidad.
 *
 * Nota: corre en onFlush y recalcula changesets para que Doctrine persista los links creados/modificados.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: -700)]
final class PmsEventoCalendarioBeds24LinksReconcilerListener
{
    private readonly EntityManagerInterface $em;

    public function __construct(
        EntityManagerInterface $em,
    ) {
        $this->em = $em;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /**
         * Guardamos por evento el "origen" del toque, porque:
         * - En onFlush, Doctrine solo tiene changeset computado para entidades en scheduled insert/update.
         * - Si el evento solo aparece por cambios en colecciones, puede estar MANAGED pero sin changeset aún;
         *   ahí recomputeSingleEntityChangeSet() revienta.
         */
        $touched = new \SplObjectStorage(); // PmsEventoCalendario => string (insert|update|collection)

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PmsEventoCalendario) {
                $touched[$entity] = 'insert';
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof PmsEventoCalendario) {
                // si ya estaba como insert, no lo pisamos
                if (!isset($touched[$entity])) {
                    $touched[$entity] = 'update';
                }
            }
        }

        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $owner = $collection->getOwner();
            if ($owner instanceof PmsEventoCalendario) {
                if (!isset($touched[$owner])) {
                    $touched[$owner] = 'collection';
                }
            }
        }

        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            $owner = $collection->getOwner();
            if ($owner instanceof PmsEventoCalendario) {
                if (!isset($touched[$owner])) {
                    $touched[$owner] = 'collection';
                }
            }
        }

        if ($touched->count() === 0) {
            return;
        }

        $linkMeta = $em->getClassMetadata(PmsEventoBeds24Link::class);

        foreach ($touched as $evento) {
            // Detectar si la unidad cambió comparando valor actual vs original.
            // Para inserciones nuevas, asumimos "no cambió".
            $unidadChanged = false;
            $original = $uow->getOriginalEntityData($evento);
            if (array_key_exists('pmsUnidad', $original)) {
                $unidadChanged = ($original['pmsUnidad'] ?? null) !== $evento->getPmsUnidad();
            }

            $changed = $this->reconcileLinks($em, $evento, $unidadChanged);
            if (!$changed) {
                continue;
            }

            // Asegurar que Doctrine registre cambios en links creados/modificados en onFlush.
            // Regla anti-HY093:
            // - Para entidades programadas para INSERT: computeChangeSet() (NO recompute, NO scheduleForUpdate).
            // - Para entidades MANAGED con identidad: recomputeSingleEntityChangeSet().
            foreach ($evento->getBeds24Links() as $link) {
                if (!$link instanceof PmsEventoBeds24Link) {
                    continue;
                }

                if ($uow->isScheduledForInsert($link) || $uow->getEntityState($link) === UnitOfWork::STATE_NEW) {
                    // Para INSERT: si Doctrine ya computó el changeset (normalmente completo), NO lo recomputamos.
                    // Recomputarlo después de que originalEntityData fue seteado puede dejar el changeset vacío o reducido
                    // (p.ej. solo lastSeenAt) y romper el INSERT con HY093.
                    if ($uow->getEntityChangeSet($link) === []) {
                        $uow->computeChangeSet($linkMeta, $link);
                    }
                    continue;
                }

                if ($uow->getEntityState($link) === UnitOfWork::STATE_MANAGED && $link->getId() !== null) {
                    $uow->recomputeSingleEntityChangeSet($linkMeta, $link);
                }
            }
        }
    }

    /**
     * Conciliación de links Beds24.
     *
     * Reglas:
     * - Para una unidad dada, se generan links (1 por map activo).
     * - El link del map principal es la raíz; los demás apuntan a originLink.
     * - Si cambió la unidad: los links que ya tienen beds24BookId se reapuntan a los maps nuevos
     *   (prioridad: root -> principalMap; luego los demás en orden).
     * - Si hay menos maps en la unidad nueva: descartamos todos menos el principal.
     *   (preferimos desactivar en lugar de borrar si existe el método/campo).
     * - Si hay más maps: creamos links nuevos como en inserción.
     */
    private function reconcileLinks(EntityManagerInterface $em, PmsEventoCalendario $evento, bool $unidadChanged): bool
    {
        $unidad = $evento->getPmsUnidad();
        if (!$unidad instanceof PmsUnidad) {
            return false;
        }

        $maps = $this->findActiveMapsForUnidad($em, $unidad);
        if ($maps === []) {
            return false;
        }

        // Para poder revivir links históricos (SYNCED_DELETED) sin crear duplicados (unique evento+map)
        $activeMapIds = [];
        foreach ($maps as $m) {
            $id = $m->getId();
            if ($id !== null) {
                $activeMapIds[$id] = true;
            }
        }

        // Root link manda: si ya existe (originLink = null), ese es el principal aunque su map NO sea el "principal".
        // El flag esPrincipal solo se usa para bootstrap cuando aún no existe root.
        $bootstrapMap = $this->resolvePrincipalMap($maps);
        if (!$bootstrapMap) {
            return false;
        }

        $changed = false;
        $now = new DateTimeImmutable();

        // Indexamos links existentes.
        $links = [];
        $linksWithBookId = [];
        $rootLink = null; // originLink === null (manda)

        foreach ($evento->getBeds24Links() as $link) {
            $status = $link->getStatus();
            $mapId  = $link->getUnidadBeds24Map()?->getId();

            // ✅ Si está SYNCED_DELETED pero el map sigue activo, lo revivimos.
            // No podemos crear un nuevo link para el mismo map por el unique (evento_id, unidad_beds24_map_id).
            if ($status === PmsEventoBeds24Link::STATUS_SYNCED_DELETED) {
                if ($mapId !== null && isset($activeMapIds[$mapId])) {
                    $link->markActive();
                    // Para que POST cree una sub-reserva nueva (y no intente update por bookId viejo)
                    $link->setBeds24BookId(null);
                    $link->setDeactivatedAt(null);
                    $link->setLastSeenAt($now);
                    $changed = true;

                    // Continuamos con indexado normal (ya revivido)
                    $status = $link->getStatus();
                } else {
                    // Si el map ya no existe/está inactivo, no participa en la estructura.
                    continue;
                }
            }

            // Links DETACHED no participan en la estructura
            if ($status === PmsEventoBeds24Link::STATUS_DETACHED) {
                continue;
            }

            // Index por map: ACTIVE y pending_* cuentan como existentes para evitar duplicados.
            if ($mapId !== null) {
                if (
                    $status === PmsEventoBeds24Link::STATUS_ACTIVE
                    || $status === PmsEventoBeds24Link::STATUS_PENDING_MOVE
                    || $status === PmsEventoBeds24Link::STATUS_PENDING_DELETE
                ) {
                    $links[$mapId] = $link;
                }
            }

            // Root existente manda (originLink = null).
            // Permitimos ACTIVE y PENDING_MOVE como candidatos (PENDING_DELETE no debería ser root).
            if (
                $link->getOriginLink() === null
                && (
                    $status === PmsEventoBeds24Link::STATUS_ACTIVE
                    || $status === PmsEventoBeds24Link::STATUS_PENDING_MOVE
                )
            ) {
                if ($rootLink === null) {
                    $rootLink = $link;
                } else {
                    $rootHas = ($rootLink->getBeds24BookId() !== null && $rootLink->getBeds24BookId() !== '');
                    $thisHas = ($link->getBeds24BookId() !== null && $link->getBeds24BookId() !== '');
                    if (!$rootHas && $thisHas) {
                        $rootLink = $link;
                    }
                }
            }

            if ($link->getBeds24BookId() !== null && $link->getBeds24BookId() !== '') {
                $linksWithBookId[] = $link;
            }
        }

        // Orden: primero root (originLink=null), luego el resto.
        usort($linksWithBookId, static function (PmsEventoBeds24Link $a, PmsEventoBeds24Link $b): int {
            $aRoot = $a->getOriginLink() === null;
            $bRoot = $b->getOriginLink() === null;
            if ($aRoot === $bRoot) {
                return 0;
            }
            return $aRoot ? -1 : 1;
        });

        // Definimos el map "root" (el del rootLink) y la estrategia de orden.
        // - Si NO cambió la unidad y ya existe rootLink, su map es el rootMap (no lo movemos por esPrincipal).
        // - Si cambió la unidad, el rootLink se reasigna al map bootstrap (esPrincipal) porque los maps anteriores ya no aplican.
        $rootMap = null;
        if (!$unidadChanged && $rootLink !== null) {
            $rootMap = $rootLink->getUnidadBeds24Map();
        }
        if (!$rootMap instanceof PmsUnidadBeds24Map) {
            $rootMap = $bootstrapMap;
        }

        // Si cambió la unidad, reapuntamos links que tengan bookId a los maps disponibles, preservando identidad del rootLink.
        if ($unidadChanged && $linksWithBookId !== []) {
            // Ordenamos los maps con el rootMap primero; luego el resto.
            $targetMaps = $this->orderMapsPrincipalFirst($maps, $rootMap);

            // Aseguramos que el primer link con bookId sea root (originLink = null) y se asigne al rootMap.
            $first = $linksWithBookId[0];

            if ($first->getOriginLink() !== null) {
                $first->setOriginLink(null);
                $changed = true;
            }
            if ($first->getUnidadBeds24Map()?->getId() !== $rootMap->getId()) {
                $first->setUnidadBeds24Map($rootMap);
                $changed = true;
            }
            $rootLink = $first;

            // Si hay menos maps que links con bookId: nos quedamos con el root y descartamos el resto.
            if (count($targetMaps) < count($linksWithBookId)) {
                for ($i = 1; $i < count($linksWithBookId); $i++) {
                    $l = $linksWithBookId[$i];
                    $changed = $this->deactivateOrRemoveLink($evento, $l, $now) || $changed;
                }
            } else {
                // Hay suficientes maps: reasignamos cada link con bookId a un map distinto.
                // Nota: el índice 0 ya se asignó al rootMap.
                foreach ($linksWithBookId as $idx => $l) {
                    $map = $targetMaps[$idx] ?? null;
                    if (!$map) {
                        break;
                    }

                    if ($l->getUnidadBeds24Map()?->getId() !== $map->getId()) {
                        $l->setUnidadBeds24Map($map);
                        $changed = true;
                    }
                }
            }
        }

        // Aseguramos que exista rootLink (originLink = null). Si ya existe, NO lo movemos por esPrincipal cuando no cambió la unidad.
        $rootLink = $rootLink ?? ($rootMap?->getId() ? ($links[$rootMap->getId()] ?? null) : null);

        if ($rootLink === null) {
            // Bootstrap: si no hay root existente, creamos uno usando el map marcado como principal (bootstrapMap).
            $rootLink = new PmsEventoBeds24Link();
            $rootLink->setEvento($evento);
            $rootLink->setUnidadBeds24Map($bootstrapMap);
            $rootLink->setOriginLink(null);
            $rootLink->setLastSeenAt($now);
            $rootLink->markActive();
            $em->persist($rootLink);
            $evento->addBeds24Link($rootLink);
            $changed = true;

            // Al bootstrap, el rootMap es el map del rootLink.
            $rootMap = $bootstrapMap;
        } else {
            // Asegurar root.
            if ($rootLink->getOriginLink() !== null) {
                $rootLink->setOriginLink(null);
                $changed = true;
            }

            // Solo si cambió la unidad (o el map no está seteado), reasignamos el rootLink al rootMap elegido.
            if ($unidadChanged || !$rootLink->getUnidadBeds24Map() instanceof PmsUnidadBeds24Map) {
                if ($rootLink->getUnidadBeds24Map()?->getId() !== $rootMap->getId()) {
                    $rootLink->setUnidadBeds24Map($rootMap);
                    $changed = true;
                }
            }

            // Evitar tocar links sin identidad durante INSERT: puede reducir el changeset a solo lastSeenAt y romper el INSERT (HY093).
            if ($rootLink->getId() !== null) {
                $rootLink->setLastSeenAt($now);
                $changed = true;
            }
        }

        // Para el resto de maps: si NO cambió la unidad, simplemente aseguramos que existan.
        // Si cambió, creamos los faltantes sólo si hay más maps que links que ya tenían bookId.
        $mapsOrdered = $this->orderMapsPrincipalFirst($maps, $rootMap);
        foreach ($mapsOrdered as $idx => $map) {
            if ($map->getId() === $rootMap->getId()) {
                continue;
            }

            $existing = $links[$map->getId()] ?? null;

            // Si el link histórico está SYNCED_DELETED, revivimos en lugar de crear uno nuevo.
            if ($existing !== null && $existing->getStatus() === PmsEventoBeds24Link::STATUS_SYNCED_DELETED) {
                $existing->markActive();
                $existing->setBeds24BookId(null);
                $existing->setDeactivatedAt(null);
                $existing->setOriginLink($rootLink);
                $existing->setLastSeenAt($now);
                $changed = true;
                continue;
            }

            // Si cambió unidad y ya venían links con bookId, sólo creamos nuevos cuando falten maps.
            if ($unidadChanged && $linksWithBookId !== []) {
                // Creamos si el map no tiene link asignado.
                if ($existing === null) {
                    $l = new PmsEventoBeds24Link();
                    $l->setEvento($evento);
                    $l->setUnidadBeds24Map($map);
                    $l->setOriginLink($rootLink);
                    $l->setLastSeenAt($now);
                    $l->markActive();
                    $em->persist($l);
                    $evento->addBeds24Link($l);
                    $changed = true;
                } else {
                    // Aseguramos originLink.
                    if ($existing->getOriginLink() !== $rootLink) {
                        $existing->setOriginLink($rootLink);
                        $changed = true;
                    }
                    if ($existing->getId() !== null) {
                        $existing->setLastSeenAt($now);
                        $changed = true;
                    }
                }

                continue;
            }

            // Caso normal (sin cambio de unidad): insert-like
            if ($existing === null) {
                $l = new PmsEventoBeds24Link();
                $l->setEvento($evento);
                $l->setUnidadBeds24Map($map);
                $l->setOriginLink($rootLink);
                $l->setLastSeenAt($now);
                $l->markActive();
                $em->persist($l);
                $evento->addBeds24Link($l);
                $changed = true;
            } else {
                if ($existing->getOriginLink() !== $rootLink) {
                    $existing->setOriginLink($rootLink);
                    $changed = true;
                }
                if ($existing->getId() !== null) {
                    $existing->setLastSeenAt($now);
                    $changed = true;
                }
            }
        }

        // (UnidadBeds24Map cache en el evento removido; ya no se actualiza aquí.)

        return $changed;
    }

    /** @return PmsUnidadBeds24Map[] */
    private function findActiveMapsForUnidad(EntityManagerInterface $em, PmsUnidad $unidad): array
    {
        // Evitar queries dentro de onFlush (reduce conflictos con listeners de Stof/Gedmo).
        $maps = [];
        foreach ($unidad->getBeds24Maps() as $m) {
            if (!$m instanceof PmsUnidadBeds24Map) {
                continue;
            }

            // Soportar ambos nombres de getter (activo/isActivo) según tu entidad.
            $isActive = null;
            if (method_exists($m, 'isActivo')) {
                $isActive = (bool) ($m->isActivo() ?? false);
            } elseif (method_exists($m, 'getActivo')) {
                $isActive = (bool) ($m->getActivo() ?? false);
            }

            if ($isActive !== true) {
                continue;
            }

            $maps[] = $m;
        }

        usort($maps, static function (PmsUnidadBeds24Map $a, PmsUnidadBeds24Map $b): int {
            $aP = (bool) ($a->isEsPrincipal() ?? false);
            $bP = (bool) ($b->isEsPrincipal() ?? false);
            if ($aP !== $bP) {
                return $aP ? -1 : 1;
            }
            return (int) ($a->getId() ?? 0) <=> (int) ($b->getId() ?? 0);
        });

        return $maps;
    }

    private function resolvePrincipalMap(array $maps): ?PmsUnidadBeds24Map
    {
        foreach ($maps as $m) {
            if ($m instanceof PmsUnidadBeds24Map && ($m->isEsPrincipal() ?? false)) {
                return $m;
            }
        }

        foreach ($maps as $m) {
            if ($m instanceof PmsUnidadBeds24Map) {
                return $m;
            }
        }

        return null;
    }

    /** @param PmsUnidadBeds24Map[] $maps */
    private function orderMapsPrincipalFirst(array $maps, PmsUnidadBeds24Map $principal): array
    {
        usort($maps, static function (PmsUnidadBeds24Map $a, PmsUnidadBeds24Map $b) use ($principal): int {
            $aIs = ($a->getId() === $principal->getId());
            $bIs = ($b->getId() === $principal->getId());
            if ($aIs !== $bIs) {
                return $aIs ? -1 : 1;
            }

            $aP = (bool) ($a->isEsPrincipal() ?? false);
            $bP = (bool) ($b->isEsPrincipal() ?? false);
            if ($aP !== $bP) {
                return $aP ? -1 : 1;
            }

            return (int) ($a->getId() ?? 0) <=> (int) ($b->getId() ?? 0);
        });

        return $maps;
    }

    private function deactivateOrRemoveLink(PmsEventoCalendario $evento, PmsEventoBeds24Link $link, DateTimeImmutable $now): bool
    {
        // Si el link ya está marcado como borrado/sincronizado, no hacemos nada.
        if ($link->getStatus() === PmsEventoBeds24Link::STATUS_SYNCED_DELETED) {
            return false;
        }

        // Preferimos historial: si el link tiene bookId, no lo borramos; lo marcamos para que el sync
        // pueda enviar delete/move a Beds24 y mantener trazabilidad.
        $hasBookId = $link->getBeds24BookId() !== null && $link->getBeds24BookId() !== '';

        if ($hasBookId) {
            $link->markPendingDelete($now);
            if ($link->getId() !== null) {
                $link->setLastSeenAt($now);
            }
            return true;
        }

        // Si NO tiene bookId, es un link "local" aún no sincronizado: lo podemos remover sin riesgo.
        // orphanRemoval=true lo eliminará.
        $evento->removeBeds24Link($link);
        return true;
    }
}