<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\Cotizacion;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Sostiene la invariante que hace innecesario desempatar: **una publicada por propuesta**.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Una propuesta tiene varias filas —sus históricos, la aprobada, la operativa— y todas comparten
 * número. Si dos estuvieran publicadas, el provider tendría que elegir cuál sirve, y ese desempate
 * ya mordió una vez: `findOneBy` entregaba la foto congelada y un enlace que el cliente ya tenía
 * dejaba de funcionar sin que cambiara nada suyo.
 *
 * Con esta invariante el problema **no se resuelve mejor: deja de existir**. El provider filtra por
 * `publicado = true` y sólo puede encontrar una.
 *
 * ⚠️ **Va en el servidor, no en el botón.** El editor no es el único que escribe: la API acepta un
 * `PATCH`, y mañana lo hará el agente. Una invariante que sólo cumple la interfaz no es una
 * invariante — es una costumbre.
 *
 * ── Por qué DQL y no la unidad de trabajo ───────────────────────────────────
 * Despublicar a las hermanas con un `UPDATE` directo evita reentrar en el flush y, sobre todo,
 * evita hidratar filas que llevan varios JSON grandes dentro. La misma razón por la que
 * `$historicos` es `EXTRA_LAZY`: cargar cotizaciones enteras para tocarles un booleano es cómo se
 * llega a un «Out of sort memory».
 *
 * ⚠️ Se ejecuta en `postFlush`, no en `onFlush`: si la fila que se está publicando aún no tiene id
 * —recién creada— el `UPDATE` la excluiría mal, o peor, se despublicaría a sí misma.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class CotizacionPublicadaEventListener
{
    /**
     * Las que empezaron a publicarse en este flush.
     *
     * @var list<Cotizacion>
     */
    private array $recienPublicadas = [];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Cotizacion && $entity->isPublicado()) {
                $this->recienPublicadas[] = $entity;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Cotizacion) {
                continue;
            }

            $cambios = $uow->getEntityChangeSet($entity);

            // Sólo cuando PASA a publicada. Guardar una que ya lo estaba no toca a nadie más.
            if (isset($cambios['publicado']) && $cambios['publicado'][1] === true) {
                $this->recienPublicadas[] = $entity;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->recienPublicadas === []) {
            return;
        }

        $pendientes = $this->recienPublicadas;
        $this->recienPublicadas = [];

        $em = $args->getObjectManager();

        foreach ($pendientes as $cotizacion) {
            $padre = $cotizacion->getFile() ?? $cotizacion->getCatalogo();

            if ($padre === null || $cotizacion->getId() === null) {
                continue;
            }

            $campo = $cotizacion->getFile() !== null ? 'file' : 'catalogo';

            // ⚠️ **El id con `UuidType`, no la entidad.** Pasar el objeto como parámetro de una
            // relación con clave UUID **no casa ninguna fila y no da error**: la consulta devuelve
            // cero y parece que no había hermanas que despublicar. Se descubrió porque la
            // invariante no se cumplía en una prueba con datos reales — a ojo, el código está bien.
            // Es el mismo patrón que ya usan los providers públicos.
            $em->createQuery(sprintf(
                'UPDATE %s c SET c.publicado = false
                 WHERE c.%s = :padre AND c.propuesta = :propuesta AND c.id != :yo AND c.publicado = true',
                Cotizacion::class,
                $campo,
            ))
                ->setParameter('padre', $padre->getId(), UuidType::NAME)
                ->setParameter('propuesta', $cotizacion->getPropuesta())
                ->setParameter('yo', $cotizacion->getId(), UuidType::NAME)
                ->execute();
        }
    }
}
