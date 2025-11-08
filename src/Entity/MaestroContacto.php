<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'mae_contacto')]
class MaestroContacto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150, nullable: false)]
    private ?string $nombre = null;

    #[ORM\ManyToOne(targetEntity: MaestroTipodocumento::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MaestroTipodocumento $tipodocumento = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $numerodocumento = null;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $telefono = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $vehiculoplaca = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $vehiculocolor = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $vehiculomarca = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $vehiculomodelo = null;

    #[ORM\Column(nullable: true)]
    private ?int $vehiculoanio = null;

    #[ORM\ManyToOne(targetEntity: MaestroTipocontacto::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MaestroTipocontacto $tipocontacto = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function getId(): ?int { return $this->id; }
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getTipodocumento(): ?MaestroTipodocumento { return $this->tipodocumento; }
    public function setTipodocumento(?MaestroTipodocumento $tipodocumento): self { $this->tipodocumento = $tipodocumento; return $this; }

    public function getNumerodocumento(): ?string { return $this->numerodocumento; }
    public function setNumerodocumento(?string $numerodocumento): self { $this->numerodocumento = $numerodocumento; return $this; }

    public function getTelefono(): ?string { return $this->telefono; }
    public function setTelefono(?string $telefono): self { $this->telefono = $telefono; return $this; }

    public function getVehiculoplaca(): ?string { return $this->vehiculoplaca; }
    public function setVehiculoplaca(?string $vehiculoplaca): self { $this->vehiculoplaca = $vehiculoplaca; return $this; }

    public function getVehiculocolor(): ?string { return $this->vehiculocolor; }
    public function setVehiculocolor(?string $vehiculocolor): self { $this->vehiculocolor = $vehiculocolor; return $this; }

    public function getVehiculomarca(): ?string { return $this->vehiculomarca; }
    public function setVehiculomarca(?string $vehiculomarca): self { $this->vehiculomarca = $vehiculomarca; return $this; }

    public function getVehiculomodelo(): ?string { return $this->vehiculomodelo; }
    public function setVehiculomodelo(?string $vehiculomodelo): self { $this->vehiculomodelo = $vehiculomodelo; return $this; }

    public function getVehiculoanio(): ?int { return $this->vehiculoanio; }
    public function setVehiculoanio(?int $vehiculoanio): self { $this->vehiculoanio = $vehiculoanio; return $this; }

    public function getTipocontacto(): ?MaestroTipocontacto { return $this->tipocontacto; }
    public function setTipocontacto(?MaestroTipocontacto $tipocontacto): self { $this->tipocontacto = $tipocontacto; return $this; }

    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
}
