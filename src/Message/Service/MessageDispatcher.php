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
        $errors = []; // 🔥 Guardaremos los motivos de fallo
        $channels = $this->resolveChannels($message);
        $runAt = new \DateTimeImmutable();

        foreach ($channels as $channel) {
            foreach ($this->enqueuers as $enqueuer) {
                if ($enqueuer->supports($channel)) {
                    try {
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

        // 🔥 LOGICA DE FALLO NOTORIO
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
     * * @return MessageChannel[]
     */
    private function resolveChannels(Message $message): array
    {
        $channelRepo = $this->em->getRepository(MessageChannel::class);
        $resolvedChannels = [];

        // =====================================================================
        // REGLA 1 (MÁXIMA JERARQUÍA): REGLAS DE LA PLANTILLA
        // =====================================================================
        if ($template = $message->getTemplate()) {
            // Buscamos todos los canales activos en el sistema
            $allActiveChannels = $channelRepo->findBy(['isActive' => true]);

            foreach ($allActiveChannels as $channel) {
                $column = $channel->getTemplateColumn(); // Ej: 'whatsappGupshupTmpl'

                // Magia dinámica: Llamamos a getWhatsappGupshupTmpl(), getBeds24Tmpl(), etc.
                $getter = 'get' . ucfirst($column);

                if (method_exists($template, $getter)) {
                    $tmplData = $template->$getter();

                    // Si el interruptor is_active dentro del JSON está encendido, lo sumamos
                    if (is_array($tmplData) && ($tmplData['is_active'] ?? false) === true) {
                        $resolvedChannels[] = $channel;
                    }
                }
            }

            return $resolvedChannels; // Retornamos inmediatamente, ignorando transientChannels
        }

        // =====================================================================
        // REGLA 2: SELECCIÓN MANUAL DEL OPERADOR (EasyAdmin checkboxes)
        // =====================================================================
        $transientIds = $message->getTransientChannels();
        if (!empty($transientIds)) {
            return $channelRepo->findBy([
                'id' => $transientIds,
                'isActive' => true // Seguro extra: Solo canales que sigan activos globalmente
            ]);
        }

        // =====================================================================
        // REGLA 3: FALLBACK (Por si acaso)
        // =====================================================================
        if ($message->getChannel() && $message->getChannel()->isActive()) {
            return [$message->getChannel()];
        }

        return [];
    }
}