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
use App\Panel\Contract\ConIdentificador;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad de Catálogo Maestro que representa un TravelOrganizacion logístico u hotelero.
 * Expuesto en API Platform con filtros de búsqueda y seguridad por roles.
 */
/**
 * ⚠️ **Aquí NO se declara `id`, y cuesta explicarlo pero importa.**
 *
 * `SearchFilter` **no puede filtrar por UUID en este proyecto**: los identificadores se guardan
 * como `binary(16)` y la librería enlaza el valor sin decir su tipo —`setParameter($p, $values[0])`
 * en `Filter/SearchFilter.php`—, así que compara texto contra binario y no casa nunca. Comprobado
 * contra producción el 20/08/2026: `?nombreComercial=Tambo` devuelve 1 y `?id=<uuid>` devuelve 0.
 *
 * Declararlo es PEOR que no declararlo. Sin declarar, API Platform ignora el parámetro y la
 * colección vuelve entera: las cargas por lote del editor (`?id[]=a&id[]=b&pagination=false`)
 * funcionan de casualidad, porque quien llama busca luego por id dentro de la lista. Declarado,
 * el filtro **sí se aplica** y devuelve **cero** — que es como se rompió el selector de
 * prestadores del editor durante unas horas ese mismo día.
 *
 * Si algún día hace falta filtrar por id de verdad, va por un endpoint propio que resuelva el
 * UUID en PHP. Ver {@see \App\Api\Controller\Travel\TravelOrganizacionServicioOpcionesController}.
 */
