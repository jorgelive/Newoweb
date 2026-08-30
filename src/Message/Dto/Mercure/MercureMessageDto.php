<?php

declare(strict_types=1);

namespace App\Message\Dto\Mercure;

use App\Message\Entity\Message;
use DateTimeInterface;
use JsonSerializable;

/**
 * Data Transfer Object para estandarizar el payload de los mensajes
 * que se transmiten en tiempo real a través de Mercure.
 * Aisla la capa de dominio de la capa de transporte, asegurando
 * que Vue reciba la estructura exacta que espera ApiMessage.
 */
class MercureMessageDto implements JsonSerializable
{
    private string $context = '/platform/contexts/Message';
    private string $iri;
    private string $type = 'Message';
    private string $id;
    private string $direction;
    private string $status;
    private string $senderType;
    private ?string $contentLocal;
    private ?string $contentExternal;
    private ?string $createdAt;
    private ?string $scheduledAt;
    private ?string $effectiveDateTime;
    private bool $isScheduledForFuture;
    private string $conversation;
    /** @var array<string, mixed> La bolsa abierta de {@see \App\Message\Entity\Message::getMetadata()}. */
    private array $metadata = [];

    /** @var array{'@type': string, '@id': string, id: string}|null */
    private ?array $channel = null;

    /** @var list<array{'@id': string, '@type': string, id: string, originalName: string|null, mimeType: string|null, fileUrl: string|null}> */
    private array $attachments = [];

    /** @var list<array{status: string|null}> */
    private array $beds24SendQueues = [];

    /** @var list<array{status: string|null, deliveryStatus: string|null}> */
    private array $whatsappMetaSendQueues = [];

    /**
     * @var string|array<string, mixed>|null El IRI de la plantilla, o sus datos ya embebidos.
     */
    private string|array|null $template = null;

