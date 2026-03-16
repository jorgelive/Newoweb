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
 * Identifica el contexto (Insert vs Update) para proveer triggers precisos al motor.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 100)]
#[AsDoctrineListener(event: Events::postFlush, priority: 100)]
final class ConversationSchedulingSubscriber
{
    /** @var array<string, MessageConversation> */
    private array $conversationsToInsert = [];

    /** @var array<string, MessageConversation> */
    private array $conversationsToUpdate = [];

    private bool $isSyncing = false;

    public function __construct(
        private readonly MessageRuleEngine $ruleEngine
    ) {}

    /**
     * Intercepta la Unidad de Trabajo de Doctrine antes de escribir en BD para
     * clasificar las conversaciones afectadas en nuevas (Insert) o modificadas (Update).
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        // 1. Recolectar conversaciones NUEVAS (ej. Webhook de nueva reserva)
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof MessageConversation) {
                // Se marca explícitamente como INSERT usando su UUID como llave
                $this->conversationsToInsert[$entity->getId()->toRfc4122()] = $entity;
            }
        }

        // 2. Recolectar conversaciones MODIFICADAS (ej. Cambio de fechas o estado por cron/usuario)
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof MessageConversation) {
                $changeSet = $uow->getEntityChangeSet($entity);

                // Solo re-evaluamos si cambiaron datos críticos (fechas, origen, estado)
                if (isset($changeSet['contextData']) || isset($changeSet['status'])) {
                    $id = $entity->getId()->toRfc4122();
                    // Si por casualidad también estaba en insert, priorizamos el insert.
                    if (!isset($this->conversationsToInsert[$id])) {
                        $this->conversationsToUpdate[$id] = $entity;
                    }
                }
            }
        }
    }

    /**
     * Dispara el Motor de Reglas después de que los datos son seguros en la BD.
     * Pasa el trigger correspondiente para que el motor aplique las heurísticas adecuadas.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        // Seguro anti-bucles infinitos inducidos por el propio flush del RuleEngine
        if ((empty($this->conversationsToInsert) && empty($this->conversationsToUpdate)) || $this->isSyncing) {
            return;
        }

        $this->isSyncing = true;

        // Copiamos a variables locales y limpiamos la memoria ANTES de iterar
        $inserts = $this->conversationsToInsert;
        $updates = $this->conversationsToUpdate;

        $this->conversationsToInsert = [];
        $this->conversationsToUpdate = [];

        try {
            foreach ($inserts as $conversation) {
                $this->ruleEngine->syncConversationRules($conversation, MessageRuleEngine::TRIGGER_INSERT);
            }
            foreach ($updates as $conversation) {
                $this->ruleEngine->syncConversationRules($conversation, MessageRuleEngine::TRIGGER_UPDATE);
            }
        } finally {
            $this->isSyncing = false;
        }
    }
}