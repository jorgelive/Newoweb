<?php

declare(strict_types=1);

namespace App\Message\EventListener;

use App\Message\Dispatch\AvisarFusionSugeridaDispatch;
use App\Message\Dto\FusionSugerida;
use App\Message\Entity\MessageConversation;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Cuando a un hilo se le apunta una fusión sugerida NUEVA, se avisa al equipo.
 *
 * «Nueva» es que apunte a otro hilo que el que ya apuntaba: el recálculo de una reserva vuelve a
 * pasar por el resolutor varias veces al día y {@see MessageConversation::sugerirFusion()} no
 * toca nada si es la misma, así que aquí sólo llega el primer choque.
 *
 * Se recoge en `onFlush` (ahí se sabe qué cambió) y se despacha en `postFlush`, con el dato ya en
 * la base: el handler lo lee de ahí. Mismo patrón que `PmsChoqueNocheExtraListener`.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class FusionSugeridaListener
{
    /** @var array<string, true> */
    private array $pendientes = [];

    public function __construct(private readonly MessageBusInterface $bus) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entidad) {
            if (!$entidad instanceof MessageConversation) {
                continue;
            }

            $ahora = $entidad->fusionSugerida();
            $cambio = $uow->getEntityChangeSet($entidad)['fusionSugerida'] ?? null;

            if ($ahora === null || $cambio === null) {
                continue;
            }

            if (FusionSugerida::desde($cambio[0] ?? null)?->con !== $ahora->con) {
                $this->pendientes[(string) $entidad->getId()] = true;
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

        foreach ($ids as $id) {
            $this->bus->dispatch(new AvisarFusionSugeridaDispatch($id));
        }
    }
}