    /**
     * Construye el DTO a partir de una entidad Message de Doctrine.
     * Mapea manualmente las relaciones para evitar referencias circulares
     * y controla la inyección del dominio absoluto para los archivos adjuntos.
     *
     * @param Message $message La entidad origen.
     */
    /**
     * ⚠️ El `$iri` viene de fuera (`IriConverter`) y el respaldo es sólo por si no se resuelve.
     *
     * `MercureBroadcaster` ya se lo pasaba —hay hasta un comentario «FIX: IRI real del recurso»—
     * pero el constructor **no tenía el parámetro**, y PHP descarta los argumentos de más sin
     * decir nada: el arreglo llevaba desde entonces sin aplicarse. El respaldo que se usaba en su
     * lugar, `/platform/user/util/msg/messages/`, tampoco existe: la ruta real es
     * `/platform/message/messages/{id}` (`routePrefix: '/message'` en {@see Message}).
     *
     * No se notó porque el front encaja los mensajes por UUID —`uuidDe()` en `services/hydra.ts`
     * se queda con el último segmento—, así que el prefijo equivocado daba igual para pintar.
     * Pero el `@id` que viajaba era irresoluble: cualquier consumidor que lo usara para releer el
     * recurso se comía un 404. Mismo patrón que {@see MercureConversationDto}, al que sí se le
     * añadió el parámetro en su día.
     */
    public function __construct(Message $message, ?string $iri = null)
    {
        $this->iri = $iri ?? '/platform/message/messages/' . $message->getId();
        $this->id = (string) $message->getId();
        $this->direction = $message->getDirection();
        $this->status = $message->getStatus();
        $this->senderType = $message->getSenderType();
        $this->contentLocal = $message->getContentLocal();
        $this->contentExternal = $message->getContentExternal();
        $this->createdAt = $message->getCreatedAt() ? $message->getCreatedAt()->format(DateTimeInterface::ATOM) : null;
        $this->scheduledAt = $message->getScheduledAt() ? $message->getScheduledAt()->format(DateTimeInterface::ATOM) : null;
        $this->effectiveDateTime = $message->getEffectiveDateTime() ? $message->getEffectiveDateTime()->format(DateTimeInterface::ATOM) : null;

        // Asignamos el flag virtual
        $this->isScheduledForFuture = $message->isScheduledForFuture();

        // Rutas reales, comprobadas con `debug:router`. Las que había —con el prefijo
        // `/platform/user/util/msg/`— son de un esquema de rutas anterior y ya no resuelven.
        $this->conversation = '/platform/message/conversations/' . $message->getConversation()->getId();
        $this->metadata = $message->getMetadata();

        if ($message->getChannel()) {
            $this->channel = [
                '@type' => 'MessageChannel',
                '@id' => '/platform/.well-known/genid/' . uniqid(),
                'id' => (string) $message->getChannel()->getId()
            ];
        }

        // Mapeo de la plantilla como IRI para mantener compatibilidad con Vue.
        //
        // ⚠️ Esto SÍ se comparaba por cadena completa, no por UUID: `ChatView.vue` hace
        // `store.templates.find(t => (t['@id'] || t.id) === templateData)`, y `@id` viene del
        // endpoint REST como `/platform/message/templates/{id}`. Con la ruta inventada que había
        // aquí (`/platform/message_templates/`) la búsqueda NO encontraba nunca la plantilla de
        // un mensaje llegado por Mercure.
        if ($message->getTemplate()) {
            $this->template = '/platform/message/templates/' . $message->getTemplate()->getId();
        }

        // Definimos el dominio base una sola vez para concatenarlo a las rutas relativas
        $apiBaseUrl = rtrim($_ENV['APP_URL'] ?? 'https://api.openperu.pe', '/');

        // Se mapean los adjuntos formateando la URL para el frontend
        foreach ($message->getAttachments() as $attachment) {
            $fileUrl = $attachment->getFileUrl();

            // Si hay URL y es relativa (no empieza con http), le concatenamos el dominio de la API
            if ($fileUrl !== null && !str_starts_with($fileUrl, 'http://') && !str_starts_with($fileUrl, 'https://')) {
                $fileUrl = $apiBaseUrl . '/' . ltrim($fileUrl, '/');
            }

            $this->attachments[] = [
                // `MessageAttachment` no es un recurso de API Platform —sólo tiene CRUD de
                // panel—, así que no hay IRI real que poner. Se deja el identificador de nodo
                // anónimo, que es lo que corresponde a algo sin URL propia; el front usa `id` y
                // `fileUrl`, nunca esto.
                '@id' => '/platform/.well-known/genid/' . uniqid(),
                '@type' => 'MessageAttachment',
                'id' => (string) $attachment->getId(),
                'originalName' => $attachment->getOriginalName(),
                'mimeType' => $attachment->getMimeType(),
                'fileUrl' => $fileUrl
            ];
        }

        foreach ($message->getBeds24SendQueues() as $queue) {
            $this->beds24SendQueues[] = ['status' => $queue->getStatus()];
        }

        foreach ($message->getWhatsappMetaSendQueues() as $queue) {
            $this->whatsappMetaSendQueues[] = [
                'status' => $queue->getStatus(),
                'deliveryStatus' => $queue->getDeliveryStatus()
            ];
        }
    }

    /**
     * Define la estructura exacta que se convertirá a JSON al emitir por Mercure.
     * Garantiza compatibilidad absoluta con la interfaz ApiMessage de Vue.
     *
     * @return array<string, mixed> El array asociativo listo para ser serializado a JSON.
     */
    public function jsonSerialize(): array
    {
        return [
            '@context' => $this->getContext(),
            '@id' => $this->getIri(),
            '@type' => $this->getType(),
            'id' => $this->getId(),
            'direction' => $this->getDirection(),
            'status' => $this->getStatus(),
            'senderType' => $this->getSenderType(),
            'contentLocal' => $this->getContentLocal(),
            'contentExternal' => $this->getContentExternal(),
            'createdAt' => $this->getCreatedAt(),
            'scheduledAt' => $this->getScheduledAt(),
            'effectiveDateTime' => $this->getEffectiveDateTime(),
            'isScheduledForFuture' => $this->getIsScheduledForFuture(),
            'conversation' => $this->getConversation(),
            'metadata' => $this->getMetadata(),
            'channel' => $this->getChannel(),
            'attachments' => $this->getAttachments(),
            'beds24SendQueues' => $this->getBeds24SendQueues(),
            'whatsappMetaSendQueues' => $this->getWhatsappMetaSendQueues(),
            'template' => $this->getTemplate(), // Se expone la propiedad recién agregada
        ];
    }

