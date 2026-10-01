<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Dispatch\RevisarChoqueDeNocheExtraDispatch;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Cuando un CANAL crea o cambia una estancia, se mira después si choca con una noche extra.
 *
 * Sólo en el pull (y el webhook, que entra igual): lo que hace una persona ya lo frena
 * {@see PmsEventoCalendarioSolapeListener} antes de guardar. Lo que manda el canal no se puede
 * rechazar —ya ha pasado—, sólo avisar. Fase 5 de docs/PlanHorarioExtraSinEventos.md.
 *
 * Se recoge en `onFlush` (ahí se sabe qué cambió) y se despacha en `postFlush`, cuando los datos
 * ya están en la base: el handler los lee de ahí.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class PmsChoqueNocheExtraListener
{
    /** Lo que, si cambia, puede llevar una noche encima de otra. */
    private const array CAMPOS = ['inicio', 'fin', 'pmsUnidad', 'estado', 'entradaTemprana', 'salidaTardia'];

    /** @var array<string, true> */
    private array $pendientes = [];

    public function __construct(
        private readonly SyncContext $syncContext,
        private readonly MessageBusInterface $bus,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->syncContext->isPull()) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entidad) {
            if ($entidad instanceof PmsEventoCalendario) {
                $this->recolectar($entidad);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entidad) {
            if ($entidad instanceof PmsEventoCalendario
                && array_intersect(self::CAMPOS, array_keys($uow->getEntityChangeSet($entidad))) !== []
            ) {
                $this->recolectar($entidad);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pendientes === []) {
            return;
        }

        // Se vacía ANTES de despachar: si el bus lanzara, no se reenviaría en el siguiente flush.
        $ids = array_keys($this->pendientes);
        $this->pendientes = [];

        $this->bus->dispatch(new RevisarChoqueDeNocheExtraDispatch($ids));
    }

    private function recolectar(PmsEventoCalendario $evento): void
    {
        if ($evento->getId() === null || !in_array($evento->getEstado()?->getId(), PmsEventoEstado::OCUPAN_UNIDAD, true)) {
            return;
        }

        $this->pendientes[(string) $evento->getId()] = true;
    }
}
