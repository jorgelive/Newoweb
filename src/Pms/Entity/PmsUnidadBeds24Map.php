<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Repository\PmsUnidadBeds24MapRepository;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsUnidadBeds24Map.
 * Mapea una unidad física del PMS con los identificadores técnicos de Beds24 (Property, Room, Unit).
 * Utiliza UUID para identificación única y auditoría temporal inmutable.
 */
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
#[ORM\HasLifecycleCallbacks]
class PmsUnidadBeds24Map
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    /**
     * Configuración de API de Beds24 vinculada.
     */
    #[ORM\ManyToOne(targetEntity: Beds24Config::class, inversedBy: 'unidadMaps')]
    #[ORM\JoinColumn(
        name: 'beds24_config_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?Beds24Config $beds24Config = null;

    /**
     * Unidad interna del PMS vinculada.
     */
    #[ORM\ManyToOne(targetEntity: PmsUnidad::class, inversedBy: 'beds24Maps')]
    #[ORM\JoinColumn(
        name: 'pms_unidad_id',
        referencedColumnName: 'id',
        nullable: false,
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?PmsUnidad $pmsUnidad = null;

    /**
     * ID de la habitación en Beds24.
     */
    #[ORM\Column(type: 'integer')]
    private ?int $beds24RoomId = null;

    /**
     * ID de la propiedad en Beds24.
     */
    #[ORM\Column(type: 'integer')]
    private ?int $beds24PropertyId = null;

    /**
     * ID de la unidad específica en Beds24 (opcional).
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $beds24UnitId = null;

    /**
     * ID de la propiedad en el Canal Externo (Booking Hotel ID, Airbnb Listing ID).
     * Usado para generar enlaces dinámicos en la UI.
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $channelPropId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $activo = true;

    /**
     * Indica si es el mapa principal para la unidad.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $esPrincipal = false;

    /**
     * Notas técnicas o administrativas sobre el mapeo.
     */
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nota = null;

    /**
     * Constructor de la entidad.
     */
    public function __construct()
    {
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS (Regla 2026-01-14)
     * -------------------------------------------------------------------------
     */

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

    /**
     * Representación textual.
     */
    public function __toString(): string
    {
        $unidad = $this->pmsUnidad?->getNombre() ?? 'Unidad';
        $prop = $this->beds24PropertyId ?? '?';
        $room = $this->beds24RoomId ?? '?';

        return sprintf(
            '%s (Beds24 P:%s R:%s)',
            $unidad,
            $prop,
            $room
        );
    }
}