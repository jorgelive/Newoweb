<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
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
        private iterable                $enqueuers,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
        private EnlacesDeConversacion   $enlaces
    ) {}

    /**
     * Evalúa canales y crea colas físicas, respetando la idempotencia dictada por los Enqueuers.
     * Si un canal falla, los demás continúan (Tolerancia a fallos parciales).
     *
     * @param Message $message La entidad mensaje original.
     *
     * ⚠️ Había **dos `@return` contradictorios** —«las entidades de cola creadas» y
     * `array<string, mixed>` «resultado por canal»— y ninguno era cierto: devuelve una LISTA
     * numerada (`$queues[] = $queue`), que es como la consumen los dos llamadores
     * (`foreach ($this->dispatcher->dispatch($message) as $queue)`). Quien se hubiera fiado del
     * segundo habría indexado por nombre de canal algo que viene numerado.
     *
     * @return list<MessageQueueItemInterface> Las colas creadas, en el orden en que se crearon.
     */
    public function dispatch(Message $message): array
    {
        // 🔥 Un mensaje sin nada dentro no sale. Comprobado antes de tocar los canales.
        //
        // No es hipotético: hay cuatro en producción sin texto, sin adjunto y sin plantilla, y
        // TRES llegaron a Beds24 con `sent_at` — o sea que Airbnb y Booking recibieron un
        // mensaje en blanco de nuestra parte. Nada lo impedía: ni la entidad ni el controlador
        // comprueban que haya contenido, y el encolador tampoco.
        //
        // Se admite el mensaje SIN texto cuando lleva plantilla —el cuerpo se hidrata al
        // enviar— o cuando lleva adjunto, que es una foto y ya es contenido.
        if ($this->estaVacio($message)) {
            $message->setStatus(Message::STATUS_FAILED);
            $message->addMetadata('dispatch_errors', ['El mensaje no tiene texto, ni plantilla, ni adjunto: no se envía nada vacío.']);

            $this->logger->warning(sprintf(
                'Mensaje %s descartado por vacío: sin texto, sin plantilla y sin adjuntos.',
                $message->getId()?->toRfc4122() ?? 'N/A'
            ));

            return [];
        }

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
        if (empty($channels)) {
            // NI UN CANAL. Antes caía en el `else` y el mensaje se quedaba `QUEUED` sin una
            // sola cola detrás: en el panel se veía «encolado» y no salía nunca, que es el
            // peor de los tres finales posibles porque nadie lo va a ir a buscar.
            //
            // Se vuelve alcanzable de verdad con el corte por asunto —un expediente de viaje
            // con sólo Beds24 marcado se queda sin nada—, así que el final tiene que decirlo.
            $message->setStatus(Message::STATUS_FAILED);
            $message->addMetadata('dispatch_errors', [
                'Ningún canal disponible para este mensaje: o no se marcó ninguno, o los marcados no existen para este asunto.',
            ]);

            $this->logger->warning(sprintf(
                'Mensaje %s sin ningún canal que despachar.',
                $message->getId()?->toRfc4122() ?? 'N/A'
            ));

            return [];
        }

        if (empty($queues)) {
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
     * ¿Este mensaje no lleva absolutamente nada que enviar?
     *
     * Los tres contenidos posibles, y basta con uno:
     *
     * - **texto**, en local o en el externo ya traducido;
     * - **plantilla**, porque el cuerpo se hidrata en el momento del envío y aquí todavía no
     *   existe — es el caso normal de todo lo automático;
     * - **adjunto**, que es una foto y se envía sin una sola palabra.
     *
     * `trim()` a propósito: un cuerpo con sólo espacios o saltos de línea es tan vacío como uno
     * nulo, y llega igual desde un textarea al que se le dio a enviar sin querer.
     */
    private function estaVacio(Message $message): bool
    {
        if ($message->getTemplate() !== null) {
            return false;
        }

        if (!$message->getAttachments()->isEmpty()) {
            return false;
        }

        return trim((string) $message->getContentLocal()) === ''
            && trim((string) $message->getContentExternal()) === '';
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

            return $this->acotarAlAsunto($message, array_values($resolvedChannels));
        }

        // =====================================================================
        // REGLA 2: SELECCIÓN MANUAL DEL OPERADOR (Texto Libre)
        // =====================================================================
        if (!empty($transientIds)) {
            return $this->acotarAlAsunto($message, $channelRepo->findBy([
                'id' => $transientIds,
                'isActive' => true
            ]));
        }

        // =====================================================================
        // REGLA 3: FALLBACK
        // =====================================================================
        if ($message->getChannel() && $message->getChannel()->isActive()) {
            return $this->acotarAlAsunto($message, [$message->getChannel()]);
        }

        return [];
    }

    /**
     * Quita los canales que NO existen para el asunto al que va este mensaje.
     *
     * ── Por qué hace falta y por qué aquí ───────────────────────────────────
     * Las tres reglas de arriba no saben de dominios: cruzan lo que permite la plantilla con
     * lo que marcó el operador. Con un solo negocio daba igual; con Turismo dentro, Beds24
     * entraba en la lista de un expediente de viaje —que no tiene `bookId` ni lo tendrá— y el
     * corte llegaba tarde, dentro de `Beds24SendEnqueuer::isBusinessValid()`: como ese
     * encolador devuelve `null` en vez de encolar, si era el único canal el mensaje acababa
     * en `STATUS_FAILED` con «posible restricción de negocio por canal». Un canal que no
     * existe no es un envío fallido.
     *
     * Va aquí y no en el front porque el front es una PETICIÓN: el operador manda ids en
     * `transientChannels` y una skill del agente también. El cierre es esto.
     *
     * ── Con qué asunto se acota ─────────────────────────────────────────────
     * Con el del MENSAJE cuando lo lleva. Si no —la mayoría hoy—, con la unión de los del
     * hilo, que no acota nada mientras haya un asunto de alojamiento: se prefiere ofrecer un
     * canal de más, que es visible y se corrige, a callar uno legítimo, que no se descubre.
     *
     * @param MessageChannel[] $canales
     * @return MessageChannel[]
     */
    private function acotarAlAsunto(Message $message, array $canales): array
    {
        $conversacion = $message->getConversation();

        if ($conversacion === null || $canales === []) {
            return $canales;
        }

        $posibles = $this->enlaces->canalesPosibles(
            $conversacion,
            $message->getAsuntoType(),
            $message->getAsuntoId(),
        );

        // Sin acotar, o un hilo sin ningún asunto colgado: no se toca nada.
        if ($posibles === []) {
            return $canales;
        }

        $permitidos = array_values(array_filter(
            $canales,
            static fn (MessageChannel $c): bool => in_array($c->getId(), $posibles, true)
        ));

        if (count($permitidos) !== count($canales)) {
            $this->logger->info('Canales descartados: no existen para el asunto de este mensaje.', [
                'mensaje'   => $message->getId()?->toRfc4122(),
                'asunto'    => $message->getAsuntoType(),
                'pedidos'   => array_map(static fn (MessageChannel $c): ?string => $c->getId(), $canales),
                'posibles'  => $posibles,
            ]);
        }

        return $permitidos;
    }
}