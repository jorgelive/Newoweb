<?php

declare(strict_types=1);

namespace App\Message\Factory;

use App\Entity\Maestro\MaestroIdioma;
use App\Message\Contract\MessageContextInterface;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * MessageConversationFactory
 *
 * Se encarga de crear o actualizar (Upsert) una conversación basándose en su Contexto.
 * Mantiene actualizado el Snapshot (Nombre y Teléfono) para listados rápidos.
 */
class MessageConversationFactory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * Realiza el Upsert lógico de la conversación.
     * * @param MessageContextInterface $context El adaptador de la entidad origen (Ej: PmsReserva)
     * @param bool $flush Si es true, ejecuta el flush inmediatamente. Útil si no estás en un listener.
     * @return MessageConversation
     */
    public function upsertFromContext(MessageContextInterface $context, bool $flush = false): MessageConversation
    {
        $repository = $this->entityManager->getRepository(MessageConversation::class);

        // 1. Buscamos por la Llave Lógica Compuesta (Join Lógico)
        $conversation = $repository->findOneBy([
            'contextType' => $context->getContextType(),
            'contextId'   => $context->getContextId(),
        ]);

        // 2. Si no existe, la instanciamos y la persistimos (NACIMIENTO)
        if (!$conversation) {
            $conversation = new MessageConversation(
                $context->getContextType(),
                $context->getContextId()
            );

            // 🔥 REGLA DE NEGOCIO: El idioma solo se hereda del contexto en la CREACIÓN.
            // Extraemos las 2 primeras letras por seguridad (Ej: si llega 'es_ES' lo dejamos en 'es')
            $langCode = substr($context->getContextLanguage(), 0, 2) ?: MaestroIdioma::DEFAULT_IDIOMA;

            // Usamos getReference para crear un objeto "proxy" y no gastar un SELECT en BD
            $idiomaRef = $this->entityManager->getReference(MaestroIdioma::class, strtolower($langCode));

            // Asignamos la relación ManyToOne
            $conversation->setIdioma($idiomaRef);

            $this->entityManager->persist($conversation);
        }

        // 1. Snapshot de contacto
        $conversation->setGuestName($context->getContextName());
        $conversation->setGuestPhone($context->getContextPhone());

        // 2. Llenado estricto del JSON (Agnóstico)
        $conversation->setContextOrigin($context->getOrigin());
        $conversation->setContextStatusTag($context->getStatusTag());
        $conversation->setContextMilestones($context->getMilestones());
        $conversation->setContextItems($context->getItems());
        $conversation->setContextFinancials($context->getFinancialTotal(), $context->isFinancialCleared());

        // 3. AUTO-ARCHIVADO y REACTIVACIÓN
        if ($context->isArchivable()) {
            $conversation->setStatus(MessageConversation::STATUS_ARCHIVED);
        } else {
            if ($conversation->getStatus() === MessageConversation::STATUS_ARCHIVED) {
                $conversation->setStatus(MessageConversation::STATUS_OPEN);
            }
        }

        if ($flush) {
            $this->entityManager->flush();
        }

        return $conversation;
    }
}