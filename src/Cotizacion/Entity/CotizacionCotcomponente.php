<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\AutoTranslate;
use App\Cotizacion\Enum\ComponenteEstadoEnum;
use App\Cotizacion\Enum\DetalleOperativoTipoEnum;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Travel\Enum\ComponenteModoEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Logística inmutable. Congela los ítems bilingües, su estado y horarios precisos.
 */
#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_cotcomponente')]
#[ORM\HasLifecycleCallbacks]
class CotizacionCotcomponente
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionCotservicio::class, inversedBy: 'cotcomponentes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotservicio $cotservicio = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\ManyToOne(targetEntity: CotizacionSegmento::class, cascade: ['persist'], inversedBy: 'cotcomponentes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CotizacionSegmento $cotsegmento = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $nombreSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $cantidad = 1;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ComponenteEstadoEnum::class, options: ['default' => 'Pendiente'])]
    private ComponenteEstadoEnum $estado = ComponenteEstadoEnum::PENDIENTE;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ComponenteModoEnum::class, options: ['default' => 'incluido'])]
    private ComponenteModoEnum $modo = ComponenteModoEnum::INCLUIDO;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaHoraInicio = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaHoraFin = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['nombreSnapshot'], format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $snapshotItems = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\OneToMany(mappedBy: 'cotcomponente', targetEntity: CotizacionCottarifa::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cottarifas;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $componenteMaestroId = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['detalle'], format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $detallesOperativos = [];

    public function __construct()
    {
        $this->initializeId();
        $this->cottarifas = new ArrayCollection();
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

    /**
     * Obtiene el servicio de cotización padre.
     *
     * @return CotizacionCotservicio|null
     */
    public function getCotservicio(): ?CotizacionCotservicio { return $this->cotservicio; }

    /**
     * Establece el servicio de cotización padre.
     *
     * @param CotizacionCotservicio|null $cotservicio
     * @return self
     */
    public function setCotservicio(?CotizacionCotservicio $cotservicio): self { $this->cotservicio = $cotservicio; return $this; }

    /**
     * Obtiene el segmento de la cotización vinculado.
     *
     * @return CotizacionSegmento|null
     */
    public function getCotsegmento(): ?CotizacionSegmento { return $this->cotsegmento; }

    /**
     * Establece el segmento de la cotización vinculado.
     *
     * @param CotizacionSegmento|null $cotsegmento
     * @return self
     */
    public function setCotsegmento(?CotizacionSegmento $cotsegmento): self { $this->cotsegmento = $cotsegmento; return $this; }

    /**
     * Obtiene el snapshot del nombre del componente.
     *
     * @return array
     */
    public function getNombreSnapshot(): array { return $this->nombreSnapshot; }

    /**
     * Establece el snapshot del nombre del componente.
     *
     * @param array $nombreSnapshot
     * @return self
     */
    public function setNombreSnapshot(array $nombreSnapshot): self { $this->nombreSnapshot = $nombreSnapshot; return $this; }

    /**
     * Obtiene la cantidad de componentes instanciados.
     *
     * @return int
     */
    public function getCantidad(): int { return $this->cantidad; }

    /**
     * Establece la cantidad de componentes instanciados.
     *
     * @param int $cantidad
     * @return self
     */
    public function setCantidad(int $cantidad): self { $this->cantidad = $cantidad; return $this; }

    /**
     * Obtiene el estado del componente.
     *
     * @return ComponenteEstadoEnum
     */
    public function getEstado(): ComponenteEstadoEnum { return $this->estado; }

    /**
     * Establece el estado del componente.
     *
     * @param ComponenteEstadoEnum $estado
     * @return self
     */
    public function setEstado(ComponenteEstadoEnum $estado): self { $this->estado = $estado; return $this; }

    /**
     * Obtiene la modalidad del componente en la cotización.
     *
     * @return ComponenteModoEnum
     */
    public function getModo(): ComponenteModoEnum { return $this->modo; }

    /**
     * Establece la modalidad del componente en la cotización.
     *
     * @param ComponenteModoEnum $modo
     * @return self
     */
    public function setModo(ComponenteModoEnum $modo): self { $this->modo = $modo; return $this; }

    /**
     * Obtiene la fecha y hora de inicio de la operativa.
     *
     * @return DateTimeImmutable|null
     */
    public function getFechaHoraInicio(): ?DateTimeImmutable { return $this->fechaHoraInicio; }

    /**
     * Establece la fecha y hora de inicio de la operativa.
     *
     * @param DateTimeImmutable|null $fechaHoraInicio
     * @return self
     */
    public function setFechaHoraInicio(?DateTimeImmutable $fechaHoraInicio): self { $this->fechaHoraInicio = $fechaHoraInicio; return $this; }

    /**
     * Obtiene la fecha y hora de fin de la operativa.
     *
     * @return DateTimeImmutable|null
     */
    public function getFechaHoraFin(): ?DateTimeImmutable { return $this->fechaHoraFin; }

    /**
     * Establece la fecha y hora de fin de la operativa.
     *
     * @param DateTimeImmutable|null $fechaHoraFin
     * @return self
     */
    public function setFechaHoraFin(?DateTimeImmutable $fechaHoraFin): self { $this->fechaHoraFin = $fechaHoraFin; return $this; }

    /**
     * Obtiene los items guardados en el snapshot.
     *
     * @return array
     */
    public function getSnapshotItems(): array { return $this->snapshotItems; }

    /**
     * Establece los items guardados en el snapshot.
     *
     * @param array $snapshotItems
     * @return self
     */
    public function setSnapshotItems(array $snapshotItems): self { $this->snapshotItems = $snapshotItems; return $this; }

    /**
     * Obtiene las tarifas vinculadas al componente.
     *
     * @return Collection
     */
    public function getCottarifas(): Collection { return $this->cottarifas; }

    /**
     * Añade una tarifa a la colección de tarifas del componente.
     *
     * @param CotizacionCottarifa $cottarifa
     * @return self
     */
    public function addCottarifa(CotizacionCottarifa $cottarifa): self
    {
        if (!$this->cottarifas->contains($cottarifa)) {
            $this->cottarifas->add($cottarifa);
            $cottarifa->setCotcomponente($this);
        }
        return $this;
    }

    /**
     * Remueve una tarifa de la colección de tarifas del componente.
     *
     * @param CotizacionCottarifa $cottarifa
     * @return self
     */
    public function removeCottarifa(CotizacionCottarifa $cottarifa): self
    {
        if ($this->cottarifas->removeElement($cottarifa)) {
            if ($cottarifa->getCotcomponente() === $this) { $cottarifa->setCotcomponente(null); }
        }
        return $this;
    }

    /**
     * Obtiene los detalles operativos internos.
     *
     * @return array
     */
    public function getDetallesOperativos(): array
    {
        return $this->detallesOperativos;
    }

    /**
     * Establece los detalles operativos internos, validando su tipo.
     *
     * @param array $detallesOperativos
     * @return self
     * @throws \InvalidArgumentException
     */
    public function setDetallesOperativos(array $detallesOperativos): self
    {
        foreach ($detallesOperativos as $bloque) {
            if (!isset($bloque['tipo']) || DetalleOperativoTipoEnum::tryFrom($bloque['tipo']) === null) {
                throw new \InvalidArgumentException('Tipo de detalle operativo inválido.');
            }
        }
        $this->detallesOperativos = $detallesOperativos;
        return $this;
    }

    /**
     * Superficie segura para exponer al cliente final: filtra bloques OPERATIVA.
     * Retorna únicamente los detalles que el cliente está autorizado a ver.
     *
     * @return array
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    public function getDetallesParaCliente(): array
    {
        return array_values(array_filter(
            $this->detallesOperativos,
            static fn (array $bloque): bool =>
                ($bloque['tipo'] ?? null) === DetalleOperativoTipoEnum::CLIENTE->value
        ));
    }

    /**
     * Obtiene el ID del componente maestro si lo hubiera.
     *
     * @return string|null
     */
    public function getComponenteMaestroId(): ?string { return $this->componenteMaestroId; }

    /**
     * Establece el ID del componente maestro.
     *
     * @param string|null $componenteMaestroId
     * @return self
     */
    public function setComponenteMaestroId(?string $componenteMaestroId): self { $this->componenteMaestroId = $componenteMaestroId; return $this; }
}