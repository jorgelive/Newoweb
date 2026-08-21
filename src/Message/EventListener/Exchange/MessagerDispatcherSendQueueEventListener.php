<?php

declare(strict_types=1);

namespace App\Message\EventListener\Exchange;

use App\Exchange\Dispatch\RunExchangeTaskDispatch;
use App\Message\Contract\MessageQueueItemInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lanza la tarea del motor en cuanto nace una cola de envío.
 *
 * ── 🔥 ES AGNÓSTICO AL CANAL, y no lo era ───────────────────────────────────
 * Nombraba los canales **a mano en cuatro sitios**: dos `instanceof` al recoger y dos `if` al
 * despachar. Y olvidar uno **no rompía nada**: la cola se creaba, el panel decía «encolado», y
 * el mensaje no salía nunca.
 *
 * Pasó con el correo el 20/08/2026 — encolado, con destino y asunto correctos, esperando a que
 * alguien lo lanzara. El fallo más caro de este módulo siempre es el que no da error.
 *
 * Ahora se recorren las colas por su interfaz y cada una dice qué tarea la manda
 * ({@see MessageQueueItemInterface::getSendTaskName()}). **Un canal nuevo no toca este archivo.**
 * Misma regla que `Message::addQueue()`.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 200)]
#[AsDoctrineListener(event: Events::postFlush, priority: 200)]
final class MessagerDispatcherSendQueueEventListener
{
    /** @var array<string, list<string>> tarea => ids de cola */
    private array $pendientes = [];

    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        // Toda cola nueva nace PENDING: se recoge directa.
        foreach ($uow->getScheduledEntityInsertions() as $entidad) {
            $this->recogerSiEstaPendiente($entidad);
        }

        // Y de las modificadas, sólo las que ACABAN de volver a `pending` — una reprogramación
        // o un reintento. Mirar el change set evita despertar al motor por cualquier cambio.
        foreach ($uow->getScheduledEntityUpdates() as $entidad) {
            if (!$entidad instanceof MessageQueueItemInterface) {
                continue;
            }

            $cambios = $uow->getEntityChangeSet($entidad);

            if (isset($cambios['status']) && $cambios['status'][1] === 'pending') {
                $this->recogerSiEstaPendiente($entidad);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        // Se vacía ANTES de despachar: si el bus fallara, quedarían ids repetidos esperando en
        // un worker de larga vida y se volverían a lanzar en el siguiente flush ajeno.
        $porTarea = $this->pendientes;
        $this->pendientes = [];

        foreach ($porTarea as $tarea => $ids) {
            $this->bus->dispatch(new RunExchangeTaskDispatch($tarea, array_values(array_unique($ids))));
        }
    }

    private function recogerSiEstaPendiente(object $entidad): void
    {
        if (!$entidad instanceof MessageQueueItemInterface || $entidad->getStatus() !== 'pending') {
            return;
        }

        $this->pendientes[$entidad->getSendTaskName()][] = (string) $entidad->getId();
    }
}
