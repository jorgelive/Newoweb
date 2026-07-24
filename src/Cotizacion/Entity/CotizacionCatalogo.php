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
use App\Api\Provider\Cotizacion\CotizacionCatalogoPublicProvider;
use App\Cotizacion\Enum\CatalogoTipoClienteEnum;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\LocatorTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

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
        new Get(
            normalizationContext: ['groups' => ['catalogo:read', 'catalogo:item:read', 'file:item:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
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
            uriTemplate: '/client/cotizacion/cotizacion_catalogo/{localizador}/{version}',
            uriVariables: [
                'localizador' => new Link(fromClass: CotizacionCatalogo::class, identifiers: ['localizador']),
                'version'     => new Link(fromClass: CotizacionCatalogo::class, identifiers: ['version']),
            ],
            normalizationContext: ['groups' => ['pax_catalogo:read', 'pax_cotizacion:read']],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: CotizacionCatalogoPublicProvider::class,
        ),
        new Post(
            denormalizationContext: ['groups' => ['catalogo:write']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear catálogos.'
        ),
        new Put(
            denormalizationContext: ['groups' => ['catalogo:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar catálogos.'
        ),
        new Patch(
            denormalizationContext: ['groups' => ['catalogo:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para actualizar parcialmente catálogos.'
        ),
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar catálogos.'
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_catalogo')]
#[ORM\Index(columns: ['created_at'], name: 'idx_cotizacion_catalogo_created_at')]
#[ORM\HasLifecycleCallbacks]
class CotizacionCatalogo
{
    use IdTrait;
    use TimestampTrait;
    use LocatorTrait;

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

    /**
     * @var Collection<int, Cotizacion>
     * EXTRA_LAZY: la vista pública nunca hidrata esta colección (el provider
     * usa queries escalares); el editor la usa con catalogo:item:read.
     */
    #[ApiProperty(fetchEager: false)]
    #[Groups(['catalogo:item:read'])]
    #[ORM\OneToMany(mappedBy: 'catalogo', targetEntity: Cotizacion::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['version' => 'DESC'])]
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

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

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
