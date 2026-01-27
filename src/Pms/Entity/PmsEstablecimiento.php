<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Maestro\MaestroPais;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsEstablecimiento.
 * Representa la unidad de negocio principal.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_establecimiento')]
#[ORM\HasLifecycleCallbacks]
class PmsEstablecimiento
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $nombreComercial = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $direccionLinea1 = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $ciudad = null;

    /**
     * Relación con MaestroPais.
     * Apunta al ID Natural de 2 caracteres.
     */
    #[ORM\ManyToOne(targetEntity: MaestroPais::class, inversedBy: 'establecimientos')]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroPais $pais = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefonoPrincipal = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $emailContacto = null;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?DateTimeInterface $horaCheckIn = null;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?DateTimeInterface $horaCheckOut = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $timezone = null;

    /** @var Collection<int, PmsUnidad> */
    #[ORM\OneToMany(mappedBy: 'establecimiento', targetEntity: PmsUnidad::class, cascade: ['persist', 'remove'])]
    private Collection $unidades;

    public function __construct()
    {
        $this->unidades = new ArrayCollection();
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

    public function getNombreComercial(): ?string { return $this->nombreComercial; }
    public function setNombreComercial(?string $nombreComercial): self { $this->nombreComercial = $nombreComercial; return $this; }

    public function getDireccionLinea1(): ?string { return $this->direccionLinea1; }
    public function setDireccionLinea1(?string $direccionLinea1): self { $this->direccionLinea1 = $direccionLinea1; return $this; }

    public function getCiudad(): ?string { return $this->ciudad; }
    public function setCiudad(?string $ciudad): self { $this->ciudad = $ciudad; return $this; }

    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $pais): self { $this->pais = $pais; return $this; }

    public function getTelefonoPrincipal(): ?string { return $this->telefonoPrincipal; }
    public function setTelefonoPrincipal(?string $telefonoPrincipal): self { $this->telefonoPrincipal = $telefonoPrincipal; return $this; }

    public function getEmailContacto(): ?string { return $this->emailContacto; }
    public function setEmailContacto(?string $emailContacto): self { $this->emailContacto = $emailContacto; return $this; }

    public function getHoraCheckIn(): ?DateTimeInterface { return $this->horaCheckIn; }
    public function setHoraCheckIn(?DateTimeInterface $horaCheckIn): self { $this->horaCheckIn = $horaCheckIn; return $this; }

    public function getHoraCheckOut(): ?DateTimeInterface { return $this->horaCheckOut; }
    public function setHoraCheckOut(?DateTimeInterface $horaCheckOut): self { $this->horaCheckOut = $horaCheckOut; return $this; }

    public function getTimezone(): ?string { return $this->timezone; }
    public function setTimezone(?string $timezone): self { $this->timezone = $timezone; return $this; }

    /** @return Collection<int, PmsUnidad> */
    public function getUnidades(): Collection { return $this->unidades; }

    public function addUnidad(PmsUnidad $unidad): self
    {
        if (!$this->unidades->contains($unidad)) {
            $this->unidades->add($unidad);
            $unidad->setEstablecimiento($this);
        }
        return $this;
    }

    public function removeUnidad(PmsUnidad $unidad): self
    {
        if ($this->unidades->removeElement($unidad)) {
            if ($unidad->getEstablecimiento() === $this) {
                $unidad->setEstablecimiento(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->nombreComercial ?? ('Establecimiento ' . ($this->getId() ?? 'N/A'));
    }
}