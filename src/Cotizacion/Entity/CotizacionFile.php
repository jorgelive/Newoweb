<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroPais;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\LocatorTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * El Expediente raíz. Agrupa todas las propuestas comerciales de un cliente o grupo.
 */
#[ApiResource(
    shortName: 'CotizacionFile',
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['file:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        ),
        new Get(
            normalizationContext: ['groups' => ['file:read', 'file:item:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        ),
        new Post(
            denormalizationContext: ['groups' => ['file:write']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear expedientes.'
        ),
        new Put(
            denormalizationContext: ['groups' => ['file:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar expedientes.'
        ),
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar expedientes.'
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_file')]
#[ORM\HasLifecycleCallbacks]
class CotizacionFile
{
    use IdTrait;
    use TimestampTrait;
    use LocatorTrait;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreGrupo = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $pasajeroPrincipal = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $email = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $telefono = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroPais $pais = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroIdioma $idioma = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 30, options: ['default' => 'abierto'])]
    private string $estado = 'abierto';

    /**
     * @var Collection<int, Cotizacion>
     */
    #[Groups(['file:item:read'])]
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: Cotizacion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['version' => 'DESC'])]
    private Collection $cotizaciones;

    public function __construct()
    {
        $this->initializeId();
        $this->initializeLocator();
        $this->cotizaciones = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombreGrupo ?? 'File sin nombre';
    }

    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $pais): self { $this->pais = $pais; return $this; }

    public function getIdioma(): ?MaestroIdioma { return $this->idioma; }
    public function setIdioma(?MaestroIdioma $idioma): self { $this->idioma = $idioma; return $this; }

    public function getNombreGrupo(): ?string { return $this->nombreGrupo; }
    public function setNombreGrupo(string $nombreGrupo): self { $this->nombreGrupo = $nombreGrupo; return $this; }

    public function getPasajeroPrincipal(): ?string { return $this->pasajeroPrincipal; }
    public function setPasajeroPrincipal(?string $pasajeroPrincipal): self { $this->pasajeroPrincipal = $pasajeroPrincipal; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getTelefono(): ?string { return $this->telefono; }
    public function setTelefono(?string $telefono): self { $this->telefono = $telefono; return $this; }

    public function getEstado(): string { return $this->estado; }
    public function setEstado(string $estado): self { $this->estado = $estado; return $this; }

    public function getCotizaciones(): Collection { return $this->cotizaciones; }
    public function addCotizacion(Cotizacion $cotizacion): self
    {
        if (!$this->cotizaciones->contains($cotizacion)) {
            $this->cotizaciones->add($cotizacion);
            $cotizacion->setFile($this);
        }
        return $this;
    }
    public function removeCotizacion(Cotizacion $cotizacion): self
    {
        if ($this->cotizaciones->removeElement($cotizacion)) {
            if ($cotizacion->getFile() === $this) { $cotizacion->setFile(null); }
        }
        return $this;
    }
}