<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsEstablecimientoVirtual;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Repository\PmsUnidadBeds24MapRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
// ✅ Importamos las Constraints
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entidad PmsUnidadBeds24Map.
 * Mapea una unidad física del PMS con los identificadores técnicos de Beds24.
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
        new ORM\UniqueConstraint(
            name: 'uniq_unidad_virtual',
            columns: ['pms_unidad_id', 'virtual_establecimiento_id']
        ),
    ]
)]
#[UniqueEntity(
    fields: ['pmsUnidad', 'virtualEstablecimiento'],
    message: 'Esta unidad física ya tiene asignado este Listing. No puedes repetirlo.',
    errorPath: 'virtualEstablecimiento',
    ignoreNull: true
)]
#[ORM\HasLifecycleCallbacks]
class PmsUnidadBeds24Map
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: Beds24Config::class, inversedBy: 'unidadMaps')]
    #[ORM\JoinColumn(
        name: 'config_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    #[Assert\NotNull(message: 'Debes seleccionar una configuración de Beds24.')]
    private ?Beds24Config $config = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class, inversedBy: 'beds24Maps')]
    #[ORM\JoinColumn(
        name: 'pms_unidad_id',
        referencedColumnName: 'id',
        nullable: false
    )]
    #[Assert\NotNull(message: 'Debes seleccionar una unidad del PMS.')]
    private ?PmsUnidad $pmsUnidad = null;

    #[ORM\ManyToOne(targetEntity: PmsEstablecimientoVirtual::class, inversedBy: 'beds24Maps')]
    #[ORM\JoinColumn(name: 'virtual_establecimiento_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PmsEstablecimientoVirtual $virtualEstablecimiento = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\NotNull(message: 'El Room ID de Beds24 es obligatorio.')]
    #[Assert\Positive(message: 'El Room ID debe ser un número positivo.')]
    private ?int $beds24RoomId = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\Positive(message: 'El Property ID debe ser un número positivo.')]
    private ?int $beds24PropertyId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $beds24UnitId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $activo = true;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $nota = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    // ... (Getters y Setters se mantienen igual) ...

    public function getConfig(): ?Beds24Config { return $this->config; }
    public function setConfig(?Beds24Config $config): self { $this->config = $config; return $this; }

    public function getPmsUnidad(): ?PmsUnidad { return $this->pmsUnidad; }
    public function setPmsUnidad(?PmsUnidad $pmsUnidad): self { $this->pmsUnidad = $pmsUnidad; return $this; }

    public function getVirtualEstablecimiento(): ?PmsEstablecimientoVirtual { return $this->virtualEstablecimiento; }
    public function setVirtualEstablecimiento(?PmsEstablecimientoVirtual $virtualEstablecimiento): self { $this->virtualEstablecimiento = $virtualEstablecimiento; return $this; }

    public function getBeds24RoomId(): ?int { return $this->beds24RoomId; }
    public function setBeds24RoomId(?int $beds24RoomId): self { $this->beds24RoomId = $beds24RoomId; return $this; }

    public function getBeds24PropertyId(): ?int { return $this->beds24PropertyId; }
    public function setBeds24PropertyId(?int $beds24PropertyId): self { $this->beds24PropertyId = $beds24PropertyId; return $this; }

    public function getBeds24UnitId(): ?int { return $this->beds24UnitId; }
    public function setBeds24UnitId(?int $beds24UnitId): self { $this->beds24UnitId = $beds24UnitId; return $this; }

    public function getChannelPropId(): ?string { return $this->virtualEstablecimiento?->getCodigoExterno(); }

    public function isActivo(): ?bool { return $this->activo; }
    public function setActivo(?bool $activo): self { $this->activo = $activo; return $this; }

    public function isEsPrincipal(): bool { return $this->virtualEstablecimiento?->isEsPrincipal() ?? false; }

    public function getNota(): ?string { return $this->nota; }
    public function setNota(?string $nota): self { $this->nota = $nota; return $this; }

    public function __toString(): string
    {
        $unidad = $this->pmsUnidad?->getNombre() ?? 'Unidad';
        $virtual = $this->virtualEstablecimiento ? (' [' . $this->virtualEstablecimiento->getCodigo() . ']') : '';
        $prop = $this->beds24PropertyId ?? '?';
        $room = $this->beds24RoomId ?? '?';

        return sprintf('%s%s (Beds24 P:%s R:%s)', $unidad, $virtual, $prop, $room);
    }
}