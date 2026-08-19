<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
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
 * Entidad que representa un servicio ofrecido por un organizacion (ej. Habitaciones de un Hotel).
 * Expuesto en API Platform con filtros de búsqueda y seguridad por roles.
 */
#[ApiFilter(SearchFilter::class, properties: [
    'id' => 'exact',
    'nombre' => 'partial'
])]
#[ApiResource(
    shortName: 'OrganizacionServicio',
    operations: [
        new GetCollection(
            uriTemplate: '/organizacion-servicios',
            normalizationContext: ['groups' => ['organizacion_servicio:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),
        new Get(
            uriTemplate: '/organizacion-servicios/{id}',
            normalizationContext: ['groups' => ['organizacion_servicio:read', 'organizacion_servicio:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Escritura para el CRUD de catálogo en Vue. `organizacion` es escribible porque el
        // servicio se crea desde la ficha de su organizacion y llega como IRI.
        new Post(
            uriTemplate: '/organizacion-servicios',
            denormalizationContext: ['groups' => ['organizacion_servicio:write']],
            securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear servicios de organizacion.'
        ),
        new Put(
            uriTemplate: '/organizacion-servicios/{id}',
            denormalizationContext: ['groups' => ['organizacion_servicio:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar servicios de organizacion.'
        ),
        new Patch(
            uriTemplate: '/organizacion-servicios/{id}',
            denormalizationContext: ['groups' => ['organizacion_servicio:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar servicios de organizacion.'
        ),
        // Ojo: `CotizacionCotcomponente` guarda un soft-link `organizaciónServicioMaestroId`
        // con su título e imágenes ya congelados. Borrar aquí no toca las cotizaciones
        // emitidas.
        new Delete(
            uriTemplate: '/organizacion-servicios/{id}',
            security: "is_granted('" . Roles::MAESTROS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar servicios de organizacion.'
        ),
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_organizacion_servicio')]
#[ORM\HasLifecycleCallbacks]
class TravelOrganizacionServicio
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    /**
     * El id además del `@id`. Sin esto, al leerse anidado dentro de la ficha del organizacion
     * sólo llegaba la IRI y el front no podía construir las URLs de borrado.
     */
    #[Groups(['organizacion:item:read', 'organizacion_servicio:read', 'organizacion_servicio:item:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    #[Groups(['organizacion:item:read', 'organizacion_servicio:read', 'organizacion_servicio:item:read', 'componente:item:read', 'organizacion_servicio:write'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombre = null;

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['organizacion:item:read', 'organizacion_servicio:read', 'organizacion_servicio:item:read', 'organizacion_servicio:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['organizacion:item:read', 'organizacion_servicio:read', 'organizacion_servicio:item:read', 'organizacion_servicio:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $descripcion = [];

    #[Groups(['organizacion:item:read', 'organizacion_servicio:read', 'organizacion_servicio:item:read', 'organizacion_servicio:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $url = null;

    #[Groups(['organizacion_servicio:read', 'organizacion_servicio:item:read', 'componente:item:read', 'organizacion_servicio:write'])]
    #[ORM\ManyToOne(targetEntity: TravelOrganizacion::class, inversedBy: 'servicios')]
    #[ORM\JoinColumn(name: 'organizacion_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?TravelOrganizacion $organizacion = null;

    /**
     * @var Collection<int, TravelOrganizacionServicioImagen>
     */
    #[Groups(['organizacion:item:read', 'organizacion_servicio:item:read'])]
    #[ORM\OneToMany(
        mappedBy: 'organizacionServicio',
        targetEntity: TravelOrganizacionServicioImagen::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $imagenes;

    /**
     * Constructor de la entidad TravelOrganizacionServicio.
     * Inicializa el identificador único UUIDv7 y la colección interna de imágenes.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->imagenes = new ArrayCollection();
    }

    /**
     * Representación textual legible de la entidad para EasyAdmin.
     * Incluye el nombre comercial del organizacion para identificar a qué hotel/organizacion pertenece.
     */
    public function __toString(): string
    {
        $nombreServicio = $this->nombre ?? 'Servicio sin nombre';
        $nombreOrganizacion = $this->organizacion?->getNombreComercial();

        return $nombreOrganizacion
            ? sprintf('%s - %s', $nombreOrganizacion, $nombreServicio)
            : $nombreServicio;
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
     *
     * @return list<array{language?: string, content?: string|null}>
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
     *
     * @param list<array{language?: string, content?: string|null}> $titulo
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
     *
     * @return list<array{language?: string, content?: string|null}>
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
     *
     * @param list<array{language?: string, content?: string|null}> $descripcion
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
     * Obtiene el organizacion principal que ofrece este servicio.
     */
    public function getOrganizacion(): ?TravelOrganizacion
    {
        return $this->organizacion;
    }

    /**
     * Establece el organizacion principal que ofrece este servicio.
     */
    public function setOrganizacion(?TravelOrganizacion $organizacion): self
    {
        $this->organizacion = $organizacion;
        return $this;
    }

    /**
     * Obtiene la colección completa de imágenes pertenecientes a la galería de este servicio.
     *
     * @return Collection<int, TravelOrganizacionServicioImagen>
     *
     * @return Collection<int, TravelOrganizacionServicioImagen>
     */
    public function getImagenes(): Collection
    {
        return $this->imagenes;
    }

    /**
     * Añade un recurso de imagen a la galería del servicio garantizando la sincronización bidireccional.
     *
     * @param TravelOrganizacionServicioImagen $imagen Instancia de la imagen a asociar.
     * @return $this
     */
    public function addImagen(TravelOrganizacionServicioImagen $imagen): self
    {
        if (!$this->imagenes->contains($imagen)) {
            $this->imagenes->add($imagen);
            $imagen->setOrganizacionServicio($this);
        }
        return $this;
    }

    /**
     * Remueve un recurso de imagen de la galería del servicio rompiendo el vínculo asociativo.
     *
     * @param TravelOrganizacionServicioImagen $imagen Instancia de la imagen a desvincular.
     * @return $this
     */
    public function removeImagen(TravelOrganizacionServicioImagen $imagen): self
    {
        if ($this->imagenes->removeElement($imagen)) {
            if ($imagen->getOrganizacionServicio() === $this) {
                $imagen->setOrganizacionServicio(null);
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
    #[Groups(['organizacion_servicio:read', 'componente:item:read'])]
    public function getOrganizacionServicioId(): ?string
    {
        return $this->getId() ? (string) $this->getId() : null;
    }

    /**
     * Expone la representación visual amigable de la entidad para inyectarse en un TomSelect o componente de Vue.
     * Concatena el nombre del organizacion para que en los listados del frontend sea fácil identificar a qué hotel pertenece.
     */
    #[Groups(['organizacion_servicio:read'])]
    public function getEtiquetaOpciones(): string
    {
        $nombreOrganizacion = $this->organizacion ? $this->organizacion->getNombreComercial() : 'Desconocido';
        return sprintf('%s - %s', $nombreOrganizacion, $this->nombre ?? 'Servicio sin nombre');
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
}