<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Entity\GupshupConfig;
use App\Exchange\Enum\ConnectivityProvider;
use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\WhatsappGupshupSendQueue;
use App\Message\Service\MessageDataResolverRegistry;
use Doctrine\ORM\EntityManagerInterface;

class WhatsappGupshupEnqueuer implements ChannelEnqueuerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageDataResolverRegistry $resolverRegistry
    ) {}

    public function supports(MessageChannel $channel): bool
    {
        return $channel->getId() === 'whatsapp_gupshup';
    }

    public function createQueueEntity(Message $message, MessageChannel $channel, \DateTimeImmutable $runAt): ?MessageQueueItemInterface
    {
        $conversation = $message->getConversation();
        if (!$conversation) {
            throw new \RuntimeException('El mensaje no tiene una conversación asociada.');
        }

        $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());
        if (!$resolver) {
            throw new \RuntimeException("No se encontró un Resolver para el contexto: {$conversation->getContextType()}");
        }

        // 1. Obtener el Teléfono Inicial (Snapshot para la vista)
        $phone = $resolver->getPhoneNumber($conversation->getContextId());

        if (empty($phone)) {
            // 🔥 HACEMOS NOTORIO EL ERROR
            throw new \RuntimeException('El huésped no tiene un número de teléfono registrado.');
        }

        // 2. Obtener la Configuración Global
        $config = $this->em->getRepository(GupshupConfig::class)->findOneBy(['activo' => true]);
        if (!$config) {
            // 🔥 HACEMOS NOTORIO EL ERROR
            throw new \RuntimeException('No hay ninguna configuración activa de Gupshup en el sistema.');
        }

        // 3. Determinar el Endpoint
        $accion = $message->getTemplate() !== null ? 'SEND_WHATSAPP_TEMPLATE' : 'SEND_WHATSAPP_MESSAGE';

        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'provider' => ConnectivityProvider::GUPSHUP,
            'accion'   => $accion,
            'activo'   => true
        ]);

        if (!$endpoint) {
            // 🔥 HACEMOS NOTORIO EL ERROR
            throw new \RuntimeException("Falta el Endpoint técnico para la acción: $accion");
        }

        // 4. Ensamblar la Cola
        $queue = new WhatsappGupshupSendQueue();
        $queue->setMessage($message);
        $queue->setConfig($config);
        $queue->setEndpoint($endpoint);
        $queue->setDestinationPhone($phone);
        $queue->setStatus(WhatsappGupshupSendQueue::STATUS_PENDING);
        $queue->setRunAt($runAt);
        $queue->setRetryCount(0);
        $queue->setMaxAttempts(3);

        return $queue;
    }
}