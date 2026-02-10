<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad PmsEstablecimientoVirtual.
 *
 * Representa una agrupación lógica o "Listing Comercial" GLOBAL del establecimiento.
 * Ej: "Saphy" es una entidad única que puede estar asignada a múltiples unidades físicas (101, 102)
 * a través de los mapas de Beds24.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_establecimiento_virtual')]
#[ORM\HasLifecycleCallbacks]
class PmsEstablecimientoVirtual
{
    use IdTrait;
    use TimestampTrait;

    /**
     * El establecimiento físico (Hotel/Edificio) al que pertenece este listing virtual.
     */
    #[ORM\ManyToOne(targetEntity: PmsEstablecimiento::class, inversedBy: 'virtualEstablecimientos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PmsEstablecimiento $establecimiento = null;

    /**
     * Nombre comercial (Ej: "Saphy", "Inti").
     */
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    /**
     * Código único para casar unidades al mover reservas.
     * Ej: 'SAPHY', 'INTI'.
     */
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $codigo = null;

    /**
     * ID de la propiedad en el Canal (Ej: Hotel ID de Booking.com).
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoExterno = null;

    /**
     * Si es true, este es el establecimiento virtual por defecto.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $esPrincipal = false;

    /**
     * Los mapas técnicos de Beds24 que usan este establecimiento virtual.
     */
    #[ORM\OneToMany(mappedBy: 'virtualEstablecimiento', targetEntity: PmsUnidadBeds24Map::class)]
    private Collection $beds24Maps;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->beds24Maps = new ArrayCollection();
    }

    // ... Getters y Setters ...

    public function getEstablecimiento(): ?PmsEstablecimiento
    {
        return $this->establecimiento;
    }

    public function setEstablecimiento(?PmsEstablecimiento $establecimiento): self
    {
        $this->establecimiento = $establecimiento;
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigo(string $codigo): self
    {
        $this->codigo = strtoupper(trim($codigo));
        return $this;
    }

    public function getCodigoExterno(): ?string
    {
        return $this->codigoExterno;
    }

    public function setCodigoExterno(?string $codigoExterno): self
    {
        $this->codigoExterno = $codigoExterno;
        return $this;
    }

    public function isEsPrincipal(): bool
    {
        return $this->esPrincipal;
    }

    public function setEsPrincipal(bool $esPrincipal): self
    {
        $this->esPrincipal = $esPrincipal;
        return $this;
    }

    public function getBeds24Maps(): Collection
    {
        return $this->beds24Maps;
    }

    public function __toString(): string
    {
        return $this->nombre ?? $this->codigo ?? 'Establecimiento Virtual';
    }
}