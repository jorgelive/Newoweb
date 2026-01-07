<?php

namespace App\Pms\Entity;

use App\Entity\MaestroPais;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_establecimiento')]
class PmsEstablecimiento
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $nombreComercial = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $direccionLinea1 = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $ciudad = null;

    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    #[ORM\JoinColumn(nullable: true)]
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

    public function getNombreComercial(): ?string
    {
        return $this->nombreComercial;
    }

    public function setNombreComercial(?string $nombreComercial): self
    {
        $this->nombreComercial = $nombreComercial;

        return $this;
    }

    public function getDireccionLinea1(): ?string
    {
        return $this->direccionLinea1;
    }

    public function setDireccionLinea1(?string $direccionLinea1): self
    {
        $this->direccionLinea1 = $direccionLinea1;

        return $this;
    }

    public function getCiudad(): ?string
    {
        return $this->ciudad;
    }

    public function setCiudad(?string $ciudad): self
    {
        $this->ciudad = $ciudad;

        return $this;
    }

    public function getPais(): ?MaestroPais
    {
        return $this->pais;
    }

    public function setPais(?MaestroPais $pais): self
    {
        $this->pais = $pais;
        return $this;
    }

    public function getTelefonoPrincipal(): ?string
    {
        return $this->telefonoPrincipal;
    }

    public function setTelefonoPrincipal(?string $telefonoPrincipal): self
    {
        $this->telefonoPrincipal = $telefonoPrincipal;

        return $this;
    }

    public function getEmailContacto(): ?string
    {
        return $this->emailContacto;
    }

    public function setEmailContacto(?string $emailContacto): self
    {
        $this->emailContacto = $emailContacto;

        return $this;
    }

    public function getHoraCheckIn(): ?DateTimeInterface
    {
        return $this->horaCheckIn;
    }

    public function setHoraCheckIn(?DateTimeInterface $horaCheckIn): self
    {
        $this->horaCheckIn = $horaCheckIn;

        return $this;
    }

    public function getHoraCheckOut(): ?DateTimeInterface
    {
        return $this->horaCheckOut;
    }

    public function setHoraCheckOut(?DateTimeInterface $horaCheckOut): self
    {
        $this->horaCheckOut = $horaCheckOut;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): self
    {
        $this->timezone = $timezone;

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
        return $this->nombreComercial ?? ('Establecimiento #' . $this->id);
    }
}
