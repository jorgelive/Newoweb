<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_cottarifa')]
#[ORM\HasLifecycleCallbacks]
class CotizacionCottarifa
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionCotcomponente::class, inversedBy: 'cottarifas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotcomponente $cotcomponente = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombreParaProveedorSnapshot = null;

    // ─────────────────────────────────────────────────────────────────────────
    // LOS TRES PAPELES, CONGELADOS
    //
    // Se copian de {@see \App\Travel\Entity\TravelTarifa} al colgar la tarifa. Se congelan
    // por lo mismo que el resto de este snapshot: una cotización es un documento HISTÓRICO, y
    // lo que se le prometió al cliente no puede cambiar porque alguien edite el maestro
    // después. El soft-link sobrevive al borrado; el nombre, a que la empresa se renombre.
    //
    // De aquí sube la regla de {@see \App\Cotizacion\Tarifa\PapelDeLaTarifa} al
    // `CotizacionCotcomponente`: **el primero manda, el distinto avisa y pasa**.
    //
    // Mismos nombres que en el cotcomponente a propósito: la regla copia de una entidad a la
    // otra, y que los campos se llamen igual es lo que hace obvio de dónde viene cada cosa.
    // ─────────────────────────────────────────────────────────────────────────

    /** Quién PRESTA. SOFT-LINK a `App\Travel\Entity\TravelOrganizacion`. */
    #[Groups(['cotizacion:item:read', 'cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorMaestroId = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $prestadorNombreSnapshot = null;

    /**
     * El servicio concreto contratado (ej. el tipo de habitación). SOFT-LINK.
     *
     * Se congela con los otros dos y por el mismo motivo: es parte de lo que se prometió.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorServicioMaestroId = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $prestadorServicioNombreSnapshot = null;

    /**
     * A quién se le encarga la COMPRA. Vacío = se le compra directo al prestador.
     *
     * ⚠️ Sin `pax_cotizacion:read`, igual que en el cotcomponente: a quién le encargaste la
     * compra no es asunto del cliente.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $compradorMaestroId = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $compradorNombreSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $cantidad = 1;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $montoCosto = '0.00';

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $moneda = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $tarifaMaestraId = null;

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $tituloSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombreInternoSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $modalidadSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $categoriaSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $procedenciaSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $edadMinimaSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $edadMaximaSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacidadMinimaSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacidadMaximaSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $esGrupal = false;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $rolSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $grupoTarifa = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $comisionOverrideSnapshot = null;

    /** @var list<array{language?: string, content?: string|null}>|null */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $notaRol = [];

    public function __construct()
    {
        $this->initializeId();
    }

    /**
     * Clona la tarifa reseteando su UUID para evitar colisiones.
     *
     * @return self
     */
    public function duplicar(): self
    {
        $copia = clone $this;
        $copia->resetId();

        return $copia;
    }

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'pax_cotizacion:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['cotizacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    // --- MÉTODOS SOBRESCRITOS PARA EXPONER EL FLAG A API PLATFORM ---
    #[Groups(['cotizacion:write', 'cotizacion:read'])]
    public function getSobreescribirTraduccion(): bool
    {
        return $this->sobreescribirTraduccion;
    }

    #[Groups(['cotizacion:write'])]
    public function setSobreescribirTraduccion(bool $sobreescribirTraduccion): self
    {
        $this->sobreescribirTraduccion = $sobreescribirTraduccion;
        return $this;
    }

    public function getCotcomponente(): ?CotizacionCotcomponente { return $this->cotcomponente; }
    public function setCotcomponente(?CotizacionCotcomponente $cotcomponente): self { $this->cotcomponente = $cotcomponente; return $this; }

    /**
     * Obtiene el título comercial multidioma de la tarifa.
     *
     * @return array
     *
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getTituloSnapshot(): array { return $this->tituloSnapshot; }

    /**
     * Establece el título comercial multidioma de la tarifa.
     *
     * @param array $tituloSnapshot
     * @return self
     *
     * @param list<array{language?: string, content?: string|null}> $tituloSnapshot
     */
    public function setTituloSnapshot(array $tituloSnapshot): self { $this->tituloSnapshot = $tituloSnapshot; return $this; }

    /**
     * Obtiene el nombre interno operativo de la tarifa.
     *
     * @return string|null
     */
    public function getNombreInternoSnapshot(): ?string { return $this->nombreInternoSnapshot; }

    /**
     * Establece el nombre interno operativo de la tarifa.
     *
     * @param string|null $nombreInternoSnapshot
     * @return self
     */
    public function setNombreInternoSnapshot(?string $nombreInternoSnapshot): self { $this->nombreInternoSnapshot = $nombreInternoSnapshot; return $this; }

    /**
     * Obtiene el nombre exclusivo para el requerimiento al proveedor.
     *
     * @return string|null
     */
    public function getNombreParaProveedorSnapshot(): ?string
    {
        return $this->nombreParaProveedorSnapshot;
    }

    /**
     * Establece el nombre exclusivo para el requerimiento al proveedor.
     *
     * @param string|null $nombreParaProveedorSnapshot
     * @return self
     */
    public function setNombreParaProveedorSnapshot(?string $nombreParaProveedorSnapshot): self
    {
        $this->nombreParaProveedorSnapshot = $nombreParaProveedorSnapshot;
        return $this;
    }

    public function getCantidad(): int { return $this->cantidad; }
    public function setCantidad(int $cantidad): self { $this->cantidad = $cantidad; return $this; }

    public function getMontoCosto(): string { return $this->montoCosto; }
    public function setMontoCosto(string $montoCosto): self { $this->montoCosto = $montoCosto; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    public function getTarifaMaestraId(): ?string { return $this->tarifaMaestraId; }
    public function setTarifaMaestraId(?string $tarifaMaestraId): self { $this->tarifaMaestraId = $tarifaMaestraId; return $this; }

    public function getModalidadSnapshot(): ?string { return $this->modalidadSnapshot; }
    public function setModalidadSnapshot(?string $modalidadSnapshot): self { $this->modalidadSnapshot = $modalidadSnapshot; return $this; }

    public function getProcedenciaSnapshot(): ?string { return $this->procedenciaSnapshot; }
    public function setProcedenciaSnapshot(?string $procedenciaSnapshot): self { $this->procedenciaSnapshot = $procedenciaSnapshot; return $this; }

    public function getCategoriaSnapshot(): ?string { return $this->categoriaSnapshot; }
    public function setCategoriaSnapshot(?string $categoriaSnapshot): self { $this->categoriaSnapshot = $categoriaSnapshot; return $this; }

    public function getEdadMinimaSnapshot(): ?int { return $this->edadMinimaSnapshot; }
    public function setEdadMinimaSnapshot(?int $edadMinimaSnapshot): self { $this->edadMinimaSnapshot = $edadMinimaSnapshot; return $this; }

    public function getEdadMaximaSnapshot(): ?int { return $this->edadMaximaSnapshot; }
    public function setEdadMaximaSnapshot(?int $edadMaximaSnapshot): self { $this->edadMaximaSnapshot = $edadMaximaSnapshot; return $this; }

    public function getCapacidadMinimaSnapshot(): ?int { return $this->capacidadMinimaSnapshot; }
    public function setCapacidadMinimaSnapshot(?int $capacidadMinimaSnapshot): self { $this->capacidadMinimaSnapshot = $capacidadMinimaSnapshot; return $this; }

    public function getCapacidadMaximaSnapshot(): ?int { return $this->capacidadMaximaSnapshot; }
    public function setCapacidadMaximaSnapshot(?int $capacidadMaximaSnapshot): self { $this->capacidadMaximaSnapshot = $capacidadMaximaSnapshot; return $this; }

    public function isEsGrupal(): bool { return $this->esGrupal; }
    public function setEsGrupal(bool $esGrupal): self { $this->esGrupal = $esGrupal; return $this; }

    public function getRolSnapshot(): ?string { return $this->rolSnapshot; }
    public function setRolSnapshot(?string $rolSnapshot): self { $this->rolSnapshot = $rolSnapshot; return $this; }

    public function getGrupoTarifa(): ?int { return $this->grupoTarifa; }
    public function setGrupoTarifa(?int $grupoTarifa): self { $this->grupoTarifa = $grupoTarifa; return $this; }

    public function getComisionOverrideSnapshot(): ?string { return $this->comisionOverrideSnapshot; }
    public function setComisionOverrideSnapshot(?string $comisionOverrideSnapshot): self { $this->comisionOverrideSnapshot = $comisionOverrideSnapshot; return $this; }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getNotaRol(): array
    {
        return $this->notaRol ?? [];
    }

    /**
     * @param list<array{language?: string, content?: string|null}>|null $notaRol
     */
    public function setNotaRol(?array $notaRol): self
    {
        $this->notaRol = $notaRol ?? [];
        return $this;
    }

    public function getPrestadorMaestroId(): ?string { return $this->prestadorMaestroId; }
    public function setPrestadorMaestroId(?string $v): self { $this->prestadorMaestroId = $v; return $this; }

    public function getPrestadorNombreSnapshot(): ?string { return $this->prestadorNombreSnapshot; }
    public function setPrestadorNombreSnapshot(?string $v): self { $this->prestadorNombreSnapshot = $v; return $this; }

    public function getPrestadorServicioMaestroId(): ?string { return $this->prestadorServicioMaestroId; }
    public function setPrestadorServicioMaestroId(?string $v): self { $this->prestadorServicioMaestroId = $v; return $this; }

    public function getPrestadorServicioNombreSnapshot(): ?string { return $this->prestadorServicioNombreSnapshot; }
    public function setPrestadorServicioNombreSnapshot(?string $v): self { $this->prestadorServicioNombreSnapshot = $v; return $this; }

    public function getCompradorMaestroId(): ?string { return $this->compradorMaestroId; }
    public function setCompradorMaestroId(?string $v): self { $this->compradorMaestroId = $v; return $this; }

    public function getCompradorNombreSnapshot(): ?string { return $this->compradorNombreSnapshot; }
    public function setCompradorNombreSnapshot(?string $v): self { $this->compradorNombreSnapshot = $v; return $this; }
}
