<?php
declare(strict_types=1);

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(
    name: 'pms_beds24_webhook_audit',
    indexes: [
        new ORM\Index(columns: ['received_at'], name: 'idx_beds24_wh_received_at'),
        new ORM\Index(columns: ['event_type'], name: 'idx_beds24_wh_event_type'),
        new ORM\Index(columns: ['status'], name: 'idx_beds24_wh_status'),
    ]
)]
class PmsBeds24WebhookAudit
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'received_at', type: 'datetime')]
    private ?DateTimeInterface $receivedAt = null;

    #[ORM\Column(name: 'event_type', type: 'string', length: 80, nullable: true)]
    private ?string $eventType = null;

    #[ORM\Column(name: 'remote_ip', type: 'string', length: 64, nullable: true)]
    private ?string $remoteIp = null;

    #[ORM\Column(name: 'headers_json', type: 'json', nullable: true)]
    private ?array $headers = null;

    #[ORM\Column(name: 'payload_raw', type: 'text')]
    private ?string $payloadRaw = null;

    #[ORM\Column(name: 'payload_json', type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_RECEIVED])]
    private string $status = self::STATUS_RECEIVED;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'processing_meta', type: 'json', nullable: true)]
    private ?array $processingMeta = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $updated = null;

    public function getId(): ?int { return $this->id; }

    public function getReceivedAt(): ?DateTimeInterface { return $this->receivedAt; }
    public function setReceivedAt(DateTimeInterface $receivedAt): self { $this->receivedAt = $receivedAt; return $this; }

    public function getEventType(): ?string { return $this->eventType; }
    public function setEventType(?string $eventType): self { $this->eventType = $eventType; return $this; }

    public function getRemoteIp(): ?string { return $this->remoteIp; }
    public function setRemoteIp(?string $remoteIp): self { $this->remoteIp = $remoteIp; return $this; }

    public function getHeaders(): ?array { return $this->headers; }
    public function setHeaders(?array $headers): self { $this->headers = $headers; return $this; }

    public function getPayloadRaw(): ?string { return $this->payloadRaw; }
    public function setPayloadRaw(?string $payloadRaw): self { $this->payloadRaw = $payloadRaw; return $this; }

    public function getPayload(): ?array { return $this->payload; }
    public function setPayload(?array $payload): self { $this->payload = $payload; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): self { $this->errorMessage = $errorMessage; return $this; }

    public function getProcessingMeta(): ?array { return $this->processingMeta; }
    public function setProcessingMeta(?array $processingMeta): self { $this->processingMeta = $processingMeta; return $this; }

    public function getCreated(): ?DateTimeInterface { return $this->created; }
    public function getUpdated(): ?DateTimeInterface { return $this->updated; }

    // -----------------------------------------------------------
    // VIRTUALES (strings) para EasyAdmin: NO rompen nunca
    // -----------------------------------------------------------

    public function getHeadersPretty(): ?string
    {
        if (empty($this->headers)) return null;

        return json_encode(
            $this->headers,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: null;
    }

    public function getPayloadPretty(): ?string
    {
        if (empty($this->payload)) return null;

        return json_encode(
            $this->payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: null;
    }

    public function getProcessingMetaPretty(): ?string
    {
        if (empty($this->processingMeta)) return null;

        return json_encode(
            $this->processingMeta,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: null;
    }

    public function __toString(): string
    {
        return 'Beds24WebhookAudit #' . ($this->id ?? 0) . ' (' . $this->status . ')';
    }
}