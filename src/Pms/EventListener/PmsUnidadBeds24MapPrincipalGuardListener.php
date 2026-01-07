<?php

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsUnidadBeds24Map;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Listener de validación para PmsUnidadBeds24Map (módulo PMS)
 *
 * Regla de negocio:
 *  - Solo puede existir UNA combinación (Unidad PMS + Beds24Config)
 *    marcada como principal (esPrincipal = true) por unidad.
 *
 * Comportamiento actual:
 *  - “Barrera muda”: si detecta que ya existe un principal en DB para la unidad, revierte el flag del registro actual (setEsPrincipal(false))
 *    para que NO se persista como principal (tanto en INSERT como en UPDATE).
 *
 * TODO:
 *  - Reforzar a nivel DB (MySQL) usando:
 *      - columna generada (IF(es_principal=1, pms_unidad_id, NULL))
 *      - UNIQUE sobre esa columna
 *  - Esto evitará race conditions en escenarios concurrentes.
 */
//#[AsDoctrineListener(event: Events::preFlush)]
final class PmsUnidadBeds24MapPrincipalGuardListener
{
    public function preFlush(PreFlushEventArgs $args): void
    {
        $om = $args->getObjectManager();

        // Seguridad: OnFlush es ORM, pero tipamos por si algún día cambia el wiring.
        if (!$om instanceof EntityManagerInterface) {
            return;
        }

        $em  = $om;
        $uow = $em->getUnitOfWork();

        foreach ($uow->getIdentityMap() as $className => $entities) {
            if ($className !== PmsUnidadBeds24Map::class) {
                continue;
            }

            foreach ($entities as $entity) {
                if (!$entity instanceof PmsUnidadBeds24Map) {
                    continue;
                }

                // Solo validar cuando se intenta marcar como principal
                if (!$entity->isEsPrincipal()) {
                    continue;
                }

                $unidad = $entity->getPmsUnidad();
                if (!$unidad) {
                    continue;
                }

                // Verificar si ya existe otro principal para la misma unidad
                $qb = $em->createQueryBuilder();
                $qb
                    ->select('COUNT(m.id)')
                    ->from(PmsUnidadBeds24Map::class, 'm')
                    ->where('m.pmsUnidad = :unidad')
                    ->andWhere('m.esPrincipal = true')
                    ->setParameter('unidad', $unidad)
                ;

                // Si estamos editando el mismo registro, hay que excluirlo del conteo
                if ($entity->getId()) {
                    $qb->andWhere('m.id != :id')->setParameter('id', $entity->getId());
                }

                $count = (int) $qb->getQuery()->getSingleScalarResult();

                if ($count > 0) {
                    // Revertimos el cambio. En preFlush Doctrine calculará el changeset luego, así que no necesitamos recompute manual.
                    $entity->setEsPrincipal(false);

                    // Continuamos sin romper la UX.
                    continue;
                }
            }
        }
    }
}