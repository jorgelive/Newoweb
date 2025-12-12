<?php
namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_tarifa_queue')]
class PmsTarifaQueue {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsTarifaRango::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsTarifaRango $tarifaRango = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidadBeds24Map::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsUnidadBeds24Map $unidadBeds24 = null;

    #[ORM\ManyToOne(targetEntity: PmsBeds24Endpoint::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsBeds24Endpoint $endpoint = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $needsSync = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private ?int $retryCount = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lastSync = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $lastMessage = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastRequestJson = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastResponseJson = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTarifaRango(): ?PmsTarifaRango
    {
        return $this->tarifaRango;
    }

    public function setTarifaRango(?PmsTarifaRango $tarifaRango): self
    {
        $this->tarifaRango = $tarifaRango;
        return $this;
    }

    public function getUnidadBeds24(): ?PmsUnidadBeds24Map
    {
        return $this->unidadBeds24;
    }

    public function setUnidadBeds24(?PmsUnidadBeds24Map $unidadBeds24): self
    {
        $this->unidadBeds24 = $unidadBeds24;
        return $this;
    }

    public function getEndpoint(): ?PmsBeds24Endpoint
    {
        return $this->endpoint;
    }

    public function setEndpoint(?PmsBeds24Endpoint $endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getNeedsSync(): ?bool
    {
        return $this->needsSync;
    }

    public function setNeedsSync(?bool $needsSync): self
    {
        $this->needsSync = $needsSync;
        return $this;
    }

    public function getRetryCount(): ?int
    {
        return $this->retryCount;
    }

    public function setRetryCount(?int $retryCount): self
    {
        $this->retryCount = $retryCount;
        return $this;
    }

    public function getLastSync(): ?DateTimeInterface
    {
        return $this->lastSync;
    }

    public function setLastSync(?DateTimeInterface $lastSync): self
    {
        $this->lastSync = $lastSync;
        return $this;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function setLastStatus(?string $lastStatus): self
    {
        $this->lastStatus = $lastStatus;
        return $this;
    }

    public function getLastMessage(): ?string
    {
        return $this->lastMessage;
    }

    public function setLastMessage(?string $lastMessage): self
    {
        $this->lastMessage = $lastMessage;
        return $this;
    }

    public function getLastRequestJson(): ?string
    {
        return $this->lastRequestJson;
    }

    public function setLastRequestJson(?string $lastRequestJson): self
    {
        $this->lastRequestJson = $lastRequestJson;
        return $this;
    }

    public function getLastResponseJson(): ?string
    {
        return $this->lastResponseJson;
    }

    public function setLastResponseJson(?string $lastResponseJson): self
    {
        $this->lastResponseJson = $lastResponseJson;
        return $this;
    }

    public function getCreated(): ?DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): ?DateTimeInterface
    {
        return $this->updated;
    }

    public function __toString(): string
    {
        $id = $this->id ?? '¿?';
        $status = $this->lastStatus ?? 'pending';
        $endpoint = $this->endpoint?->getAccion() ?? 'endpoint';
        return 'TarifaQueue #' . $id . ' - ' . $endpoint . ' (' . $status . ')';
    }
}
?>