<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Entity\MetaConfig;
use App\Exchange\Enum\ConnectivityProvider;
use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Message\Service\MessageDataResolverRegistry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Encolador para WhatsApp Meta.
 * Construye la entidad de cola tanto para enviar nuevos mensajes (OUTGOING)
 * como para notificar el estado de lectura de mensajes recibidos (INCOMING).
 */
readonly class WhatsappMetaSendEnqueuer implements ChannelEnqueuerInterface
{
    public function __construct(
        private EntityManagerInterface      $em,
        private MessageDataResolverRegistry $resolverRegistry,
        private LoggerInterface             $logger
    ) {}

    public function supports(MessageChannel $channel): bool
    {
        return $channel->getId() === 'whatsapp_meta';
    }

    public function createQueueEntity(Message $message, MessageChannel $channel, DateTimeImmutable $runAt): ?MessageQueueItemInterface
    {
        $conversation = $message->getConversation();
        if (!$conversation) {
            throw new RuntimeException('El mensaje no tiene una conversación asociada.');
        }

        // 🚧 **AQUÍ NO SE MIRA SI EL CANAL ESTÁ HABILITADO, y es deliberado.**
        //
        // Esto es CREAR la fila de la cola; el envío ocurre después, en `$runAt`, que puede ser
        // dentro de tres días. Preguntar aquí «¿está WhatsApp habilitado?» es contestar con el
        // estado de hoy una pregunta que se resolverá el jueves.
        //
        // Y no es teórico: el 10/09/2026 se midió el caso. La guía de llegada y el aviso de
        // check-out de un huésped se crearon el 07/09 a las 22:56 con el canal bloqueado desde
        // el panel, **y el aviso de check-out estaba programado para el 10/09 a las 12:00**. Se
        // dio por muerto tres días antes de tocarle salir. El canal se rehabilitó, y los dos
        // siguieron en `failed` para siempre —nadie reintenta lo que ya se declaró perdido—: el
        // huésped se alojó sin recibir ninguna de las dos cosas.
        //
        // El veto vive en {@see \App\Message\Service\Exchange\Tasks\WhatsappMetaSend\WhatsappMetaSendMappingStrategy},
        // que es donde el mensaje de verdad sale. **Un solo sitio**, con el estado de ese
        // momento. La cola ya es el mecanismo de aplazamiento; poner una segunda comprobación
        // aquí sería preguntar lo mismo dos veces y en el momento equivocado una de ellas.

        // =========================================================================
        // 1. CONFIGURACIÓN BASE COMPARTIDA
        // =========================================================================

        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);
        if (!$config) {
            throw new RuntimeException('No hay ninguna configuración activa de Meta WhatsApp en el sistema.');
        }

        // =====================================================================
        // 1. OBTENER EL NÚMERO DE DESTINO (La fuente de la verdad)
        // =====================================================================
        $targetPhone = $conversation->getGuestPhone();

        // Fallback: Si por alguna razón la conversación no tiene el teléfono guardado,
        // intentamos extraerlo de la entidad origen (PMS, Agencia, etc.) usando el Resolver.
        if (empty($targetPhone)) {
            $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());
            if ($resolver) {
                $targetPhone = $resolver->getPhoneNumber($conversation->getContextId());
            }
        }

        // Sin teléfono, WhatsApp NO APLICA: se devuelve `null`, no se lanza.
        //
        // El despachador distingue las dos respuestas —`null` es «este canal no aplica» y deja
        // el mensaje en `sin_canal`, vivo; una excepción es «lo intenté y se rompió» y lo deja
        // en `failed`—. Aquí se lanzaba, y un simple «todavía no tenemos su número» quedaba
        // registrado como avería.
        //
        // 🔥 Y `failed` no revive. Medido el 26/09/2026 con Melanie: reserva directa sin
        // teléfono, guía de llegada y check-out en `failed`; se fusionó su hilo con el de su
        // número y los dos siguieron muertos, porque el motor sólo resucita lo que está en
        // `sin_canal` (`MessageRuleEngine::syncPendingMessage()`). Es la misma corrección que
        // se hizo en `Beds24SendEnqueuer` el 14/09 con las reservas directas.
        if (empty($targetPhone)) {
            $this->logger->info(sprintf(
                'WhatsApp no aplica al mensaje %s: la conversación %s no tiene teléfono.',
                $message->getId()?->toRfc4122() ?? 'N/A',
                $conversation->getId()?->toRfc4122() ?? 'N/A'
            ));

            return null;
        }


        // Preparamos la entidad de cola
        $queue = new WhatsappMetaSendQueue();
        $queue->setMessage($message);
        $queue->setConfig($config);
        $queue->setDestinationPhone($targetPhone);
        $queue->setStatus(WhatsappMetaSendQueue::STATUS_PENDING);
        $queue->setRunAt($runAt);
        $queue->setRetryCount(0);
        $queue->setMaxAttempts(5);

        // =========================================================================
        // 2. BIFURCACIÓN: RECIBO DE LECTURA (INCOMING) VS ENVÍO (OUTGOING)
        // =========================================================================

        if ($message->getDirection() === Message::DIRECTION_INCOMING && $message->getStatus() === Message::STATUS_READ) {

            // 🔥 FLUJO A: MARCAR MENSAJE COMO LEÍDO EN META

            $remoteId = $message->getWhatsappMetaExternalId();
            if (!$remoteId) {
                // Si el mensaje entrante no tiene ID de Meta, abortamos silenciosamente
                // porque es imposible notificar la lectura a Facebook.
                return null;
            }

            $accion = 'MARK_WHATSAPP_MESSAGE_READ'; // Endpoint dedicado al status

        } else {

            // 🔥 FLUJO B: ENVÍO DE MENSAJE NUEVO O PLANTILLA

            $template = $message->getTemplate();
            $lang = (string) $conversation->getIdioma()->getId();   // el código ISO es la clave del idioma
            $isSessionActive = $conversation->isWhatsappSessionActive();

            if (!$isSessionActive) {
                if ($template === null) {
                    throw new RuntimeException(
                        'Operación denegada. La ventana de 24 horas de WhatsApp ha caducado. ' .
                        'Para iniciar o retomar el contacto con este huésped, DEBES seleccionar una Plantilla Oficial.'
                    );
                }

                if (!$template->hasWhatsappMetaOfficialData($lang)) {
                    throw new RuntimeException(sprintf(
                        'La ventana de 24 horas ha caducado. La plantilla seleccionada ("%s") ' .
                        'no tiene un ID Oficial de Meta configurado para el idioma "%s".',
                        $template->getName(),
                        $lang
                    ));
                }
            }

            // Meta usa la misma acción/endpoint tanto para plantillas como para texto libre
            $accion = 'SEND_WHATSAPP_MESSAGE';
        }

        // =========================================================================
        // 3. ASIGNACIÓN DEL ENDPOINT Y RETORNO
        // =========================================================================

        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'provider' => ConnectivityProvider::META,
            'accion'   => $accion,
            'activo'   => true
        ]);

        if (!$endpoint) {
            throw new RuntimeException("Falta el Endpoint técnico en la base de datos para la acción de Meta: {$accion}");
        }

        $queue->setEndpoint($endpoint);

        return $queue;
    }

    public function isAlreadyEnqueued(Message $message): bool
    {
        if ($message->getId() === null) {
            return false;
        }

        // =====================================================================
        // CAPA 1: MEMORIA (Unit of Work)
        // Previene duplicados si se llama al Dispatcher varias veces
        // en el mismo request, ANTES del flush().
        // =====================================================================
        $uow = $this->em->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof WhatsappMetaSendQueue) {
                $queuedMessage = $entity->getMessage();

                if ($queuedMessage !== null && $queuedMessage->getId() !== null) {
                    // 🔥 COMPARACIÓN SEGURA
                    if ($queuedMessage->getId()->equals($message->getId())) {
                        return true;
                    }
                }
            }
        }

        // =====================================================================
        // CAPA 2: BASE DE DATOS FÍSICA
        // Previene duplicados contra colas que se crearon en requests anteriores
        // o por otros workers/procesos.
        //
        // ⚠️ El id con el tipo `uuid` explícito, NO la entidad: ver el porqué —y el destrozo
        // que causó en Beds24— en `Beds24SendEnqueuer::isAlreadyEnqueued()`.
        // =====================================================================
        $qb = $this->em->createQueryBuilder();
        $count = (int) $qb->select('COUNT(q.id)')
            ->from(WhatsappMetaSendQueue::class, 'q')
            ->where('q.message = :message')
            ->andWhere('q.status != :status_cancelled')
            ->setParameter('message', $message->getId(), UuidType::NAME)
            ->setParameter('status_cancelled', WhatsappMetaSendQueue::STATUS_CANCELLED)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Valida si las condiciones actuales permiten el envío por WhatsApp.
     * A diferencia de Beds24, WhatsApp es un canal universal, por lo que
     * siempre es válido independientemente de si la reserva es OTA o Directa.
     */
    public function isValid(Message $message): bool
    {
        $conversation = $message->getConversation();

        return $conversation !== null && $this->disponiblePara($conversation);
    }

    /**
     * Sin teléfono no hay WhatsApp, y con el canal vetado tampoco.
     *
     * ⚠️ **Que aquí se mire el veto y en `createQueueEntity()` no, no es una incoherencia.**
     * Son dos preguntas distintas: ésta es «¿le ofrezco WhatsApp al operador AHORA?», y el
     * ahora de una casilla del panel es el ahora de verdad. Sin la comprobación, el panel
     * ofrecería WhatsApp en un hilo que Meta ya rechazó.
     *
     * Encolar es lo contrario: la fila corre en `$runAt`, que puede ser dentro de tres días, y
     * ahí el estado de hoy no dice nada. Por eso el veto del ENVÍO vive en un solo sitio, la
     * estrategia de mapeo, y no se repite al crear la fila.
     *
     * El asunto no entra: un teléfono alcanza a la persona, no a una de sus reservas.
     */
    public function disponiblePara(
        MessageConversation $conversacion,
        ?string $asuntoType = null,
        ?string $asuntoId = null
    ): bool {
        return !$conversacion->isWhatsappDisabled()
            && trim((string) $conversacion->getGuestPhone()) !== '';
    }

}