<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
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
        ),

        // Escritura desde el editor de cotizaciones: el prestador debe quedar SIEMPRE
        // identificado contra el maestro. Hasta ahora el recurso era de sólo lectura y la
        // única salida cuando el proveedor no existía era el campo de texto libre, que deja
        // `prestadorMaestroId` vacío y rompe el histórico financiero.
        new Post(
            uriTemplate: '/proveedores',
            denormalizationContext: ['groups' => ['proveedor:write']],
            securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear proveedores.'
        ),
        new Put(
            uriTemplate: '/proveedores/{id}',
            denormalizationContext: ['groups' => ['proveedor:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar proveedores.'
        ),
        new Patch(
            uriTemplate: '/proveedores/{id}',
            denormalizationContext: ['groups' => ['proveedor:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar proveedores.'
        ),
        // Borrar arrastra imágenes y servicios (cascade + orphanRemoval), y deja huérfanos
        // los soft-links de cotizaciones ya emitidas. Por eso va con permiso de borrado.
        new Delete(
            uriTemplate: '/proveedores/{id}',
            security: "is_granted('" . Roles::MAESTROS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar proveedores.'
        ),
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

    #[Groups(['proveedor:read', 'proveedor:item:read', 'proveedor_servicio:read', 'proveedor:write'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreComercial = null;

    #[Groups(['proveedor:read', 'proveedor:item:read', 'proveedor:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $razonSocial = null;

    #[Groups(['proveedor:read', 'proveedor:item:read', 'proveedor:write'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $telefono = null;

    #[Groups(['proveedor:read', 'proveedor:item:read', 'proveedor:write'])]
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $email = null;

    #[Groups(['proveedor:read', 'proveedor:item:read', 'proveedor:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['proveedor:read', 'proveedor:item:read', 'proveedor:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $descripcion = [];

    #[Groups(['proveedor:read', 'proveedor:item:read', 'proveedor:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $url = null;

    #[Groups(['proveedor:read', 'proveedor:item:read', 'proveedor:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $direccion = null;

    /**
     * @var Collection<int, ProveedorImagen>
     */
    #[Groups(['proveedor:read', 'proveedor:item:read'])]
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
     * Lugares donde opera. Lado DUEÑO.
     *
     * Es COBERTURA, no ubicación: un operador de Lima que también despacha Ica lleva las
     * dos. Por eso es múltiple aunque el componente suela tener una sola — ahí la etiqueta
     * dice dónde ocurre ese servicio concreto, y aquí dice hasta dónde llega el proveedor.
     *
     * Va en `proveedor:read` (la colección) además del item: el listado del catálogo pinta
     * las etiquetas en cada ficha, y con `readableLink: false` viajan como IRIs.
     *
     * @var Collection<int, TravelLugar>
     */
    #[Groups(['proveedor:read', 'proveedor:item:read', 'proveedor:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToMany(targetEntity: TravelLugar::class, inversedBy: 'proveedores')]
    #[ORM\JoinTable(name: 'travel_proveedor_lugar_pool')]
    #[ORM\JoinColumn(name: 'proveedor_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'lugar_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['orden' => 'ASC', 'nombre' => 'ASC'])]
    private Collection $lugares;

    /**
     * Constructor de la entidad Proveedor.
     * Inicializa el identificador único UUIDv7 y las colecciones internas.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->proveedorImagenes = new ArrayCollection();
        $this->proveedorServicios = new ArrayCollection();
        $this->lugares = new ArrayCollection();
    }

    /**
     * El id, además del `@id`. `IdTrait` no lo expone a la API, así que sin este override
     * el listado sólo traía la IRI y el front tenía que parsearla — con el resultado de que
     * `p.id` llegaba `undefined`, las URLs salían como `/proveedores/undefined` y todas las
     * fichas se marcaban como seleccionadas a la vez. Mismo criterio que TravelComponente.
     */
    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
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
     * Obtiene la direccion del proveedor.
     */
    public function getDireccion(): ?string
    {
        return $this->direccion;
    }

    /**
     * Establece la direccion del proveedor.
     */
    public function setDireccion(?string $direccion): self
    {
        $this->direccion = $direccion;
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

    public function getVirtualGaleria(): string
    {
        return '';
    }

    /**
     * @return Collection<int, TravelLugar>
     */
    public function getLugares(): Collection
    {
        return $this->lugares;
    }

    public function addLugar(TravelLugar $lugar): self
    {
        if (!$this->lugares->contains($lugar)) {
            $this->lugares->add($lugar);
        }

        return $this;
    }

    public function removeLugar(TravelLugar $lugar): self
    {
        $this->lugares->removeElement($lugar);

        return $this;
    }

    /** Virtual para EasyAdmin (evita el 500 de TextField + renderAsHtml). */
    public function getVirtualLugares(): string
    {
        return '';
    }
}
