<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Patch;
use App\Api\Provider\Cotizacion\CotizacionCatalogoAdminProvider;
use App\Api\Provider\Cotizacion\CotizacionCatalogoPublicProvider;
use App\Attribute\AutoTranslate;
use App\Cotizacion\Enum\CatalogoTipoClienteEnum;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\LocatorTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Catálogo de Tours. Agrupa propuestas comerciales pre-armadas (tours simples
 * o paquetes multi-día) dirigidas a un segmento de cliente (lujo, económico).
 *
 * Es el espejo de CotizacionFile para venta por catálogo: cada tour es una
 * Cotizacion colgada del catálogo (en vez de un expediente), sin fechas
 * reales (fecha base nominal) y con precio de exhibición "Desde X".
 *
 * Vista pública (por localizador) en dos niveles:
 *   - pax_catalogo:read → PORTADA: datos del catálogo + cards de tours.
 *   - pax_cotizacion:read → DETALLE: agrega la cotización completa de UN tour.
 */
#[ApiResource(
    shortName: 'CotizacionCatalogo',
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['catalogo:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        ),
        // Detalle interno (panel CatalogoDashboard.vue + editor de tours).
        // El provider añade a cada tour su portada derivada y sus días.
        new Get(
            normalizationContext: ['groups' => ['catalogo:read', 'catalogo:item:read', 'file:item:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
            provider: CotizacionCatalogoAdminProvider::class,
        ),
        // PORTADA pública: Catálogo + cards de tours (liviano)
        new Get(
            uriTemplate: '/client/cotizacion/cotizacion_catalogo/{localizador}',
            uriVariables: [
                'localizador' => new Link(fromClass: CotizacionCatalogo::class, identifiers: ['localizador']),
            ],
            normalizationContext: ['groups' => ['pax_catalogo:read']],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: CotizacionCatalogoPublicProvider::class,
        ),
        // DETALLE público: Catálogo + cotización completa de un tour
        new Get(
            uriTemplate: '/client/cotizacion/cotizacion_catalogo/{localizador}/{propuesta}',
            uriVariables: [
                'localizador' => new Link(fromClass: CotizacionCatalogo::class, identifiers: ['localizador']),
                'propuesta'     => new Link(fromClass: CotizacionCatalogo::class, identifiers: ['propuesta']),
            ],
            normalizationContext: ['groups' => ['pax_catalogo:read', 'pax_cotizacion:read']],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: CotizacionCatalogoPublicProvider::class,
        ),
        new Post(
            normalizationContext: ['groups' => ['catalogo:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['catalogo:write']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear catálogos.'
        ),
        new Put(
            normalizationContext: ['groups' => ['catalogo:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['catalogo:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar catálogos.'
        ),
        new Patch(
            normalizationContext: ['groups' => ['catalogo:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['catalogo:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para actualizar parcialmente catálogos.'
        ),
        // Las escrituras y el Delete, con los grupos de lectura (07/10/2026): sin ellos API
        // Platform documentaba su salida con TODAS las propiedades —virtuales de pax y flags del
        // trait de traducción incluidos— en un esquema «CotizacionCatalogo» que no describe nada.
        new Delete(
            normalizationContext: ['groups' => ['catalogo:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar catálogos.'
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_catalogo')]
#[ORM\Index(columns: ['created_at'], name: 'idx_cotizacion_catalogo_created_at')]
#[ORM\UniqueConstraint(name: 'uniq_cotizacion_catalogo_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'Ya hay otro catálogo con esa dirección web.')]
class CotizacionCatalogo
{
    /**
     * El día 1 de todo tour de catálogo. Las fechas de un tour son NOMINALES: sólo importa la
     * distancia entre ellas (el cliente lee «Día N»), así que todas cuelgan de un ancla fija y
     * lejana que no se confunda con una salida real.
     *
     * ⚠️ **Espejo en TypeScript**: `FECHA_BASE_NOMINAL` de
     * `util/src/stores/cotizacion/cotizacionEditorStore.ts` (servicios nuevos en el editor). Si
     * cambia, cambian los dos. Aquí la usa `CloneCotizacionProcessor` al copiar a un catálogo.
     */
    public const FECHA_BASE_NOMINAL = '2030-01-05';

    use IdTrait;
    use TimestampTrait;
    use LocatorTrait;
    use AutoTranslateControlTrait;

    #[Groups(['catalogo:read', 'catalogo:item:read', 'catalogo:write', 'pax_catalogo:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombre = null;

    #[Groups(['catalogo:read', 'catalogo:item:read', 'catalogo:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: CatalogoTipoClienteEnum::class, options: ['default' => 'economico'])]
    private CatalogoTipoClienteEnum $tipoCliente = CatalogoTipoClienteEnum::ECONOMICO;

    #[Groups(['catalogo:read', 'catalogo:item:read', 'catalogo:write', 'pax_catalogo:read'])]
    #[ORM\Column(type: 'string', length: 5, options: ['default' => 'es'])]
    private string $idiomaCliente = 'es';

    /** Un catálogo inactivo deja de ser visible en la vista pública. */
    #[Groups(['catalogo:read', 'catalogo:item:read', 'catalogo:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /** Orden de exhibición del catálogo en el listado. */
    #[Groups(['catalogo:read', 'catalogo:item:read', 'catalogo:write'])]
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orden = 0;

    // ══════════════════════════════════════════════════════════════════════
    // WEB PÚBLICA (openperu.pe) — ver docs/WebPublica.md
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Sale en la web pública. **No es `activo`**: `activo` deja vivo el enlace privado por
     * localizador; esto lo LISTA en openperu.pe. Un catálogo para una agencia se manda por
     * enlace y no debe aparecer en la web, así que son dos interruptores a propósito.
     */
    #[Groups(['catalogo:read', 'catalogo:item:read', 'catalogo:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $publicadoWeb = false;

    /**
     * Tramo de la URL pública: `/tours/{slug}`. Nulo mientras no se publique.
     *
     * ⚠️ Cambiarlo rompe los enlaces ya compartidos del catálogo (los de los tours no: esos se
     * identifican por número de propuesta y redirigen a su forma canónica).
     */
    #[Groups(['catalogo:read', 'catalogo:item:read', 'catalogo:write'])]
    #[Assert\Length(max: 80)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Sólo minúsculas, números y guiones (ej.: ofertas-cusco).')]
    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $slug = null;

    /**
     * Título que ve el público. El `nombre` es interno («Oferta Cusco económico 2026»); si éste
     * falta, la web cae al nombre, que es mejor que una sección sin título.
     *
     * @var list<array{language?: string, content?: string|null}>
     */
    #[Groups(['catalogo:read', 'catalogo:item:read', 'catalogo:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $tituloWeb = [];

    /**
     * Entradilla de la sección del catálogo en la web (HTML corto).
     *
     * @var list<array{language?: string, content?: string|null}>
     */
    #[Groups(['catalogo:read', 'catalogo:item:read', 'catalogo:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $descripcionWeb = [];

    /**
     * @var Collection<int, Cotizacion>
     * EXTRA_LAZY: la vista pública nunca hidrata esta colección (el provider
     * usa queries escalares); el editor la usa con catalogo:item:read.
     */
    #[ApiProperty(fetchEager: false)]
    #[Groups(['catalogo:item:read'])]
    #[ORM\OneToMany(mappedBy: 'catalogo', targetEntity: Cotizacion::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['propuesta' => 'DESC'])]
    private Collection $cotizaciones;

    // ══════════════════════════════════════════════════════════════════════
    // PROPIEDADES VIRTUALES DE LA VISTA PÚBLICA (no persistidas)
    // Las llena CotizacionCatalogoPublicProvider; la entity no hace queries.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Cards livianas de los tours públicos vigentes (portada del catálogo).
     * Calculadas por el provider con un query escalar (no hidrata entidades).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $toursParaCliente = [];

    /** Cotización completa del tour solicitado en la URL (solo detalle). */
    private ?Cotizacion $cotizacionParaCliente = null;

    /**
     * Las puertas que la sesión del operador se saltó en ESTA petición. Vacío para el cliente.
     *
     * 🔥 **El catálogo también deja pasar, y no lo decía.** `CotizacionCatalogoPublicProvider`
     * permite previsualizar un tour antes de publicarlo —a propósito, es útil— pero en pantalla
     * no había ninguna diferencia entre un tour vivo y uno que el cliente no puede abrir. Es el
     * mismo falso fallo que ya costó una tarde en el expediente: el operador ve algo, deduce que
     * el cliente también, y manda el enlace.
     *
     * Gemelo de {@see CotizacionFile::$saltosDeOperador}, y lo lee el mismo cartel
     * (`AvisoVistaDeOperador`). Aquí sólo puede haber una: `sin_publicar`. La identificación y el
     * filtrado por persona son de la operativa de un grupo; un catálogo no tiene ni pasajeros.
     *
     * @var list<string>
     */
    #[ApiProperty(openapiContext: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups(['pax_catalogo:read'])]
    private array $saltosDeOperador = [];

    public function __construct()
    {
        $this->initializeId();
        $this->initializeLocator();
        $this->cotizaciones = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombre ?? 'Catálogo sin nombre';
    }

    /* ======================================================
     * VISTA PÚBLICA (pax)
     * ====================================================== */

    #[Groups(['catalogo:read', 'catalogo:item:read', 'pax_catalogo:read'])]
    #[SerializedName('localizador')]
    public function getLocalizadorPublico(): ?string
    {
        // Se mapea con la propiedad $this->localizador del Trait
        return $this->localizador;
    }

    /** @param list<string> $saltos */
    public function setSaltosDeOperador(array $saltos): self
    {
        $this->saltosDeOperador = $saltos;
        return $this;
    }

    /** @return list<string> */
    public function getSaltosDeOperador(): array
    {
        return $this->saltosDeOperador;
    }

    /**
     * @param list<array<string, mixed>> $tours
     */
    public function setToursParaCliente(array $tours): self
    {
        $this->toursParaCliente = $tours;
        return $this;
    }

    /**
     * Cards de tours para la portada: resumen comercial i18n, precio "Desde",
     * número de días y pax base. Puede haber varios tours activos simultáneos.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Groups(['pax_catalogo:read'])]
    public function getToursParaCliente(): array
    {
        return $this->toursParaCliente;
    }

    public function setCotizacionParaCliente(?Cotizacion $cotizacion): self
    {
        $this->cotizacionParaCliente = $cotizacion;
        return $this;
    }

    /**
     * Cotización completa del tour expuesta al cliente. Solo la llena el
     * provider en la operación de detalle; en portada es null.
     */
    #[Groups(['pax_cotizacion:read'])]
    public function getCotizacionParaCliente(): ?Cotizacion
    {
        return $this->cotizacionParaCliente;
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getTipoCliente(): CatalogoTipoClienteEnum { return $this->tipoCliente; }
    public function setTipoCliente(CatalogoTipoClienteEnum $tipoCliente): self { $this->tipoCliente = $tipoCliente; return $this; }

    public function getIdiomaCliente(): string { return $this->idiomaCliente; }
    public function setIdiomaCliente(string $idiomaCliente): self { $this->idiomaCliente = $idiomaCliente; return $this; }

    /**
     * Publicar en la web exige una dirección. Sin `slug` el catálogo no tendría URL y la portada
     * enlazaría a ninguna parte; mejor que el guardado lo diga que una tarjeta muerta.
     */
    #[Assert\Callback]
    public function validarPublicacionWeb(ExecutionContextInterface $context): void
    {
        if ($this->publicadoWeb && ($this->slug === null || $this->slug === '')) {
            $context->buildViolation('Para publicar el catálogo en la web hace falta su dirección (slug).')
                ->atPath('slug')
                ->addViolation();
        }
    }

    public function isPublicadoWeb(): bool { return $this->publicadoWeb; }
    public function setPublicadoWeb(bool $publicadoWeb): self { $this->publicadoWeb = $publicadoWeb; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $slug): self
    {
        // Vacío desde el formulario = sin dirección; un '' único chocaría con el siguiente vacío.
        $slug = $slug !== null ? trim($slug) : null;
        $this->slug = $slug === '' ? null : $slug;
        return $this;
    }

    /** @return list<array{language?: string, content?: string|null}> */
    public function getTituloWeb(): array { return $this->tituloWeb; }
    /** @param list<array{language?: string, content?: string|null}> $tituloWeb */
    public function setTituloWeb(array $tituloWeb): self { $this->tituloWeb = $tituloWeb; return $this; }

    /** @return list<array{language?: string, content?: string|null}> */
    public function getDescripcionWeb(): array { return $this->descripcionWeb; }
    /** @param list<array{language?: string, content?: string|null}> $descripcionWeb */
    public function setDescripcionWeb(array $descripcionWeb): self { $this->descripcionWeb = $descripcionWeb; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    /**
     * @return Collection<int, Cotizacion>
     */
    public function getCotizaciones(): Collection { return $this->cotizaciones; }
    public function addCotizacion(Cotizacion $cotizacion): self
    {
        if (!$this->cotizaciones->contains($cotizacion)) {
            $this->cotizaciones->add($cotizacion);
            $cotizacion->setCatalogo($this);
        }
        return $this;
    }
    public function removeCotizacion(Cotizacion $cotizacion): self
    {
        if ($this->cotizaciones->removeElement($cotizacion)) {
            if ($cotizacion->getCatalogo() === $this) { $cotizacion->setCatalogo(null); }
        }
        return $this;
    }
}
