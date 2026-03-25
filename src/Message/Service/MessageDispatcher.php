<?php

declare(strict_types=1);

namespace App\Message\Service;

use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Orquesta la creación de ítems en las colas (Outbox) usando el patrón Strategy.
 * Delega la creación física a los Encoladores Específicos según los canales activos.
 */
class MessageDispatcher
{
    /**
     * @param iterable<ChannelEnqueuerInterface> $enqueuers
     */
    public function __construct(
        #[TaggedIterator('app.message.enqueuer')]
        private readonly iterable $enqueuers,
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * @return array Objeto/s de cola listos para ser persistidos por el Listener.
     */
    public function dispatch(Message $message): array
    {
        $queues = [];
        $errors = [];
        $channels = $this->resolveChannels($message);

        // 🔥 MAGIA DEL SCHEDULER: Leemos la fecha programada en memoria, o usamos "ahora"
        $runAt = $message->getScheduledAt() ?? new \DateTimeImmutable();

        foreach ($channels as $channel) {
            foreach ($this->enqueuers as $enqueuer) {
                if ($enqueuer->supports($channel)) {
                    try {
                        // Pasamos el $runAt exacto (presente o futuro) al Enqueuer
                        $queue = $enqueuer->createQueueEntity($message, $channel, $runAt);

                        if ($queue !== null) {
                            $queues[] = $queue;
                        }
                    } catch (\Throwable $e) {
                        // 🔥 CAPTURAMOS EL ERROR Y LO GUARDAMOS
                        $errors[] = sprintf('[%s] %s', $channel->getName(), $e->getMessage());
                    }
                    break;
                }
            }
        }

        // 🔥 LÓGICA DE FALLO NOTORIO
        // Si hubo excepciones, o si había canales pero no se generó ninguna cola:
        if (!empty($errors) || (empty($queues) && !empty($channels))) {
            $message->setStatus(Message::STATUS_FAILED);

            // Guardamos el detalle del error en la columna JSON para auditoría
            $message->addMetadata('dispatch_errors', empty($errors) ? ['Error desconocido al generar la cola.'] : $errors);
        } else {
            // Si todo fue bien, lo ponemos en Queue
            $message->setStatus(Message::STATUS_QUEUED);
        }

        return $queues;
    }

    /**
     * Aplica las reglas de negocio para determinar los canales destino.
     * @return MessageChannel[]
     */
    private function resolveChannels(Message $message): array
    {
        $channelRepo = $this->em->getRepository(MessageChannel::class);
        $transientIds = $message->getTransientChannels();
        $resolvedChannels = [];

        // =====================================================================
        // REGLA 1: PLANTILLA ACTÚA COMO EL "MÁXIMO PERMITIDO"
        // =====================================================================
        if ($template = $message->getTemplate()) {
            // Buscamos todos los canales activos en el sistema
            $allActiveChannels = $channelRepo->findBy(['isActive' => true]);

            // 1A. Identificamos qué canales permite la plantilla
            foreach ($allActiveChannels as $channel) {
                $column = $channel->getTemplateColumn();
                $getter = 'get' . ucfirst($column);

                if (method_exists($template, $getter)) {
                    $tmplData = $template->$getter();

                    // Si el interruptor is_active dentro del JSON está encendido, lo sumamos
                    if (is_array($tmplData) && ($tmplData['is_active'] ?? false) === true) {
                        $resolvedChannels[] = $channel;
                    }
                }
            }

            // 1B. 🔥 INTERSECCIÓN CON LA DECISIÓN DEL OPERADOR
            // Si el request trajo canales explícitos (la UI envió sus checkboxes),
            // filtramos para respetar si el operador desmarcó voluntariamente alguno.
            if (!empty($transientIds)) {
                $resolvedChannels = array_filter($resolvedChannels, function (MessageChannel $c) use ($transientIds) {
                    return in_array($c->getId(), $transientIds, true);
                });
            }

            return array_values($resolvedChannels);
        }

        // =====================================================================
        // REGLA 2: SELECCIÓN MANUAL DEL OPERADOR (Texto Libre)
        // =====================================================================
        if (!empty($transientIds)) {
            return $channelRepo->findBy([
                'id' => $transientIds,
                'isActive' => true
            ]);
        }

        // =====================================================================
        // REGLA 3: FALLBACK
        // =====================================================================
        if ($message->getChannel() && $message->getChannel()->isActive()) {
            return [$message->getChannel()];
        }

        return [];
    }
}