    // =========================================================================
    // GETTERS Y SETTERS EXPLÍCITOS
    // =========================================================================

    /**
     * Obtiene el contexto JSON-LD.
     */
    public function getContext(): string { return $this->context; }

    /**
     * Define el contexto JSON-LD.
     */
    public function setContext(string $context): self { $this->context = $context; return $this; }

    /**
     * Obtiene el identificador de recurso internacionalizado (IRI) para API Platform.
     */
    public function getIri(): string { return $this->iri; }

    /**
     * Define el identificador de recurso internacionalizado (IRI).
     */
    public function setIri(string $iri): self { $this->iri = $iri; return $this; }

    /**
     * Obtiene el tipo de recurso JSON-LD.
     */
    public function getType(): string { return $this->type; }

    /**
     * Define el tipo de recurso JSON-LD.
     */
    public function setType(string $type): self { $this->type = $type; return $this; }

    /**
     * Obtiene el identificador UUID del mensaje.
     */
    public function getId(): string { return $this->id; }

    /**
     * Define el identificador UUID del mensaje.
     */
    public function setId(string $id): self { $this->id = $id; return $this; }

    /**
     * Obtiene la dirección del mensaje (ej: 'incoming' o 'outgoing').
     */
    public function getDirection(): string { return $this->direction; }

    /**
     * Define la dirección del mensaje.
     */
    public function setDirection(string $direction): self { $this->direction = $direction; return $this; }

    /**
     * Obtiene el estado actual de entrega o lectura del mensaje.
     */
    public function getStatus(): string { return $this->status; }

    /**
     * Define el estado actual del mensaje.
     */
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    /**
     * Obtiene el tipo de remitente (ej: 'guest', 'system', 'agent').
     */
    public function getSenderType(): string { return $this->senderType; }

    /**
     * Define el tipo de remitente.
     */
    public function setSenderType(string $senderType): self { $this->senderType = $senderType; return $this; }

    /**
     * Obtiene el contenido local del mensaje, visible internamente.
     */
    public function getContentLocal(): ?string { return $this->contentLocal; }

    /**
     * Define el contenido local del mensaje.
     */
    public function setContentLocal(?string $contentLocal): self { $this->contentLocal = $contentLocal; return $this; }

    /**
     * Obtiene el contenido externo del mensaje que se envía a plataformas terceras.
     */
    public function getContentExternal(): ?string { return $this->contentExternal; }

    /**
     * Define el contenido externo del mensaje.
     */
    public function setContentExternal(?string $contentExternal): self { $this->contentExternal = $contentExternal; return $this; }

    /**
     * Obtiene la fecha de creación del mensaje en formato ATOM.
     */
    public function getCreatedAt(): ?string { return $this->createdAt; }

    /**
     * Define la fecha de creación del mensaje.
     */
    public function setCreatedAt(?string $createdAt): self { $this->createdAt = $createdAt; return $this; }

    /**
     * Obtiene la fecha programada para el envío futuro, si aplica.
     */
    public function getScheduledAt(): ?string { return $this->scheduledAt; }

    /**
     * Define la fecha programada para el envío.
     */
    public function setScheduledAt(?string $scheduledAt): self { $this->scheduledAt = $scheduledAt; return $this; }

