<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad de Catálogo Maestro que representa un Proveedor logístico u hotelero.
 * Expuesto en API Platform con filtros de búsqueda y seguridad por roles.
 */
#[ApiFilter(SearchFilter::class, properties: [
    'id' => 'exact',
    'nombreComercial' => 'partial',
    'razonSocial' => 'partial'
])]
#[ApiResource(
    shortName: 'Proveedor',
    operations: [
        new GetCollection(
            uriTemplate: '/proveedores',
            normalizationContext: ['groups' => ['proveedor:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),
        new Get(
            uriTemplate: '/proveedores/{id}',
            normalizationContext: ['groups' => ['proveedor:read', 'proveedor:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        )
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_proveedor')]
#[ORM\HasLifecycleCallbacks]
class Proveedor
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['proveedor:read', 'proveedor:item:read', 'proveedor_servicio:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreComercial = null;

    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $razonSocial = null;

    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $telefono = null;

    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $email = null;

    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $descripcion = [];

    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $url = null;

    /**
     * @var Collection<int, ProveedorImagen>
     */
    #[Groups(['proveedor:item:read'])]
    #[ORM\OneToMany(
        mappedBy: 'proveedor',
        targetEntity: ProveedorImagen::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $proveedorImagenes;

    /**
     * @var Collection<int, ProveedorServicio>
     */
    #[Groups(['proveedor:item:read'])]
    #[ORM\OneToMany(
        mappedBy: 'proveedor',
        targetEntity: ProveedorServicio::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $proveedorServicios;

    /**
     * Constructor de la entidad Proveedor.
     * Inicializa el identificador único UUIDv7 y las colecciones internas.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->proveedorImagenes = new ArrayCollection();
        $this->proveedorServicios = new ArrayCollection();
    }

    /**
     * Representación textual legible de la entidad para EasyAdmin y opciones en selects.
     *
     * @return string Retorna el nombre comercial del proveedor o un marcador genérico.
     */
    public function __toString(): string
    {
        return $this->nombreComercial ?? 'Proveedor sin nombre';
    }

    /**
     * Obtiene el nombre comercial del proveedor.
     */
    public function getNombreComercial(): ?string
    {
        return $this->nombreComercial;
    }

    /**
     * Establece el nombre comercial del proveedor.
     */
    public function setNombreComercial(string $nombreComercial): self
    {
        $this->nombreComercial = $nombreComercial;
        return $this;
    }

    /**
     * Obtiene la razón social legal del proveedor.
     */
    public function getRazonSocial(): ?string
    {
        return $this->razonSocial;
    }

    /**
     * Establece la razón social legal del proveedor.
     */
    public function setRazonSocial(?string $razonSocial): self
    {
        $this->razonSocial = $razonSocial;
        return $this;
    }

    /**
     * Obtiene el número de teléfono principal del proveedor.
     */
    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    /**
     * Establece el número de teléfono principal del proveedor.
     */
    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono;
        return $this;
    }

    /**
     * Obtiene el correo electrónico comercial del proveedor.
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Establece el correo electrónico comercial del proveedor.
     */
    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    /**
     * Obtiene el título estructurado en formato JSON.
     * Diseñado para almacenar traducciones dinámicas.
     */
    public function getTitulo(): array
    {
        return $this->titulo;
    }

    /**
     * Establece el título estructurado en formato JSON.
     */
    public function setTitulo(array $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    /**
     * Obtiene la descripción estructurada en formato JSON.
     */
    public function getDescripcion(): array
    {
        return $this->descripcion;
    }

    /**
     * Establece la descripción estructurada en formato JSON.
     */
    public function setDescripcion(array $descripcion): self
    {
        $this->descripcion = $descripcion;
        return $this;
    }

    /**
     * Obtiene la URL de texto externa asociada al proveedor.
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * Establece la URL de texto externa asociada al proveedor.
     */
    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Obtiene la colección completa de imágenes pertenecientes a la galería del proveedor.
     *
     * @return Collection<int, ProveedorImagen>
     */
    public function getProveedorImagenes(): Collection
    {
        return $this->proveedorImagenes;
    }

    public function addProveedorImagen(ProveedorImagen $proveedorImagen): self
    {
        if (!$this->proveedorImagenes->contains($proveedorImagen)) {
            $this->proveedorImagenes->add($proveedorImagen);
            $proveedorImagen->setProveedor($this);
        }
        return $this;
    }

    public function removeProveedorImagen(ProveedorImagen $proveedorImagen): self
    {
        if ($this->proveedorImagenes->removeElement($proveedorImagen)) {
            if ($proveedorImagen->getProveedor() === $this) {
                $proveedorImagen->setProveedor(null);
            }
        }
        return $this;
    }

    /**
     * Obtiene la colección completa de servicios pertenecientes al proveedor.
     *
     * @return Collection<int, ProveedorServicio>
     */
    public function getProveedorServicios(): Collection
    {
        return $this->proveedorServicios;
    }

    public function addProveedorServicio(ProveedorServicio $proveedorServicio): self
    {
        if (!$this->proveedorServicios->contains($proveedorServicio)) {
            $this->proveedorServicios->add($proveedorServicio);
            $proveedorServicio->setProveedor($this);
        }
        return $this;
    }

    public function removeProveedorServicio(ProveedorServicio $proveedorServicio): self
    {
        if ($this->proveedorServicios->removeElement($proveedorServicio)) {
            if ($proveedorServicio->getProveedor() === $this) {
                $proveedorServicio->setProveedor(null);
            }
        }
        return $this;
    }

    /* ========================================================================
     * MÉTODOS DE SOPORTE PARA FRONTEND (API PLATFORM / VUE / STIMULUS)
     * ======================================================================== */

    /**
     * Devuelve el ID casteado como string para su manipulación directa en JS.
     */
    #[Groups(['proveedor:read'])]
    public function getProveedorId(): ?string
    {
        return $this->getId() ? (string) $this->getId() : null;
    }

    /**
     * Expone la representación visual amigable de la entidad para inyectarse en un TomSelect o componente de Vue.
     */
    #[Groups(['proveedor:read'])]
    public function getEtiquetaOpciones(): string
    {
        return $this->__toString();
    }

    /**
     * Getter virtual para no romper EasyAdmin al usar el campo 'virtualTitulo'.
     */
    public function getVirtualTitulo(): string
    {
        return '';
    }
}