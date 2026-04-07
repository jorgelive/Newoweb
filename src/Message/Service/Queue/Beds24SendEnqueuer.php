<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Service\MessageDataResolverRegistry;
use App\Pms\Entity\PmsChannel;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

readonly class Beds24SendEnqueuer implements ChannelEnqueuerInterface
{
    public function __construct(
        private EntityManagerInterface      $em,
        private MessageDataResolverRegistry $resolverRegistry
    ) {}

    public function supports(MessageChannel $channel): bool
    {
        return $channel->getId() === 'beds24';
    }

    public function createQueueEntity(Message $message, MessageChannel $channel, DateTimeImmutable $runAt): ?MessageQueueItemInterface
    {
        $conversation = $message->getConversation();
        if (!$conversation) {
            // Reemplazamos el return null por excepción
            throw new RuntimeException('No se puede encolar en Beds24: El mensaje no tiene una conversación asociada.');
        }

        $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());
        if (!$resolver) {
            // 🔥 El error que pediste: Si es manual/walk-in y piden Beds24, explota y avisa.
            throw new RuntimeException(sprintf(
                'No se puede enviar por Beds24: La conversación actual (Tipo: %s) no está vinculada a una reserva del PMS.',
                $conversation->getContextType()
            ));
        }

        // 1. Obtener la Metadata del PMS (Snapshot)
        $metadata = $resolver->getMetadata($conversation->getContextId());

        // 🔥 REGLA DE SEGURIDAD ESTRICTA: ¿Es Reserva Directa?
        $source = (string) ($metadata['source'] ?? '');
        $canalesDirectos = [PmsChannel::CODIGO_DIRECTO, 'manual', 'web', ''];

        if (in_array($source, $canalesDirectos, true)) {
            throw new RuntimeException(sprintf('Operación denegada: No se permite enviar mensajes por la API de Beds24 a reservas directas (Canal: %s).', $source ?: 'Desconocido'));
        }

        $config = $metadata['beds24_config'] ?? null;
        $bookId = $metadata['beds24_book_id'] ?? null;

        if (!$config || !$bookId) {
            // Reemplazamos el return null por excepción
            throw new RuntimeException('Faltan datos críticos: No se encontró la configuración de la propiedad o el ID de la reserva (bookId) en Beds24.');
        }

        // 2. Obtener el Endpoint Técnico
        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'provider' => ConnectivityProvider::BEDS24,
            'accion'   => 'SEND_MESSAGE',
            'activo'   => true
        ]);

        if (!$endpoint) {
            // Reemplazamos el return null por excepción
            throw new RuntimeException('Error de sistema: No se encontró un Endpoint activo para SEND_MESSAGE de Beds24.');
        }

        // 3. Ensamblar la Cola
        $queue = new Beds24SendQueue();
        $queue->setMessage($message);
        $queue->setConfig($config);
        $queue->setEndpoint($endpoint);

        // 🔥 GUARDAMOS EL SNAPSHOT INICIAL
        $queue->setTargetBookId((string) $bookId);

        $queue->setStatus(Beds24SendQueue::STATUS_PENDING);
        $queue->setRunAt($runAt);
        $queue->setRetryCount(0);
        $queue->setMaxAttempts(5);

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
            if ($entity instanceof Beds24SendQueue) {
                $queuedMessage = $entity->getMessage();

                if ($queuedMessage !== null && $queuedMessage->getId() !== null) {
                    // 🔥 COMPARACIÓN ROBUSTA POR VALOR DE UUID, NO POR INSTANCIA DE MEMORIA
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
        // =====================================================================
        $qb = $this->em->createQueryBuilder();
        $count = (int) $qb->select('COUNT(q.id)')
            ->from(Beds24SendQueue::class, 'q')
            ->where('q.message = :message')
            // Opcional: ignoramos las que fueron explícitamente canceladas, permitiendo que se regeneren si es necesario.
            ->andWhere('q.status != :status_cancelled')
            ->setParameter('message', $message)
            ->setParameter('status_cancelled', Beds24SendQueue::STATUS_CANCELLED)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}