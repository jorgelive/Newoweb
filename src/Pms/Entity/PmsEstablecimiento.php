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
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entidad PmsEstablecimiento.
 * Representa la unidad de negocio principal (Hotel/Edificio).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_establecimiento')]
#[ORM\HasLifecycleCallbacks]
class PmsEstablecimiento
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: Beds24Config::class, inversedBy: 'establecimientos')]
    #[ORM\JoinColumn(
        name: 'beds24_config_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'CASCADE',
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    #[Assert\NotNull(message: 'Debes seleccionar una configuración de Beds24.')]
    private ?Beds24Config $beds24Config = null;


    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Assert\NotBlank(message: 'El nombre comercial es obligatorio.')]
    #[Assert\Length(max: 180)]
    private ?string $nombreComercial = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\NotBlank(message: 'La dirección es obligatoria.')]
    #[Assert\Length(max: 255)]
    private ?string $direccionLinea1 = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Assert\NotBlank(message: 'La ciudad es obligatoria.')]
    #[Assert\Length(max: 100)]
    private ?string $ciudad = null;

    #[ORM\ManyToOne(targetEntity: MaestroPais::class, inversedBy: 'establecimientos')]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: true)]
    #[Assert\NotNull(message: 'Debes seleccionar un país.')]
    private ?MaestroPais $pais = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $telefonoPrincipal = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    #[Assert\NotBlank(message: 'El email de contacto es obligatorio.')]
    #[Assert\Email]
    #[Assert\Length(max: 150)]
    private ?string $emailContacto = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Assert\NotNull]
    private ?DateTimeInterface $horaCheckIn = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Assert\NotNull]
    private ?DateTimeInterface $horaCheckOut = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Timezone]
    private ?string $timezone = null;

    // ============================================================
    // 🔐 SEGURIDAD / ACCESO (NUEVO)
    // ============================================================

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoCajaPrincipal = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoCajaSecundaria = null;

    // ============================================================
    // RELACIONES
    // ============================================================

    #[ORM\OneToMany(mappedBy: 'establecimiento', targetEntity: PmsUnidad::class, cascade: ['persist', 'remove'])]
    private Collection $unidades;

    #[ORM\OneToMany(mappedBy: 'establecimiento', targetEntity: PmsReserva::class, cascade: ['persist', 'remove'])]
    private Collection $reservas;

    #[ORM\OneToMany(mappedBy: 'establecimiento', targetEntity: PmsEstablecimientoVirtual::class, cascade: ['persist', 'remove'])]
    private Collection $virtualEstablecimientos;

    public function __construct()
    {
        $this->unidades = new ArrayCollection();
        $this->reservas = new ArrayCollection();
        $this->virtualEstablecimientos = new ArrayCollection();
        $this->id = Uuid::v7();
    }

    // ... Getters y Setters existentes ...


    public function getBeds24Config(): ?Beds24Config { return $this->beds24Config; }
    public function setBeds24Config(?Beds24Config $beds24Config): self { $this->beds24Config = $beds24Config; return $this; }

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

    // ============================================================
    // GETTERS Y SETTERS DE SEGURIDAD
    // ============================================================

    public function getCodigoCajaPrincipal(): ?string
    {
        return $this->codigoCajaPrincipal;
    }

    public function setCodigoCajaPrincipal(?string $codigoCajaPrincipal): self
    {
        $this->codigoCajaPrincipal = $codigoCajaPrincipal;
        return $this;
    }

    public function getCodigoCajaSecundaria(): ?string
    {
        return $this->codigoCajaSecundaria;
    }

    public function setCodigoCajaSecundaria(?string $codigoCajaSecundaria): self
    {
        $this->codigoCajaSecundaria = $codigoCajaSecundaria;
        return $this;
    }

    // ============================================================
    // COLECCIONES
    // ============================================================

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

    public function getReservas(): Collection { return $this->reservas; }

    public function addReserva(PmsReserva $reserva): self
    {
        if (!$this->reservas->contains($reserva)) {
            $this->reservas->add($reserva);
            $reserva->setEstablecimiento($this);
        }
        return $this;
    }

    public function removeReserva(PmsReserva $reserva): self
    {
        if ($this->reservas->removeElement($reserva)) {
            if ($reserva->getEstablecimiento() === $this) {
                $reserva->setEstablecimiento(null);
            }
        }
        return $this;
    }

    public function getVirtualEstablecimientos(): Collection { return $this->virtualEstablecimientos; }

    public function addVirtualEstablecimiento(PmsEstablecimientoVirtual $virtualEstablecimiento): self
    {
        if (!$this->virtualEstablecimientos->contains($virtualEstablecimiento)) {
            $this->virtualEstablecimientos->add($virtualEstablecimiento);
            $virtualEstablecimiento->setEstablecimiento($this);
        }
        return $this;
    }

    public function removeVirtualEstablecimiento(PmsEstablecimientoVirtual $virtualEstablecimiento): self
    {
        if ($this->virtualEstablecimientos->removeElement($virtualEstablecimiento)) {
            if ($virtualEstablecimiento->getEstablecimiento() === $this) {
                $virtualEstablecimiento->setEstablecimiento(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->nombreComercial ?? ('Establecimiento ' . ($this->getId() ?? 'N/A'));
    }
}