    /**
     * Obtiene la fecha y hora efectiva en la que el mensaje se considera activo/enviado.
     */
    public function getEffectiveDateTime(): ?string { return $this->effectiveDateTime; }

    /**
     * Define la fecha y hora efectiva.
     */
    public function setEffectiveDateTime(?string $effectiveDateTime): self { $this->effectiveDateTime = $effectiveDateTime; return $this; }

    /**
     * Indica si el mensaje está encolado para un envío futuro.
     */
    public function getIsScheduledForFuture(): bool { return $this->isScheduledForFuture; }

    /**
     * Define si el mensaje debe tratarse como programado en el futuro.
     */
    public function setIsScheduledForFuture(bool $isScheduledForFuture): self { $this->isScheduledForFuture = $isScheduledForFuture; return $this; }

    /**
     * Obtiene el IRI de la conversación a la que pertenece este mensaje.
     */
    public function getConversation(): string { return $this->conversation; }

    /**
     * Define la conversación asociada.
     */
    public function setConversation(string $conversation): self { $this->conversation = $conversation; return $this; }

    /**
     * Obtiene los metadatos asociados al mensaje (respuestas de canales, etc).
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array { return $this->metadata; }

    /**
     * Define los metadatos del mensaje.
     *
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): self { $this->metadata = $metadata; return $this; }

    /**
     * Obtiene la estructura del canal por el cual se transmitió el mensaje.
     *
     * @return array{'@type': string, '@id': string, id: string}|null
     */
    public function getChannel(): ?array { return $this->channel; }

    /**
     * Define la estructura del canal asociado.
     *
     * @param array{'@type': string, '@id': string, id: string}|null $channel
     */
    public function setChannel(?array $channel): self { $this->channel = $channel; return $this; }

    /**
     * Obtiene la colección de archivos adjuntos del mensaje formateada para Vue.
     *
     * @return list<array<string, mixed>>
     */
    public function getAttachments(): array { return $this->attachments; }

    /**
     * Define los archivos adjuntos.
     *
     * La misma forma que declara la propiedad: una sola verdad por campo.
     *
     * @param list<array{'@id': string, '@type': string, id: string, originalName: string|null, mimeType: string|null, fileUrl: string|null}> $attachments
     */
    public function setAttachments(array $attachments): self { $this->attachments = $attachments; return $this; }

    /**
     * Obtiene el estado en las colas de envío hacia Beds24.
     *
     * @return list<array{status: string|null}>
     */
    public function getBeds24SendQueues(): array { return $this->beds24SendQueues; }

    /**
     * Define el estado en las colas de Beds24.
     *
     * @param list<array{status: string|null}> $beds24SendQueues
     */
    public function setBeds24SendQueues(array $beds24SendQueues): self { $this->beds24SendQueues = $beds24SendQueues; return $this; }

    /**
     * Obtiene el estado en las colas de envío hacia WhatsApp Meta.
     *
     * @return list<array{status: string|null, deliveryStatus: string|null}>
     */
    public function getWhatsappMetaSendQueues(): array { return $this->whatsappMetaSendQueues; }

    /**
     * Define el estado en las colas de WhatsApp Meta.
     *
     * @param list<array{status: string|null, deliveryStatus: string|null}> $whatsappMetaSendQueues
     */
    public function setWhatsappMetaSendQueues(array $whatsappMetaSendQueues): self { $this->whatsappMetaSendQueues = $whatsappMetaSendQueues; return $this; }

    /**
     * Obtiene la plantilla asociada al mensaje, si existe.
     *
     * @return string|array|null
     *
     * @return string|array<string, mixed>|null
     */
    public function getTemplate(): string|array|null { return $this->template; }

    /**
     * Define la plantilla asociada al mensaje.
     *
     * @param string|array|null $template
     * @return self
     *
     * @param string|array<string, mixed>|null $template
     */
    public function setTemplate(string|array|null $template): self { $this->template = $template; return $this; }
}