#[ApiFilter(SearchFilter::class, properties: [
    'nombreComercial' => 'partial',
    'razonSocial' => 'partial'
])]
#[ApiResource(
    shortName: 'Organizacion',
    operations: [
        new GetCollection(
            uriTemplate: '/organizaciones',
            normalizationContext: ['groups' => ['organizacion:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),
        new Get(
            uriTemplate: '/organizaciones/{id}',
            normalizationContext: ['groups' => ['organizacion:read', 'organizacion:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Escritura desde el editor de cotizaciones: el prestador debe quedar SIEMPRE
        // identificado contra el maestro. Hasta ahora el recurso era de sólo lectura y la
        // única salida cuando el prestador no existía era el campo de texto libre, que deja
        // `prestadorMaestroId` vacío y rompe el histórico financiero.
        new Post(
            uriTemplate: '/organizaciones',
            denormalizationContext: ['groups' => ['organizacion:write']],
            securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear organizaciones.'
        ),
        new Put(
            uriTemplate: '/organizaciones/{id}',
            denormalizationContext: ['groups' => ['organizacion:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar organizaciones.'
        ),
        new Patch(
            uriTemplate: '/organizaciones/{id}',
            denormalizationContext: ['groups' => ['organizacion:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar organizaciones.'
        ),
        // Borrar arrastra imágenes y servicios (cascade + orphanRemoval), y deja huérfanos
        // los soft-links de cotizaciones ya emitidas. Por eso va con permiso de borrado.
        new Delete(
            uriTemplate: '/organizaciones/{id}',
            security: "is_granted('" . Roles::MAESTROS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar organizaciones.'
        ),
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_organizacion')]
#[ORM\HasLifecycleCallbacks]
class TravelOrganizacion implements ConIdentificador
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['organizacion:read', 'organizacion:item:read', 'organizacion_servicio:read', 'organizacion:write'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreComercial = null;

    #[Groups(['organizacion:read', 'organizacion:item:read', 'organizacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $razonSocial = null;

    #[Groups(['organizacion:read', 'organizacion:item:read', 'organizacion:write'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $telefono = null;

    #[Groups(['organizacion:read', 'organizacion:item:read', 'organizacion:write'])]
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $email = null;

    // ─────────────────────────────────────────────────────────────────────────
    // CARA PÚBLICA — la bandera gobierna, el título sólo aporta el texto
    //
    // Antes no había bandera: la regla era «sin título, el cliente no lo ve», y
    // se documentó como una ventaja (un booleano menos que mantener). No lo era.
    // Deducir la visibilidad de la presencia de un dato hace que ocultar sea
    // DESTRUCTIVO —el snapshot del título es la única copia, así que borrarlo
    // para esconder al prestador pierde el texto y obliga a reescribirlo para
    // volver a mostrarlo— y deja el 95% del catálogo (93 de 98 organizaciones)
    // invisible por omisión, sin que nadie lo haya decidido.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ¿Se puede nombrar a esta organización delante del cliente?
     *
     * ⚠️ Es un DEFAULT que se lee al ASIGNAR, no un veto vivo. Quien decide en
     * cada propuesta es la bandera del snapshot; ésta sólo la siembra. Si se
     * consultara al serializar, editar el catálogo cambiaría en silencio lo que
     * ve un cliente con la propuesta ya abierta — que es justo el defecto que
     * esta bandera viene a cerrar, y va contra la regla de que la cotización es
     * una foto del catálogo (docs/Travel.md §9).
     *
     * Arranca en `false` a propósito, invirtiendo el criterio de «lista vacía =
     * sin acotar» que rige en guía y conocimiento. Allí el olvido deja un ítem
     * de más, que es inofensivo; aquí nombrar a una organización que no tocaba
     * invita al cliente a saltarse la intermediación y contratar directo. El
     * olvido caro es el contrario, así que se entra por opt-in.
     */
    #[Groups(['organizacion:read', 'organizacion:item:read', 'organizacion:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $visibleParaCliente = false;

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['organizacion:read', 'organizacion:item:read', 'organizacion:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['organizacion:read', 'organizacion:item:read', 'organizacion:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $descripcion = [];

    #[Groups(['organizacion:read', 'organizacion:item:read', 'organizacion:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $url = null;

    #[Groups(['organizacion:read', 'organizacion:item:read', 'organizacion:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $direccion = null;

    /**
     * @var Collection<int, TravelOrganizacionImagen>
     */
    #[Groups(['organizacion:read', 'organizacion:item:read'])]
    #[ORM\OneToMany(
        mappedBy: 'organizacion',
        targetEntity: TravelOrganizacionImagen::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $imagenes;

    /**
     * @var Collection<int, TravelOrganizacionServicio>
     */
    #[Groups(['organizacion:item:read'])]
    #[ORM\OneToMany(
        mappedBy: 'organizacion',
        targetEntity: TravelOrganizacionServicio::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $servicios;

    /**
     * Lugares donde opera. Lado DUEÑO.
     *
     * Es COBERTURA, no ubicación: un operador de Lima que también despacha Ica lleva las
     * dos. Por eso es múltiple aunque el componente suela tener una sola — ahí la etiqueta
     * dice dónde ocurre ese servicio concreto, y aquí dice hasta dónde llega el organización.
     *
     * Va en `organización:read` (la colección) además del item: el listado del catálogo pinta
     * las etiquetas en cada ficha, y con `readableLink: false` viajan como IRIs.
     *
     * @var Collection<int, TravelLugar>
     */
    #[Groups(['organizacion:read', 'organizacion:item:read', 'organizacion:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToMany(targetEntity: TravelLugar::class, inversedBy: 'organizaciones')]
    #[ORM\JoinTable(name: 'travel_organizacion_lugar_pool')]
    #[ORM\JoinColumn(name: 'organizacion_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'lugar_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['orden' => 'ASC', 'nombre' => 'ASC'])]
    private Collection $lugares;

    /**
     * Constructor de la entidad TravelOrganizacion.
     * Inicializa el identificador único UUIDv7 y las colecciones internas.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->imagenes = new ArrayCollection();
        $this->servicios = new ArrayCollection();
        $this->lugares = new ArrayCollection();
    }

    /**
     * El id, además del `@id`. `IdTrait` no lo expone a la API, así que sin este override
     * el listado sólo traía la IRI y el front tenía que parsearla — con el resultado de que
     * `p.id` llegaba `undefined`, las URLs salían como `/organizaciones/undefined` y todas las
     * fichas se marcaban como seleccionadas a la vez. Mismo criterio que TravelComponente.
     */
    #[Groups(['organizacion:read', 'organizacion:item:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /**
     * Representación textual legible de la entidad para EasyAdmin y opciones en selects.
     *
     * @return string Retorna el nombre comercial del prestador o un marcador genérico.
     */
    public function __toString(): string
    {
        return $this->nombreComercial ?? 'TravelOrganizacion sin nombre';
    }

    /**
     * Obtiene el nombre comercial de la organización.
     */
    public function getNombreComercial(): ?string
    {
        return $this->nombreComercial;
    }

    /**
     * Establece el nombre comercial de la organización.
     */
    public function setNombreComercial(string $nombreComercial): self
    {
        $this->nombreComercial = $nombreComercial;
        return $this;
    }

    /**
     * Obtiene la razón social legal de la organización.
     */
    public function getRazonSocial(): ?string
    {
        return $this->razonSocial;
    }

    /**
     * Establece la razón social legal de la organización.
     */
    public function setRazonSocial(?string $razonSocial): self
    {
        $this->razonSocial = $razonSocial;
        return $this;
    }

    /**
     * Obtiene el número de teléfono principal de la organización.
     */
    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    /**
     * Establece el número de teléfono principal de la organización.
     */
    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono;
        return $this;
    }

    /**
     * Obtiene el correo electrónico comercial de la organización.
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Establece el correo electrónico comercial de la organización.
     */
    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    /**
     * ¿Se puede nombrar a esta organización delante del cliente? Ver la propiedad:
     * es la semilla que se copia al asignar, no un veto que se relea después.
     */
    public function isVisibleParaCliente(): bool
    {
        return $this->visibleParaCliente;
    }

    public function setVisibleParaCliente(bool $visibleParaCliente): self
    {
        $this->visibleParaCliente = $visibleParaCliente;
        return $this;
    }

    /**
     * ¿Está en condiciones de mostrarse? Bandera puesta Y texto que enseñar.
     *
     * El título dejó de ser la bandera, pero sigue siendo el contenido: marcar
     * visible a una organización sin título no da nada que pintar. Se comprueban las
     * dos cosas juntas para que el editor pueda avisar del hueco en vez de
     * mostrar una tarjeta vacía.
     */
    public function puedeMostrarseAlCliente(): bool
    {
        return $this->visibleParaCliente && $this->titulo !== [];
    }

    /**
     * Obtiene el título estructurado en formato JSON.
     * Diseñado para almacenar traducciones dinámicas.
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
     * @param list<array{language?: string, content?: string|null}> $titulo
     */
    public function setTitulo(array $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    /**
     * Obtiene la descripción estructurada en formato JSON.
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
     * @param list<array{language?: string, content?: string|null}> $descripcion
     */
    public function setDescripcion(array $descripcion): self
    {
        $this->descripcion = $descripcion;
        return $this;
    }

    /**
     * Obtiene la URL de texto externa asociada al prestador.
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * Establece la URL de texto externa asociada al prestador.
     */
    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Obtiene la direccion de la organización.
     */
    public function getDireccion(): ?string
    {
        return $this->direccion;
    }

    /**
     * Establece la direccion de la organización.
     */
    public function setDireccion(?string $direccion): self
    {
        $this->direccion = $direccion;
        return $this;
    }

    /**
     * Obtiene la colección completa de imágenes pertenecientes a la galería de la organización.
     *
     * @return Collection<int, TravelOrganizacionImagen>
     *
     * @return Collection<int, TravelOrganizacionImagen>
     */
    public function getImagenes(): Collection
    {
        return $this->imagenes;
    }

    public function addImagen(TravelOrganizacionImagen $imagen): self
    {
        if (!$this->imagenes->contains($imagen)) {
            $this->imagenes->add($imagen);
            $imagen->setOrganizacion($this);
        }
        return $this;
    }

    public function removeImagen(TravelOrganizacionImagen $imagen): self
    {
        if ($this->imagenes->removeElement($imagen)) {
            if ($imagen->getOrganizacion() === $this) {
                $imagen->setOrganizacion(null);
            }
        }
        return $this;
    }

    /**
     * Obtiene la colección completa de servicios pertenecientes al prestador.
     *
     * @return Collection<int, TravelOrganizacionServicio>
     *
     * @return Collection<int, TravelOrganizacionServicio>
     */
    public function getServicios(): Collection
    {
        return $this->servicios;
    }

    /**
     * Doctrine no llama a este método al hidratar (usa reflexión directa sobre la
     * colección), pero el formulario de EasyAdmin sí: el `CollectionField::new('servicios', …)`
     * del CRUD de organización necesita que el `PropertyAccessor` encuentre un
     * `addServicio()`/`removeServicio()` para la propiedad `servicios` — busca el singular de
     * el nombre de la propiedad, no el de la entidad. Ver docs/Travel.md.
     */
    public function addServicio(TravelOrganizacionServicio $servicio): self
    {
        if (!$this->servicios->contains($servicio)) {
            $this->servicios->add($servicio);
            $servicio->setOrganizacion($this);
        }
        return $this;
    }

    public function removeServicio(TravelOrganizacionServicio $servicio): self
    {
        if ($this->servicios->removeElement($servicio)) {
            if ($servicio->getOrganizacion() === $this) {
                $servicio->setOrganizacion(null);
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
    #[Groups(['organizacion:read'])]
    public function getOrganizacionId(): ?string
    {
        return $this->getId() ? (string) $this->getId() : null;
    }

    /**
     * Expone la representación visual amigable de la entidad para inyectarse en un TomSelect o componente de Vue.
     */
    #[Groups(['organizacion:read'])]
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
     *
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
