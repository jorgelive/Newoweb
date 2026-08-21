<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Entity\EmailConfig;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use App\Exchange\Service\Contract\EndpointInterface;
use App\Exchange\Service\Contract\MemoryCleanableInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Repository\EmailSendQueueRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Un correo esperando salir.
 *
 * ⚠️ **Congela el DESTINO y el ASUNTO.** Es la misma razón que `destinationPhone` en WhatsApp:
 * entre encolar y enviar pueden pasar horas —un recordatorio se programa días antes— y en ese
 * rato el correo de la persona puede cambiar, retirarse o vetarse. Un mensaje tiene que salir a
 * donde se decidió que saliera, no a donde apunte la ficha en el momento del envío.
 *
 * Y el asunto se congela por lo mismo: se compone de la plantilla o de la etiqueta del asunto, y
 * las dos pueden cambiar. Un correo cuyo título cambia entre que se programa y se manda es un
 * correo distinto.
 */
#[ORM\Entity(repositoryClass: EmailSendQueueRepository::class)]
#[ORM\Table(name: 'msg_email_send_queue')]
#[ORM\Index(columns: ['status', 'run_at'], name: 'idx_msg_email_worker')]
#[ORM\HasLifecycleCallbacks]
class EmailSendQueue implements MessageQueueItemInterface, MemoryCleanableInterface
{
    use IdTrait;
    use TimestampTrait;

    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_CANCELLED = 'cancelled';

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'emailSendQueues')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    #[ORM\ManyToOne(targetEntity: EmailConfig::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmailConfig $config = null;

    /**
     * El endpoint marcador.
     *
     * ⚠️ **No apunta a ninguna ruta** —el destino de un correo es un buzón— pero la columna es
     * real y se rellena, porque el motor arma los lotes agrupando por `(config_id, endpoint_id)`
     * en SQL nativo. Sin la columna, `claimRunnable()` falla con «Unknown column 'endpoint_id'»
     * y la cola se queda llena.
     *
     * Se prefiere darle al correo la MISMA forma que a los demás canales antes que sembrar
     * excepciones en el motor: cambiar el núcleo para un caso obliga a revisar los cinco que ya
     * funcionan.
     */
    #[ORM\ManyToOne(targetEntity: ExchangeEndpoint::class)]
    #[ORM\JoinColumn(name: 'endpoint_id', nullable: false)]
    private ?ExchangeEndpoint $endpoint = null;

    /** El buzón al que se decidió mandarlo. Congelado: ver la nota de la clase. */
    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['message:read'])]
    private ?string $destinationEmail = null;

    /** El título, ya resuelto y en el idioma de la persona. Congelado por lo mismo. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['message:read'])]
    private ?string $subject = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    #[Groups(['message:read'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $runAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lockedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['message:read'])]
    private ?string $failedReason = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 3])]
    private int $maxAttempts = 3;

    /** El identificador que devuelve el transporte, para poder rastrear un envío concreto. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastRequestRaw = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastResponseRaw = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $lastHttpCode = null;

    /** @var array<string, mixed>|null Volcado de lo que devolvió el transporte. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $executionResult = null;

    public function __construct()
    {
        $this->initializeId();
        $this->runAt = new DateTimeImmutable();
    }

    /** @return list<object|null> */
    public function getRelatedEntitiesToDetach(): array
    {
        return [$this->message, $this->message?->getConversation(), $this->message?->getTemplate()];
    }

    public function markProcessing(string $workerId, DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->lockedBy = $workerId;
        $this->lockedAt = $now;
    }

    public function markSuccess(DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_SUCCESS;
        $this->lockedAt = null;
        $this->lockedBy = null;
        $this->failedReason = null;
    }

    public function markFailure(string $reason, ?int $httpCode, DateTimeImmutable $nextRetry): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failedReason = mb_substr($reason, 0, 65000);
        $this->runAt = $nextRetry;
        $this->lockedAt = null;
        $this->lockedBy = null;
        ++$this->retryCount;
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getMessage(): ?Message { return $this->message; }
    public function setMessage(?Message $message): self { $this->message = $message; return $this; }

    public function getChannelId(): string { return 'email'; }

    public function getSendTaskName(): string { return 'email_message_send'; }

    public function getDestinationEmail(): ?string { return $this->destinationEmail; }
    public function setDestinationEmail(?string $v): self { $this->destinationEmail = $v; return $this; }

    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $v): self { $this->subject = $v; return $this; }

    public function getConfig(): ?ChannelConfigInterface { return $this->config; }

    public function setConfig(?ChannelConfigInterface $config): self
    {
        if ($config !== null && !$config instanceof EmailConfig) {
            throw new InvalidArgumentException('La cola de correo sólo admite EmailConfig.');
        }

        $this->config = $config;

        return $this;
    }

    public function getEndpoint(): EndpointInterface
    {
        if ($this->endpoint === null) {
            throw new InvalidArgumentException('Cola de correo sin endpoint: no se puede armar el lote.');
        }

        return $this->endpoint;
    }

    public function setEndpoint(?EndpointInterface $endpoint): self
    {
        if ($endpoint !== null && !$endpoint instanceof ExchangeEndpoint) {
            throw new InvalidArgumentException('La cola de correo sólo admite ExchangeEndpoint.');
        }

        $this->endpoint = $endpoint;

        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getRunAt(): ?DateTimeImmutable { return $this->runAt; }

    public function setRunAt(?DateTimeInterface $at): self
    {
        $this->runAt = $at instanceof DateTimeImmutable
            ? $at
            : ($at === null ? null : DateTimeImmutable::createFromInterface($at));

        return $this;
    }

    public function getLockedAt(): ?DateTimeInterface { return $this->lockedAt; }
    public function setLockedAt(?DateTimeInterface $v): self { $this->lockedAt = $v; return $this; }

    public function getLockedBy(): ?string { return $this->lockedBy; }
    public function setLockedBy(?string $v): self { $this->lockedBy = $v; return $this; }

    public function getFailedReason(): ?string { return $this->failedReason; }
    public function setFailedReason(?string $v): self { $this->failedReason = $v; return $this; }

    public function getRetryCount(): int { return $this->retryCount; }
    public function setRetryCount(int $v): self { $this->retryCount = $v; return $this; }

    public function getMaxAttempts(): int { return $this->maxAttempts; }
    public function setMaxAttempts(int $v): self { $this->maxAttempts = $v; return $this; }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $v): self { $this->externalId = $v; return $this; }

    public function getLastResponseRaw(): ?string { return $this->lastResponseRaw; }
    public function setLastResponseRaw(?string $v): self { $this->lastResponseRaw = $v; return $this; }

    public function setLastRequestRaw(?string $v): self { $this->lastRequestRaw = $v; return $this; }
    public function getLastRequestRaw(): ?string { return $this->lastRequestRaw; }

    /**
     * El mailer no devuelve códigos HTTP: o manda o lanza. Se guarda igual porque el contrato lo
     * pide y el motor lo escribe; leerlo como «respuesta del servidor» sería malinterpretarlo.
     */
    public function setLastHttpCode(?int $code): self { $this->lastHttpCode = $code; return $this; }
    public function getLastHttpCode(): ?int { return $this->lastHttpCode; }

    /** @param array<string, mixed>|null $result */
    public function setExecutionResult(?array $result): self { $this->executionResult = $result; return $this; }

    /** @return array<string, mixed>|null */
    public function getExecutionResult(): ?array { return $this->executionResult; }
}
