<?php

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_unidad_beds24_map')]
class PmsUnidadBeds24Map
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsUnidad $pmsUnidad = null;

    #[ORM\Column(type: 'integer')]
    private ?int $beds24RoomId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $beds24UnitId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $esPrincipal = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nota = null;

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

    public function getPmsUnidad(): ?PmsUnidad
    {
        return $this->pmsUnidad;
    }

    public function setPmsUnidad(?PmsUnidad $pmsUnidad): self
    {
        $this->pmsUnidad = $pmsUnidad;

        return $this;
    }

    public function getBeds24RoomId(): ?int
    {
        return $this->beds24RoomId;
    }

    public function setBeds24RoomId(?int $beds24RoomId): self
    {
        $this->beds24RoomId = $beds24RoomId;

        return $this;
    }

    public function getBeds24UnitId(): ?int
    {
        return $this->beds24UnitId;
    }

    public function setBeds24UnitId(?int $beds24UnitId): self
    {
        $this->beds24UnitId = $beds24UnitId;

        return $this;
    }

    public function isEsPrincipal(): ?bool
    {
        return $this->esPrincipal;
    }

    public function setEsPrincipal(?bool $esPrincipal): self
    {
        $this->esPrincipal = $esPrincipal;

        return $this;
    }

    public function getNota(): ?string
    {
        return $this->nota;
    }

    public function setNota(?string $nota): self
    {
        $this->nota = $nota;

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
        $unidad = $this->pmsUnidad?->getNombre() ?? 'Unidad';
        $room = $this->beds24RoomId ?? '?';
        return $unidad . ' (Room ' . $room . ')';
    }
}
