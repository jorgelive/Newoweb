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

    public function upsertFromContext(MessageContextInterface $context, bool $flush = false): MessageConversation
    {
        $repository = $this->entityManager->getRepository(MessageConversation::class);

        // 1. Buscamos por la Llave Lógica Compuesta (Join Lógico)
        $conversation = $repository->findOneBy([
            'contextType' => $context->getContextType(),
            'contextId'   => $context->getContextId(),
        ]);

        // 2. Si no existe, la instanciamos (NACIMIENTO)
        if (!$conversation) {
            $conversation = new MessageConversation(
                $context->getContextType(),
                $context->getContextId()
            );
            $this->entityManager->persist($conversation);
        }

        // =====================================================================
        // 🔥 GESTIÓN DE IDIOMA CON CERROJO (Sin redundancias)
        // =====================================================================
        if (!$conversation->isIdiomaFijado()) {
            // Extraemos los 2 primeros caracteres directo del contrato (ej: de 'en_US' a 'en')
            $langCode = substr($context->getContextLanguage() ?? MaestroIdioma::DEFAULT_IDIOMA, 0, 2);

            // Inyectamos la referencia directamente sin ensuciar con llamadas extra
            $idiomaRef = $this->entityManager->getReference(MaestroIdioma::class, $langCode);
            $conversation->setIdioma($idiomaRef);
        }

        // 3. Snapshot de contacto
        $conversation->setGuestName($context->getContextName());
        $conversation->setGuestPhone($context->getContextPhone());

        // 4. Llenado estricto del JSON (Agnóstico)
        $conversation->setContextOrigin($context->getOrigin());
        $conversation->setContextStatusTag($context->getStatusTag());
        $conversation->setContextMilestones($context->getMilestones());
        $conversation->setContextItems($context->getItems());
        $conversation->setContextFinancials($context->getFinancialTotal(), $context->isFinancialCleared());

        // 5. AUTO-ARCHIVADO y REACTIVACIÓN
        if ($context->isCancelled()) {
            $conversation->setStatus(MessageConversation::STATUS_CLOSED); //Cambiado
        } else {
            if ($conversation->getStatus() === MessageConversation::STATUS_CLOSED) { //Cambiado
                $conversation->setStatus(MessageConversation::STATUS_OPEN);
            }
        }

        if ($flush) {
            $this->entityManager->flush();
        }

        return $conversation;
    }
}