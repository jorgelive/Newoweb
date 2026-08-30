<?php

declare(strict_types=1);

namespace App\Travel\EventListener;

use App\Travel\Entity\TravelSegmentoComponente;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;

/**
 * Garantiza la unicidad de la "hora de servicio completo": a lo sumo UN
 * componente promovido por cada (plantilla, día). Es decir, cada día relativo de
 * una plantilla (itinerarioContexto) puede tener una sola hora que represente el
 * horario global de la excursión.
 *
 * Vive a nivel de entidad (onFlush) a propósito: así la regla se cumple sin
 * importar la vía de escritura — el modal AJAX de logística, el CRUD suelto de
 * TravelSegmentoComponente, el CRUD anidado dentro del segmento/itinerario, o la
 * propia API. Al ver todo el flush a la vez, también resuelve el caso de varias
 * filas promovidas para el mismo día en una única edición (gana la primera).
 *
 * La clave de unicidad es (itinerarioContexto, día); `null` es su propio cubo,
 * tanto para el contexto (logística global del pool) como para el día (aplica a
 * todos los días).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class TravelSegmentoComponentePromocionUnicaListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(TravelSegmentoComponente::class);

        // 1. Componentes promovidos que entran/actualizan en este flush.
        $cambiadas = array_merge(
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates()
        );

        $promovidas = array_filter(
            $cambiadas,
            static fn ($e) => $e instanceof TravelSegmentoComponente && $e->isHoraServicioCompleto()
        );

        if (!$promovidas) {
            return;
        }

        // 2. Un ganador por clave (plantilla|día); las demás promovidas del mismo
        //    flush para esa clave se desmarcan. La promoción requiere plantilla:
        //    cualquier promoción global (itinerarioContexto null) se desmarca —
        //    la validación ya lo bloquea, esto es defensa a nivel de datos.
        $ganadorPorClave = [];
        foreach ($promovidas as $e) {
            /** @var TravelSegmentoComponente $e */
            if ($e->getItinerarioContexto() === null) {
                $this->desmarcar($e, $uow, $meta);
                continue;
            }
            $clave = $this->clave($e);
            if (isset($ganadorPorClave[$clave])) {
                $this->desmarcar($e, $uow, $meta);
            } else {
                $ganadorPorClave[$clave] = $e;
            }
        }

        // 3. Para cada ganador, se desmarca cualquier otra fila ya existente en BD
        //    con la misma clave (otros segmentos/registros de la misma plantilla y día).
        foreach ($ganadorPorClave as $ganador) {
            $qb = $em->getRepository(TravelSegmentoComponente::class)->createQueryBuilder('sc')
                ->where('sc.horaServicioCompleto = true');

            $ctx = $ganador->getItinerarioContexto();
            if ($ctx === null) {
                $qb->andWhere('sc.itinerarioContexto IS NULL');
            } else {
                $qb->andWhere('sc.itinerarioContexto = :ctx')->setParameter('ctx', $ctx);
            }

            $dia = $ganador->getDia();
            if ($dia === null) {
                $qb->andWhere('sc.dia IS NULL');
            } else {
                $qb->andWhere('sc.dia = :dia')->setParameter('dia', $dia);
            }

            foreach ($qb->getQuery()->getResult() as $otra) {
                if ($otra === $ganador) {
                    continue;
                }
                $this->desmarcar($otra, $uow, $meta);
            }
        }
    }

    private function clave(TravelSegmentoComponente $e): string
    {
        $ctx = $e->getItinerarioContexto()?->getId()?->toRfc4122() ?? 'null';
        $dia = $e->getDia() ?? 'null';

        return $ctx . '|' . $dia;
    }

    /**
     * Desmarca la promoción y registra el cambio en la unidad de trabajo del flush
     * en curso (según esté ya agendada o sea una entidad gestionada recién tocada).
     *
     * @param ClassMetadata<TravelSegmentoComponente> $meta
     */
    private function desmarcar(TravelSegmentoComponente $e, UnitOfWork $uow, ClassMetadata $meta): void
    {
        if (!$e->isHoraServicioCompleto()) {
            return;
        }

        $e->setHoraServicioCompleto(false);

        if ($uow->isScheduledForInsert($e) || $uow->isScheduledForUpdate($e)) {
            $uow->recomputeSingleEntityChangeSet($meta, $e);
        } else {
            $uow->computeChangeSet($meta, $e);
        }
    }
}
