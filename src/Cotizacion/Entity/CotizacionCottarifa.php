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

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
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

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $proveedorNombreSnapshot = null;

    /**
     * Título público del proveedor (I18nContent[]), traducible.
     * Snapshot independiente del catálogo maestro — sobrevive aunque el Proveedor cambie o se borre.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $proveedorTituloSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $proveedorUrlSnapshot = null;

    /**
     * Galería de imágenes del proveedor (snapshot). Necesario porque el visor
     * público no tiene acceso al catálogo maestro para resolverlas en vivo.
     * Estructura: [{ imageUrl, orden, isPortada }, ...]
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'json')]
    private array $proveedorImagenesSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'json')]
    private array $proveedorServicioImagenesSnapshot = [];

    /**
     * SOFT-LINK: Guarda el UUID del ProveedorServicio del catálogo maestro (ej. tipo de habitación).
     * Permite al frontend resolver título/imágenes en tiempo real si el registro maestro aún existe.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $proveedorServicioMaestroId = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $tituloSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombreInternoSnapshot = null;

    /**
     * Título público del servicio del proveedor (I18nContent[]), traducible.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $proveedorServicioTituloSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $proveedorServicioUrlSnapshot = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true, enumType: EstadoOperativo::class)]
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    private ?EstadoOperativo $estadoOperativoSnapshot = EstadoOperativo::SIN_SOLICITAR;

    #[ORM\Column(type: 'date', nullable: true)]
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    private ?\DateTimeInterface $fechaLimitePago = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    private ?string $condicionesPagoSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $modalidadSnapshot = null;

// 👇 agregar esto
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

    // 🔥 NUEVO FLAG DE ANONIMATO INDIVIDUAL
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $proveedorOculto = false;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $rolSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $grupoTarifa = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $comisionOverrideSnapshot = null;

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
     */
    public function duplicar(): self
    {
        $copia = clone $this;   // clone superficial por defecto (sin __clone)
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
     */
    public function getTituloSnapshot(): array { return $this->tituloSnapshot; }
    public function setTituloSnapshot(array $tituloSnapshot): self { $this->tituloSnapshot = $tituloSnapshot; return $this; }

    /**
     * Obtiene el nombre interno operativo de la tarifa.
     */
    public function getNombreInternoSnapshot(): ?string { return $this->nombreInternoSnapshot; }
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

    public function getMoneda(): string { return $this->moneda; }
    public function setMoneda(string $moneda): self { $this->moneda = $moneda; return $this; }

    public function getTarifaMaestraId(): ?string { return $this->tarifaMaestraId; }
    public function setTarifaMaestraId(?string $tarifaMaestraId): self { $this->tarifaMaestraId = $tarifaMaestraId; return $this; }

    public function getProveedorMaestroId(): ?string { return $this->proveedorMaestroId; }
    public function setProveedorMaestroId(?string $proveedorMaestroId): self { $this->proveedorMaestroId = $proveedorMaestroId; return $this; }

    public function getProveedorNombreSnapshot(): ?string { return $this->proveedorNombreSnapshot; }
    public function setProveedorNombreSnapshot(?string $proveedorNombreSnapshot): self { $this->proveedorNombreSnapshot = $proveedorNombreSnapshot; return $this; }

    public function getProveedorTituloSnapshot(): array { return $this->proveedorTituloSnapshot; }
    public function setProveedorTituloSnapshot(array $proveedorTituloSnapshot): self { $this->proveedorTituloSnapshot = $proveedorTituloSnapshot; return $this; }

    public function getProveedorUrlSnapshot(): ?string { return $this->proveedorUrlSnapshot; }
    public function setProveedorUrlSnapshot(?string $proveedorUrlSnapshot): self { $this->proveedorUrlSnapshot = $proveedorUrlSnapshot; return $this; }

    public function getProveedorServicioMaestroId(): ?string { return $this->proveedorServicioMaestroId; }
    public function setProveedorServicioMaestroId(?string $proveedorServicioMaestroId): self { $this->proveedorServicioMaestroId = $proveedorServicioMaestroId; return $this; }

    public function getProveedorServicioNombreSnapshot(): ?string { return $this->proveedorServicioNombreSnapshot; }
    public function setProveedorServicioNombreSnapshot(?string $proveedorServicioNombreSnapshot): self { $this->proveedorServicioNombreSnapshot = $proveedorServicioNombreSnapshot; return $this; }

    public function getProveedorServicioTituloSnapshot(): array { return $this->proveedorServicioTituloSnapshot; }
    public function setProveedorServicioTituloSnapshot(array $proveedorServicioTituloSnapshot): self { $this->proveedorServicioTituloSnapshot = $proveedorServicioTituloSnapshot; return $this; }

    public function getProveedorServicioUrlSnapshot(): ?string { return $this->proveedorServicioUrlSnapshot; }
    public function setProveedorServicioUrlSnapshot(?string $proveedorServicioUrlSnapshot): self { $this->proveedorServicioUrlSnapshot = $proveedorServicioUrlSnapshot; return $this; }

    // getters/setters estándar para ambos
    public function getProveedorImagenesSnapshot(): array { return $this->proveedorImagenesSnapshot; }
    public function setProveedorImagenesSnapshot(array $v): self { $this->proveedorImagenesSnapshot = $v; return $this; }

    public function getProveedorServicioImagenesSnapshot(): array { return $this->proveedorServicioImagenesSnapshot; }
    public function setProveedorServicioImagenesSnapshot(array $v): self { $this->proveedorServicioImagenesSnapshot = $v; return $this; }

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

    /**
     * Determina si este proveedor debe mantenerse oculto en los vouchers o itinerarios del cliente.
     *
     * Este método existe para permitir el anonimato a nivel granular de un ítem
     * tarifario (ej. un transporte "White Label") cuando la cotización global sí
     * muestra a otros proveedores.
     *
     * @return bool
     */
    public function isProveedorOculto(): bool
    {
        return $this->proveedorOculto;
    }

    /**
     * Define si se oculta o expone el nombre y marca del proveedor logístico en la interfaz pública.
     *
     * @param bool $proveedorOculto
     * @return self
     */
    public function setProveedorOculto(bool $proveedorOculto): self
    {
        $this->proveedorOculto = $proveedorOculto;
        return $this;
    }

    public function getRolSnapshot(): ?string { return $this->rolSnapshot; }
    public function setRolSnapshot(?string $rolSnapshot): self { $this->rolSnapshot = $rolSnapshot; return $this; }
    public function getGrupoTarifa(): ?int { return $this->grupoTarifa; }
    public function setGrupoTarifa(?int $grupoTarifa): self { $this->grupoTarifa = $grupoTarifa; return $this; }
    public function getComisionOverrideSnapshot(): ?string { return $this->comisionOverrideSnapshot; }
    public function setComisionOverrideSnapshot(?string $comisionOverrideSnapshot): self { $this->comisionOverrideSnapshot = $comisionOverrideSnapshot; return $this; }
    public function getNotaRol(): array
    {
        return $this->notaRol ?? [];
    }

    public function setNotaRol(?array $notaRol): self
    {
        $this->notaRol = $notaRol ?? [];
        return $this;
    }
}