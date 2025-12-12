<?php

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_beds24_config')]
class Beds24Config
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $apiKey = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $propKey = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $propId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $activo = null;

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

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): self
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getPropKey(): ?string
    {
        return $this->propKey;
    }

    public function setPropKey(?string $propKey): self
    {
        $this->propKey = $propKey;

        return $this;
    }

    public function getPropId(): ?int
    {
        return $this->propId;
    }

    public function setPropId(?int $propId): self
    {
        $this->propId = $propId;

        return $this;
    }

    public function isActivo(): ?bool
    {
        return $this->activo;
    }

    public function setActivo(?bool $activo): self
    {
        $this->activo = $activo;

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
        return $this->nombre ?? ('Config #' . $this->id);
    }
}
