<?php

declare(strict_types=1);

namespace App\Message\EventListener\Queue;

use App\Message\Entity\MessageConversation;
use App\Message\Service\MessageRuleEngine;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Escucha cambios en las conversaciones (reservas) y dispara el motor de reglas
 * de forma asíncrona segura justo después de que la base de datos se ha guardado.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 100)]
#[AsDoctrineListener(event: Events::postFlush, priority: 100)]
final class ConversationSchedulingSubscriber
{
    /** @var MessageConversation[] */
    private array $conversationsToSync = [];
    private bool $isSyncing = false;

    public function __construct(
        private readonly MessageRuleEngine $ruleEngine
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        // 1. Recolectar conversaciones NUEVAS (ej. Webhook de nueva reserva)
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof MessageConversation) {
                // Usamos el ID como llave para evitar duplicados en el array
                $this->conversationsToSync[$entity->getId()->toRfc4122()] = $entity;
            }
        }

        // 2. Recolectar conversaciones MODIFICADAS (ej. Cambio de fechas o estado)
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof MessageConversation) {
                $changeSet = $uow->getEntityChangeSet($entity);

                // Solo nos interesa re-evaluar si cambiaron los hitos, el origen o el estado
                if (isset($changeSet['contextData']) || isset($changeSet['status'])) {
                    $this->conversationsToSync[$entity->getId()->toRfc4122()] = $entity;
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        // Seguro anti-bucles infinitos (ya que el RuleEngine hará su propio flush al final)
        if (empty($this->conversationsToSync) || $this->isSyncing) {
            return;
        }

        $this->isSyncing = true;

        // Copiamos a variable local y limpiamos la propiedad de clase ANTES de procesar
        $conversations = $this->conversationsToSync;
        $this->conversationsToSync = [];

        try {
            foreach ($conversations as $conversation) {
                $this->ruleEngine->syncConversationRules($conversation);
            }
        } finally {
            $this->isSyncing = false;
        }
    }
}