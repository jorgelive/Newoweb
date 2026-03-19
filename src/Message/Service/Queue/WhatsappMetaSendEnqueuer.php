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

        $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());
        if (!$resolver) {
            throw new RuntimeException("No se encontró un Resolver para el contexto: {$conversation->getContextType()}");
        }

        // 1. Obtener el Teléfono Inicial (Snapshot para la vista)
        $phone = $resolver->getPhoneNumber($conversation->getContextId());

        if (empty($phone)) {
            throw new RuntimeException('El huésped no tiene un número de teléfono registrado válido para enviar el mensaje.');
        }

        // 2. Obtener la Configuración Global
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);
        if (!$config) {
            throw new RuntimeException('No hay ninguna configuración activa de Meta WhatsApp en el sistema.');
        }

        $accion = !$isSessionActive ? 'SEND_WHATSAPP_TEMPLATE' : 'SEND_WHATSAPP_MESSAGE';

        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
                'provider' => ConnectivityProvider::META,
                'accion'   => $accion,
                'activo'   => true
        ]);

        if (!$endpoint) {
            throw new RuntimeException("Falta el Endpoint técnico en la base de datos para la acción de Meta: {$accion}");
        }

        // 4. Ensamblar la Cola
        $queue = new WhatsappMetaSendQueue();
        $queue->setMessage($message);
        $queue->setConfig($config);
        $queue->setEndpoint($endpoint);
        $queue->setDestinationPhone($phone);
        $queue->setStatus(WhatsappMetaSendQueue::STATUS_PENDING);
        $queue->setRunAt($runAt);
        $queue->setRetryCount(0);
        $queue->setMaxAttempts(5);

        return $queue;
    }
}