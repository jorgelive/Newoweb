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
 * Entidad que representa un servicio ofrecido por un proveedor (ej. Habitaciones de un Hotel).
 * Expuesto en API Platform con filtros de búsqueda y seguridad por roles.
 */
#[ApiFilter(SearchFilter::class, properties: [
    'id' => 'exact',
    'nombre' => 'partial'
])]
#[ApiResource(
    shortName: 'ProveedorServicio',
    operations: [
        new GetCollection(
            uriTemplate: '/proveedor-servicios',
            normalizationContext: ['groups' => ['proveedor_servicio:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),
        new Get(
            uriTemplate: '/proveedor-servicios/{id}',
            normalizationContext: ['groups' => ['proveedor_servicio:read', 'proveedor_servicio:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        )
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_proveedor_servicio')]
#[ORM\HasLifecycleCallbacks]
class ProveedorServicio
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['proveedor:item:read', 'proveedor_servicio:read', 'proveedor_servicio:item:read', 'componente:item:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombre = null;

    #[Groups(['proveedor:item:read', 'proveedor_servicio:read', 'proveedor_servicio:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['proveedor:item:read', 'proveedor_servicio:read', 'proveedor_servicio:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $descripcion = [];

    #[Groups(['proveedor:item:read', 'proveedor_servicio:read', 'proveedor_servicio:item:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $url = null;

    #[Groups(['proveedor_servicio:read', 'proveedor_servicio:item:read', 'componente:item:read'])]
    #[ORM\ManyToOne(targetEntity: Proveedor::class, inversedBy: 'proveedorServicios')]
    #[ORM\JoinColumn(name: 'proveedor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Proveedor $proveedor = null;

    /**
     * @var Collection<int, ProveedorServicioImagen>
     */
    #[Groups(['proveedor:item:read', 'proveedor_servicio:item:read'])]
    #[ORM\OneToMany(
        mappedBy: 'proveedorServicio',
        targetEntity: ProveedorServicioImagen::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $proveedorServicioImagenes;

    /**
     * Constructor de la entidad ProveedorServicio.
     * Inicializa el identificador único UUIDv7 y la colección interna de imágenes.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->proveedorServicioImagenes = new ArrayCollection();
    }

    /**
     * Representación textual legible de la entidad para EasyAdmin.
     *
     * @return string Retorna el nombre del servicio o un marcador genérico.
     */
    public function __toString(): string
    {
        return $this->nombre ?? 'Servicio sin nombre';
    }

    /**
     * Obtiene el nombre identificativo del servicio.
     */
    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    /**
     * Establece el nombre identificativo del servicio.
     */
    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    /**
     * Obtiene el título estructurado en formato JSON.
     * Empleado habitualmente para almacenar internacionalización (i18n) a nivel de servicio.
     * Ejemplo de uso: $servicio->getTitulo()['fr'] ?? '';
     *
     * @return array Arreglo asociativo del título.
     */
    public function getTitulo(): array
    {
        return $this->titulo;
    }

    /**
     * Establece el título estructurado en formato JSON.
     *
     * @param array $titulo Arreglo de datos estructurados para el título.
     * @return $this
     */
    public function setTitulo(array $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    /**
     * Obtiene la descripción estructurada en formato JSON.
     * Permite guardar las características u observaciones detalladas del servicio de forma localizada.
     * Ejemplo de uso: $servicio->getDescripcion()['es'] ?? '';
     *
     * @return array Arreglo asociativo de la descripción.
     */
    public function getDescripcion(): array
    {
        return $this->descripcion;
    }

    /**
     * Establece la descripción estructurada en formato JSON.
     *
     * @param array $descripcion Arreglo de datos estructurados para la descripción.
     * @return $this
     */
    public function setDescripcion(array $descripcion): self
    {
        $this->descripcion = $descripcion;
        return $this;
    }

    /**
     * Obtiene la URL de texto externa asociada a este servicio.
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * Establece la URL de texto externa asociada a este servicio.
     *
     * @param string|null $url Enlace directo a especificaciones técnicas externas o microSitios.
     * @return $this
     */
    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Obtiene el proveedor principal que ofrece este servicio.
     */
    public function getProveedor(): ?Proveedor
    {
        return $this->proveedor;
    }

    /**
     * Establece el proveedor principal que ofrece este servicio.
     */
    public function setProveedor(?Proveedor $proveedor): self
    {
        $this->proveedor = $proveedor;
        return $this;
    }

    /**
     * Obtiene la colección completa de imágenes pertenecientes a la galería de este servicio.
     *
     * @return Collection<int, ProveedorServicioImagen>
     */
    public function getProveedorServicioImagenes(): Collection
    {
        return $this->proveedorServicioImagenes;
    }

    /**
     * Añade un recurso de imagen a la galería del servicio garantizando la sincronización bidireccional.
     *
     * @param ProveedorServicioImagen $proveedorServicioImagen Instancia de la imagen a asociar.
     * @return $this
     */
    public function addProveedorServicioImagen(ProveedorServicioImagen $proveedorServicioImagen): self
    {
        if (!$this->proveedorServicioImagenes->contains($proveedorServicioImagen)) {
            $this->proveedorServicioImagenes->add($proveedorServicioImagen);
            $proveedorServicioImagen->setProveedorServicio($this);
        }
        return $this;
    }

    /**
     * Remueve un recurso de imagen de la galería del servicio rompiendo el vínculo asociativo.
     *
     * @param ProveedorServicioImagen $proveedorServicioImagen Instancia de la imagen a desvincular.
     * @return $this
     */
    public function removeProveedorServicioImagen(ProveedorServicioImagen $proveedorServicioImagen): self
    {
        if ($this->proveedorServicioImagenes->removeElement($proveedorServicioImagen)) {
            if ($proveedorServicioImagen->getProveedorServicio() === $this) {
                $proveedorServicioImagen->setProveedorServicio(null);
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
    #[Groups(['proveedor_servicio:read', 'componente:item:read'])]
    public function getProveedorServicioId(): ?string
    {
        return $this->getId() ? (string) $this->getId() : null;
    }

    /**
     * Expone la representación visual amigable de la entidad para inyectarse en un TomSelect o componente de Vue.
     * Concatena el nombre del proveedor para que en los listados del frontend sea fácil identificar a qué hotel pertenece.
     */
    #[Groups(['proveedor_servicio:read'])]
    public function getEtiquetaOpciones(): string
    {
        $nombreProveedor = $this->proveedor ? $this->proveedor->getNombreComercial() : 'Desconocido';
        return sprintf('%s - %s', $nombreProveedor, $this->nombre ?? 'Servicio sin nombre');
    }

    /**
     * Getter virtual para no romper EasyAdmin al usar el campo 'virtualTitulo'.
     */
    public function getVirtualTitulo(): string
    {
        return '';
    }
}