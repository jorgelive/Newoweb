<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\AutoTranslate;
use App\Cotizacion\Enum\EstadoOperativo;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ApiResource(operations: [new Get()], routePrefix: '/sales')]
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

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $nombreSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombreParaProveedorSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $cantidad = 1;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $montoCosto = '0.00';

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 10)]
    private string $moneda = 'USD';

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $tarifaMaestraId = null;

    /**
     * SOFT-LINK: Guarda el UUID del proveedor del catálogo maestro.
     * Permite al frontend consultar la galería de fotos en tiempo real si el ID aún existe.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $proveedorMaestroId = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $proveedorNombreSnapshot = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true, enumType: EstadoOperativo::class)]
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    private ?EstadoOperativo $estadoOperativoSnapshot = EstadoOperativo::SIN_SOLICITAR;

    #[ORM\Column(type: 'date', nullable: true)]
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    private ?\DateTimeInterface $fechaLimitePago = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    private ?string $condicionesPagoSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $tipoModalidadSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $esGrupal = false;


    public function __construct()
    {
        $this->initializeId();
    }

    #[Groups(['cotizacion:read', 'cotizacion:item:read'])]
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

    public function getNombreSnapshot(): array { return $this->nombreSnapshot; }
    public function setNombreSnapshot(array $nombreSnapshot): self { $this->nombreSnapshot = $nombreSnapshot; return $this; }

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

    public function getMoneda(): string { return $this->moneda; }
    public function setMoneda(string $moneda): self { $this->moneda = $moneda; return $this; }

    public function getTarifaMaestraId(): ?string { return $this->tarifaMaestraId; }
    public function setTarifaMaestraId(?string $tarifaMaestraId): self { $this->tarifaMaestraId = $tarifaMaestraId; return $this; }

    public function getProveedorMaestroId(): ?string { return $this->proveedorMaestroId; }
    public function setProveedorMaestroId(?string $proveedorMaestroId): self { $this->proveedorMaestroId = $proveedorMaestroId; return $this; }

    public function getProveedorNombreSnapshot(): ?string { return $this->proveedorNombreSnapshot; }
    public function setProveedorNombreSnapshot(?string $proveedorNombreSnapshot): self { $this->proveedorNombreSnapshot = $proveedorNombreSnapshot; return $this; }

    /**
     * Obtiene el estado operativo actual de la tarifa basado en el Enum estricto.
     *
     * Este método existe para devolver una instancia del Enum EstadoOperativo,
     * garantizando que el sistema y el serializador siempre manejen un estado válido y
     * predecible.
     *
     * @return EstadoOperativo|null El estado de la operación.
     */
    public function getEstadoOperativoSnapshot(): ?EstadoOperativo
    {
        return $this->estadoOperativoSnapshot;
    }

    /**
     * Establece el estado operativo estricto de la tarifa.
     *
     * Este método existe para asegurar que solo se puedan guardar estados
     * definidos en el Enum EstadoOperativo. API Platform se encarga de
     * deserializar automáticamente el string entrante (ej. 'Confirmado') hacia
     * su instancia Enum correspondiente de forma transparente.
     *
     * @param EstadoOperativo|null $estadoOperativoSnapshot Instancia del Enum de estado.
     * @return static
     */
    public function setEstadoOperativoSnapshot(?EstadoOperativo $estadoOperativoSnapshot): static
    {
        $this->estadoOperativoSnapshot = $estadoOperativoSnapshot;

        return $this;
    }

    /**
     * Obtiene la fecha límite exacta para reportes del sistema.
     *
     * Este método existe para permitir la filtración en base de datos y la
     * generación de alertas automáticas (cronjobs) cuando se acerca la fecha
     * de pago a un proveedor logístico.
     *
     * @return \DateTimeInterface|null
     */
    public function getFechaLimitePago(): ?\DateTimeInterface
    {
        return $this->fechaLimitePago;
    }

    /**
     * Establece la fecha límite exacta de pago.
     * * Este método existe para registrar el deadline estricto impuesto por el proveedor.
     * API Platform convierte automáticamente el string 'YYYY-MM-DD' enviado por el frontend
     * en un objeto DateTime de PHP compatible con Doctrine.
     *
     * @param \DateTimeInterface|null $fechaLimitePago
     * @return static
     */
    public function setFechaLimitePago(?\DateTimeInterface $fechaLimitePago): static
    {
        $this->fechaLimitePago = $fechaLimitePago;

        return $this;
    }

    /**
     * Obtiene las condiciones o notas de pago del proveedor.
     *
     * Este método existe para proveer al equipo operativo contexto en texto libre
     * sobre cómo ejecutar el pago (ej. cuentas bancarias, consideraciones especiales).
     *
     * @return string|null
     */
    public function getCondicionesPagoSnapshot(): ?string
    {
        return $this->condicionesPagoSnapshot;
    }

    /**
     * Establece las condiciones o notas de pago del proveedor.
     *
     * Este método existe para almacenar las instrucciones humanas y directrices
     * de pago que acompañan a la fecha límite operativa.
     *
     * @param string|null $condicionesPagoSnapshot
     * @return static
     */
    public function setCondicionesPagoSnapshot(?string $condicionesPagoSnapshot): static
    {
        $this->condicionesPagoSnapshot = $condicionesPagoSnapshot;

        return $this;
    }

    public function getTipoModalidadSnapshot(): ?string { return $this->tipoModalidadSnapshot; }
    public function setTipoModalidadSnapshot(?string $tipoModalidadSnapshot): self { $this->tipoModalidadSnapshot = $tipoModalidadSnapshot; return $this; }

    public function isEsGrupal(): bool { return $this->esGrupal; }
    public function setEsGrupal(bool $esGrupal): self { $this->esGrupal = $esGrupal; return $this; }

}