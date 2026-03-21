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
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Message\Service\MessageDataResolverRegistry;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Encolador para WhatsApp Meta.
 * Construye la entidad de cola tanto para enviar nuevos mensajes (OUTGOING)
 * como para notificar el estado de lectura de mensajes recibidos (INCOMING).
 */
class WhatsappMetaSendEnqueuer implements ChannelEnqueuerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageDataResolverRegistry $resolverRegistry
    ) {}

    public function supports(MessageChannel $channel): bool
    {
        return $channel->getId() === 'whatsapp_meta';
    }

    public function createQueueEntity(Message $message, MessageChannel $channel, \DateTimeImmutable $runAt): ?MessageQueueItemInterface
    {
        $conversation = $message->getConversation();
        if (!$conversation) {
            throw new RuntimeException('El mensaje no tiene una conversación asociada.');
        }

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

        // Si después de todo no hay teléfono, no podemos enviar un WhatsApp.
        // Retornamos null para que el Dispatcher ignore silenciosamente este canal
        // o lanzamos una excepción si prefieres que sea un error crítico.
        if (empty($targetPhone)) {
            throw new RuntimeException(
                sprintf('WhatsappMetaSendEnqueuer: No se pudo resolver el número de teléfono para la conversación: %s, con el contexto: %s.',
                $conversation->getId()->toRfc4122(),
                $conversation->getContextType())
            );
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
            $lang = $conversation->getIdioma()->getId();
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
}