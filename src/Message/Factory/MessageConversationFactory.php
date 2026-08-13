<?php

declare(strict_types=1);

namespace App\Message\Factory;

use App\Entity\Maestro\MaestroIdioma;
use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\SincronizadorDeEnlaceInterface;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * MessageConversationFactory
 *
 * Se encarga de crear o actualizar (Upsert) una conversación basándose en su Contexto.
 * Mantiene actualizado el Snapshot (Nombre y Teléfono) para listados rápidos.
 */
readonly class MessageConversationFactory
{
    /**
     * @param iterable<SincronizadorDeEnlaceInterface> $sincronizadores
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[AutowireIterator('app.message.sincronizador_enlace')]
        private iterable $sincronizadores = [],
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
        $conversation->setContextAgency($context->getAgencyId());
        $conversation->setContextStatusTag($context->getStatusTag());
        $conversation->setContextVinculo($context->getVinculo());
        $conversation->setContextMilestones($context->getMilestones());
        $conversation->setContextItems($context->getItems());
        $conversation->setContextFinancials($context->getFinancialTotal(), $context->isFinancialCleared());

        // 5. AUTO-ARCHIVADO y REACTIVACIÓN
        //
        // ⚠️ CENTINELA: esto razona por HILO y el paso 6 razona por ASUNTO.
        //
        // Cancelar una reserva cierra la conversación ENTERA. Hoy da igual —un hilo, un asunto—,
        // pero en cuanto se fusionen los hilos duplicados por persona, cancelar la reserva A
        // cerrará el hilo y silenciará las agendas VIVAS de B y de C: el motor descarta las
        // reglas de una conversación cerrada.
        //
        // No se cambia ahora a propósito: alterar cuándo se cierra un hilo es un cambio de
        // comportamiento en producción que no hace falta todavía. Pero es el primer sitio que
        // hay que tocar el día de la fusión, y por eso queda escrito aquí y no sólo en el doc.
        if ($context->isCancelled()) {
            $conversation->setStatus(MessageConversation::STATUS_CLOSED); //Cambiado
        } else {
            if ($conversation->getStatus() === MessageConversation::STATUS_CLOSED) { //Cambiado
                $conversation->setStatus(MessageConversation::STATUS_OPEN);
            }
        }

        // 6. EL ENLACE DEL ASUNTO, al día en el mismo movimiento
        //
        // Va aquí y no en un listener aparte porque este método es EL sitio por el que pasa cada
        // cambio de una reserva (`PmsReservaRecalculoService` lo llama en cada recálculo). Un
        // enlace que se refrescara en otro punto podría quedarse atrás sin que nada lo delatara:
        // el motor lee el enlace, así que unas fechas viejas ahí son mensajes en el día
        // equivocado.
        //
        // Sin sincronizador para este `context_type` no pasa nada: el asunto se queda sin enlace
        // y todo sigue por el camino de siempre, que es el fallo seguro mientras los negocios se
        // van enchufando uno a uno.
        foreach ($this->sincronizadores as $sincronizador) {
            if ($sincronizador->supports($context->getContextType())) {
                $sincronizador->sincronizar($conversation, $context);
                break;
            }
        }

        if ($flush) {
            $this->entityManager->flush();
        }

        return $conversation;
    }
}