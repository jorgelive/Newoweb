<?php

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

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


    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $authToken = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $authTokenExpiresAt = null;

    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => true])]
    private ?bool $activo = null;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private ?string $webhookToken = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    #[ORM\OneToMany(mappedBy: 'beds24Config', targetEntity: PmsUnidadBeds24Map::class, orphanRemoval: true)]
    private Collection $unidadMaps;

    public function __construct()
    {
        $this->unidadMaps = new ArrayCollection();
    }

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


    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): self
    {
        $this->refreshToken = $refreshToken;
        return $this;
    }

    public function getAuthToken(): ?string
    {
        return $this->authToken;
    }

    public function setAuthToken(?string $authToken): self
    {
        $this->authToken = $authToken;
        return $this;
    }

    public function getAuthTokenExpiresAt(): ?DateTimeInterface
    {
        return $this->authTokenExpiresAt;
    }

    public function setAuthTokenExpiresAt(?DateTimeInterface $authTokenExpiresAt): self
    {
        $this->authTokenExpiresAt = $authTokenExpiresAt;
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

    public function getUnidadMaps(): Collection
    {
        return $this->unidadMaps;
    }

    public function addUnidadMap(PmsUnidadBeds24Map $map): self
    {
        if (!$this->unidadMaps->contains($map)) {
            $this->unidadMaps->add($map);
            $map->setBeds24Config($this);
        }
        return $this;
    }

    public function removeUnidadMap(PmsUnidadBeds24Map $map): self
    {
        if ($this->unidadMaps->removeElement($map)) {
            // owning side handled by orphanRemoval
        }
        return $this;
    }


    public function getWebhookToken(): ?string
    {
        return $this->webhookToken;
    }

    public function setWebhookToken(string $token): void
    {
        $this->webhookToken = $token;
    }

    public function __toString(): string
    {
        return $this->nombre ?? ('Config #' . $this->id);
    }
}