<?php

namespace App\Pms\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_channel')]
class PmsChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $codigo = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private ?bool $esExterno = null;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private ?bool $esDirecto = null;

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $beds24ChannelId = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeInterface $created = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    private ?\DateTimeInterface $updated = null;

    public function getId(): ?int { return $this->id; }
    public function getCodigo(): ?string { return $this->codigo; }
    public function setCodigo(?string $codigo): self { $this->codigo = $codigo; return $this; }
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }
    public function getEsExterno(): ?bool { return $this->esExterno; }
    public function setEsExterno(?bool $v): self { $this->esExterno = $v; return $this; }
    public function getEsDirecto(): ?bool { return $this->esDirecto; }
    public function setEsDirecto(?bool $v): self { $this->esDirecto = $v; return $this; }
    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $c): self { $this->color = $c; return $this; }
    public function getCreated(): ?\DateTimeInterface { return $this->created; }
    public function getUpdated(): ?\DateTimeInterface { return $this->updated; }

    public function getBeds24ChannelId(): ?string
    {
        return $this->beds24ChannelId;
    }

    public function setBeds24ChannelId(?string $v): self
    {
        $this->beds24ChannelId = $v;
        return $this;
    }

    public function __toString(): string {
        return $this->nombre ?? $this->codigo ?? (string) $this->id;
    }
}
