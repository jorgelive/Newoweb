<?php

declare(strict_types=1);

namespace App\Message\Factory;

use App\Entity\Maestro\MaestroIdioma;
use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\SincronizadorDeEnlaceInterface;
use App\Message\Enum\IdentidadTipo;
use Psr\Log\LoggerInterface;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Service\Conversacion\ResolutorDeHilo;
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
        private ResolutorDeHilo $resolutor,
        private EnlacesDeConversacion $enlaces,
        private LoggerInterface $logger,
        #[AutowireIterator('app.message.sincronizador_enlace')]
        private iterable $sincronizadores = [],
    ) {}

    public function upsertFromContext(MessageContextInterface $context, bool $flush = false): MessageConversation
    {
        $repository = $this->entityManager->getRepository(MessageConversation::class);

        // 0. El ASUNTO manda si ya tiene hilo. Es el camino DURADERO.
        //
        // ⚠️ Va ANTES que la identidad, y ese orden es el arreglo. La identidad sirve para
        // DESCUBRIR a la persona la primera vez; en cuanto el asunto tiene enlace titular, ése
        // es su hilo y no se discute. Con la identidad mandando siempre pasaba esto:
        //
        //   - corriges el teléfono de una reserva y el nuevo número resulta ser de otro hilo
        //     → el asunto MIGRABA solo a ese hilo, en silencio. Y como el sincronizador busca
        //       el enlace dentro de la conversación, creaba otro: la misma reserva colgando de
        //       dos hilos, con DOS agendas, y el huésped recibiendo cada automático dos veces.
        //
        // Con el titular delante, el asunto se queda donde está y el número nuevo es sólo un
        // identificador más. Unir dos historiales sigue siendo decisión de persona:
        // `app:message:fusionar-hilos`.
        $conversation = $this->enlaces->hiloTitularDe($context->getContextType(), $context->getContextId());

        // 1. Por IDENTIDAD: el hilo es de la PERSONA.
        //
        // ⚠️ Este orden es el que impide seguir duplicando hilos. Antes se buscaba sólo por
        // `(contextType, contextId)` —una conversación por reserva— mientras WhatsApp buscaba
        // por teléfono: dos criterios sobre la misma tabla, y de ahí los 20 teléfonos con más
        // de un hilo que mide `ConversacionEnlaceInterface`.
        //
        // Los identificadores los declara el dominio, que es quien sabe cuáles son: para una
        // reserva de OTA el importante es el `bookId` de Beds24, porque nace sin teléfono y sin
        // correo y aun así se le puede escribir.
        $identificadores = $context->getIdentificadores();
        $conversation ??= $this->porIdentificadores($identificadores);

        // 2. Si no, por la llave vieja: es lo que encuentra los hilos que ya existen y todavía
        //    no tienen identidades registradas.
        $conversation ??= $repository->findOneBy([
            'contextType' => $context->getContextType(),
            'contextId'   => $context->getContextId(),
        ]);

        // 3. Si no existe, la instanciamos (NACIMIENTO)
        if (!$conversation) {
            $conversation = new MessageConversation(
                $context->getContextType(),
                $context->getContextId()
            );
            $this->entityManager->persist($conversation);
        }

        // 3-bis. Si el hilo que encontramos nació como WALK-IN, ahora sí sabemos de qué va.
        //
        // Pasa cuando alguien escribe por WhatsApp ANTES de tener reserva: nace `manual`, y al
        // reservar la resolución por identidad devuelve ese mismo hilo. Sin esta línea la
        // cabecera se quedaba en `manual` y el agente —que aún decide por `getContextType()`—
        // le diría que no tiene ninguna reserva, teniéndola enlazada dos líneas más abajo.
        if ($conversation->promoverDesdeManual($context->getContextType(), $context->getContextId())) {
            $this->logger->info('Hilo manual promovido a su asunto real.', [
                'conversacion' => (string) $conversation->getId(),
                'context_type' => $context->getContextType(),
                'context_id'   => $context->getContextId(),
            ]);
        }

        // 4. Se registran los identificadores. Idempotente, y hace que la próxima resolución
        //    —venga por donde venga— caiga en este mismo hilo.
        foreach ($identificadores as $tipo => $valor) {
            if (($caso = IdentidadTipo::tryFrom((string) $tipo)) !== null) {
                $this->resolutor->vincular($conversation, $caso, $valor, 'contexto');
            }
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

    /**
     * El primer hilo que reconozca alguno de estos identificadores.
     *
     * ⚠️ **Si dos apuntan a hilos distintos NO se elige**: se devuelve `null` y nace uno nuevo,
     * que es lo reversible. Unir dos historiales porque comparten un teléfono es una decisión
     * de persona, y ya hay 31 hilos esperando esa revisión — meter más por la puerta de atrás
     * no ayuda.
     *
     * @param array<string, string> $identificadores
     */
    private function porIdentificadores(array $identificadores): ?MessageConversation
    {
        $encontrados = [];

        foreach ($identificadores as $tipo => $valor) {
            $caso = IdentidadTipo::tryFrom((string) $tipo);

            if ($caso === null) {
                continue;
            }

            if (($hilo = $this->resolutor->porIdentidad($caso, $valor)) !== null) {
                $encontrados[(string) $hilo->getId()] = $hilo;
            }
        }

        if (count($encontrados) > 1) {
            $this->logger->warning('Identificadores de un mismo contexto apuntan a hilos distintos: no se une ninguno.', [
                'identificadores' => array_keys($identificadores),
                'hilos' => array_keys($encontrados),
            ]);

            return null;
        }

        return $encontrados === [] ? null : reset($encontrados);
    }
}