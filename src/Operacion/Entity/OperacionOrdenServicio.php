<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Cotizacion\Entity\CotizacionFile;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),
        new Get(
            security: "is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear órdenes de servicio.'
        ),
        new Put(
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para editar órdenes de servicio.'
        ),
        new Patch(
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para actualizar órdenes de servicio.'
        ),
        new Delete(
            security: "is_granted('" . Roles::OPERACIONES_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar órdenes de servicio.'
        ),
    ],
    routePrefix: '/ops',
    normalizationContext: ['groups' => ['operacion:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['operacion:write']]
)]
#[ORM\Entity]
#[ORM\Table(name: 'operacion_orden_servicio')]
#[ORM\HasLifecycleCallbacks]
class OperacionOrdenServicio
{
    use IdTrait;
    use TimestampTrait;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $numeroOs;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CotizacionFile $file = null;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $proveedorMaestroId = null;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $proveedorNombreManual = null;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: EstadoOrdenServicioEnum::class, options: ['default' => 'borrador'])]
    private EstadoOrdenServicioEnum $estadoOs = EstadoOrdenServicioEnum::BORRADOR;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_os', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $monedaOs = null;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $totalOs = '0.00';

    /**
     * @var Collection<int, OperacionServicio>
     */
    #[Groups(['operacion:read'])]
    #[ORM\OneToMany(mappedBy: 'ordenServicio', targetEntity: OperacionServicio::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $operacionServicios;

    /**
     * @var Collection<int, OperacionMensaje>
     */
    #[Groups(['operacion:read'])]
    #[ORM\OneToMany(mappedBy: 'ordenServicio', targetEntity: OperacionMensaje::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $mensajes;

    public function __construct()
    {
        $this->initializeId();
        $this->operacionServicios = new ArrayCollection();
        $this->mensajes = new ArrayCollection();
    }

    #[Groups(['operacion:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['operacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    public function getNumeroOs(): string { return $this->numeroOs; }
    public function setNumeroOs(string $numeroOs): self { $this->numeroOs = $numeroOs; return $this; }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    public function getProveedorMaestroId(): ?string { return $this->proveedorMaestroId; }
    public function setProveedorMaestroId(?string $proveedorMaestroId): self { $this->proveedorMaestroId = $proveedorMaestroId; return $this; }

    public function getProveedorNombreManual(): ?string { return $this->proveedorNombreManual; }
    public function setProveedorNombreManual(?string $proveedorNombreManual): self { $this->proveedorNombreManual = $proveedorNombreManual; return $this; }

    public function getEstadoOs(): EstadoOrdenServicioEnum { return $this->estadoOs; }
    public function setEstadoOs(EstadoOrdenServicioEnum $estadoOs): self { $this->estadoOs = $estadoOs; return $this; }

    public function getMonedaOs(): ?MaestroMoneda { return $this->monedaOs; }
    public function setMonedaOs(?MaestroMoneda $monedaOs): self { $this->monedaOs = $monedaOs; return $this; }

    public function getTotalOs(): string { return $this->totalOs; }
    public function setTotalOs(string $totalOs): self { $this->totalOs = $totalOs; return $this; }

    public function getOperacionServicios(): Collection { return $this->operacionServicios; }

    public function addOperacionServicio(OperacionServicio $operacionServicio): self
    {
        if (!$this->operacionServicios->contains($operacionServicio)) {
            $this->operacionServicios->add($operacionServicio);
            $operacionServicio->setOrdenServicio($this);
        }
        return $this;
    }

    public function removeOperacionServicio(OperacionServicio $operacionServicio): self
    {
        if ($this->operacionServicios->removeElement($operacionServicio)) {
            if ($operacionServicio->getOrdenServicio() === $this) {
                $operacionServicio->setOrdenServicio(null);
            }
        }
        return $this;
    }

    public function getMensajes(): Collection { return $this->mensajes; }

    public function addMensaje(OperacionMensaje $mensaje): self
    {
        if (!$this->mensajes->contains($mensaje)) {
            $this->mensajes->add($mensaje);
            $mensaje->setOrdenServicio($this);
        }
        return $this;
    }

    public function removeMensaje(OperacionMensaje $mensaje): self
    {
        if ($this->mensajes->removeElement($mensaje)) {
            if ($mensaje->getOrdenServicio() === $this) {
                $mensaje->setOrdenServicio(null);
            }
        }
        return $this;
    }
}
