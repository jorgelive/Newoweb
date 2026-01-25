<?php

namespace App\Pms\Entity;

use App\Pms\Repository\PmsUnidadBeds24MapRepository;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use App\Pms\Entity\Beds24Config;

#[ORM\Entity(repositoryClass: PmsUnidadBeds24MapRepository::class)]
#[ORM\Table(
    name: 'pms_unidad_beds24_map',
    indexes: [
        new ORM\Index(columns: ['beds24RoomId', 'beds24UnitId'], name: 'idx_beds24_room_unit'),
        new ORM\Index(columns: ['beds24_config_id'], name: 'idx_beds24_config'),
        new ORM\Index(columns: ['beds24PropertyId'], name: 'idx_beds24_property')
    ],
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_pms_unidad_beds24_config', columns: ['pms_unidad_id', 'beds24_config_id'])
    ]
)]
class PmsUnidadBeds24Map
{
    public function __construct()
    {
        // Colección eliminada por aplanamiento de arquitectura
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Beds24Config::class, inversedBy: 'unidadMaps')]
    #[ORM\JoinColumn(name: 'beds24_config_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Beds24Config $beds24Config = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class, inversedBy: 'beds24Maps')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsUnidad $pmsUnidad = null;

    #[ORM\Column(type: 'integer')]
    private ?int $beds24RoomId = null;

    #[ORM\Column(type: 'integer')]
    private ?int $beds24PropertyId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $beds24UnitId = null;

    /**
     * ID de la propiedad en el Canal Externo (Booking Hotel ID, Airbnb Listing ID).
     * Usado para generar enlaces dinámicos.
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $channelPropId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $activo = null;

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

    // Relación OneToMany hacia Delivery eliminada.

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBeds24Config(): ?Beds24Config
    {
        return $this->beds24Config;
    }

    public function setBeds24Config(?Beds24Config $beds24Config): self
    {
        $this->beds24Config = $beds24Config;
        return $this;
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

    public function getBeds24PropertyId(): ?int
    {
        return $this->beds24PropertyId;
    }

    public function setBeds24PropertyId(?int $beds24PropertyId): self
    {
        $this->beds24PropertyId = $beds24PropertyId;
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

    public function getChannelPropId(): ?string
    {
        return $this->channelPropId;
    }

    public function setChannelPropId(?string $channelPropId): self
    {
        $this->channelPropId = $channelPropId;
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
        $cfg = $this->beds24Config ? (string) $this->beds24Config : 'Beds24';
        $prop = $this->beds24PropertyId ?? '?';
        $room = $this->beds24RoomId ?? '?';
        $unit = $this->beds24UnitId ?? '-';
        $estado = ($this->activo ?? false) ? 'activo' : 'inactivo';

        return sprintf(
            '%s (%s • Prop %s • Room %s • Unit %s • %s)',
            $unidad,
            $cfg,
            $prop,
            $room,
            $unit,
            $estado
        );
    }
}