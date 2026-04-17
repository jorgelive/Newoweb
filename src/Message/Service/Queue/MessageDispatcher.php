<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Throwable;
use Psr\Log\LoggerInterface;

/**
 * Orquesta la creación de ítems en las colas (Outbox) usando el patrón Strategy.
 * Delega la creación física a los Encoladores Específicos según los canales activos.
 * Implementa resiliencia multicanal tolerando fallos parciales de encolamiento.
 */
readonly class MessageDispatcher
{
    /**
     * @param iterable<ChannelEnqueuerInterface> $enqueuers Colección de encoladores etiquetados inyectados por Symfony.
     */
    public function __construct(
        #[TaggedIterator('app.message.enqueuer')]
        private iterable               $enqueuers,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger
    ) {}

    /**
     * Evalúa canales y crea colas físicas, respetando la idempotencia dictada por los Enqueuers.
     * Si un canal falla, los demás continúan (Tolerancia a fallos parciales).
     *
     * @param Message $message La entidad mensaje original.
     * @return array Un arreglo con las entidades de cola creadas (ej. Beds24SendQueue).
     */
    public function dispatch(Message $message): array
    {
        $queues = [];
        $errors = [];
        $channels = $this->resolveChannels($message);
        $runAt = $message->getScheduledAt() ?? new DateTimeImmutable();

        foreach ($channels as $channel) {
            foreach ($this->enqueuers as $enqueuer) {
                if ($enqueuer->supports($channel)) {

                    // 🛡️ BARRERA DE IDEMPOTENCIA
                    if ($enqueuer->isAlreadyEnqueued($message)) {
                        $this->logger->info(sprintf(
                            'Idempotencia: La cola %s para el mensaje %s ya existe en BD/UoW. Ignorando.',
                            $channel->getId(),
                            $message->getId()?->toRfc4122() ?? 'N/A'
                        ));
                        break;
                    }

                    try {
                        // Pasamos el $runAt exacto (presente o futuro) al Enqueuer
                        $queue = $enqueuer->createQueueEntity($message, $channel, $runAt);

                        if ($queue !== null) {
                            $queues[] = $queue;
                        }
                    } catch (Throwable $e) {
                        // Atrapamos el error específico del canal, pero NO rompemos el bucle
                        $errors[] = sprintf('[%s] %s', $channel->getName(), $e->getMessage());
                    }

                    // Ya encontramos el encolador para este canal, no seguimos iterando enqueuers
                    break;
                }
            }
        }

        // =====================================================================
        // 🔥 LÓGICA DE FALLO Y ÉXITO MEJORADA (Resiliencia Parcial)
        // =====================================================================
        if (empty($queues) && !empty($channels)) {
            // FRACASO TOTAL: Había canales previstos, pero NINGUNO generó una cola.
            // (Ya sea porque todos lanzaron excepción, o todos retornaron null por reglas de negocio)
            $message->setStatus(Message::STATUS_FAILED);

            $motivo = empty($errors)
                ? ['No se pudo generar ninguna cola para los canales solicitados (posible restricción de negocio por canal).']
                : $errors;

            $message->addMetadata('dispatch_errors', $motivo);

        } else {
            // ÉXITO (Total o Parcial): Al menos una cola se generó correctamente.
            $message->setStatus(Message::STATUS_QUEUED);

            // Si hubo éxito, pero algún otro canal falló, dejamos registro de auditoría
            if (!empty($errors)) {
                $message->addMetadata('dispatch_partial_errors', $errors);
                $this->logger->warning(sprintf(
                    'Mensaje %s encolado con fallos parciales: %s',
                    $message->getId()?->toRfc4122() ?? 'N/A',
                    implode(' | ', $errors)
                ));
            }
        }

        return $queues;
    }

    /**
     * Aplica las reglas de negocio para determinar los canales destino finales.
     * Analiza las plantillas, la selección manual del usuario y hace un fallback si es necesario.
     * * @param Message $message
     * @return MessageChannel[] Arreglo de canales resultantes a despachar.
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