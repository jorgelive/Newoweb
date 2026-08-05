<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Maestro\MaestroMoneda;
use App\Pms\ApiPlatform\Dto\PmsTarifaMasivaInput;
use App\Pms\ApiPlatform\Dto\PmsTarifaMasivaResult;
use App\Pms\ApiPlatform\State\PmsTarifaMasivaProcessor;
use App\Pms\Repository\PmsTarifaRangoRepository;
use App\Security\Roles;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entidad PmsTarifaRango.
 * Define precios y estancias mínimas por unidad y rango de fechas.
 * IDs: UUID (Propio/Negocio), String 3 (Moneda).
 *
 * El calendario SPA (Vue) lee los rangos por los providers `tarifa_ranges_spa` /
 * `tarifa_compressed_ranges_spa` (ver docs/Calendar_architecture.md) y los edita
 * por estas operaciones REST. Toda escritura dispara el push de tarifas a Beds24
 * vía Beds24RatesPushQueueListener (incluido el Delete), así que no hace falta
 * ninguna protección extra de borrado como la de PmsEventoCalendario.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
        ),
        new Get(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
        ),
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear tarifas.',
        ),
        new Patch(
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar tarifas.',
        ),
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar tarifas.',
        ),
        // Equivalente del botón "Generar Masivo" de EasyAdmin
        // (PmsTarifaRangoCrudController::generarMasivo): crea un rango por cada
        // unidad activa con tarifa base, aplicando un % sobre ese precio base.
        new Post(
            uriTemplate: '/pms_tarifa_rangos/generar-masivo',
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para generar tarifas.',
            input: PmsTarifaMasivaInput::class,
            output: PmsTarifaMasivaResult::class,
            normalizationContext: ['groups' => ['pms_tarifa_masiva:read']],
            denormalizationContext: ['groups' => ['pms_tarifa_masiva:write']],
            processor: PmsTarifaMasivaProcessor::class,
            read: false,
        ),
    ],
    routePrefix: '/pms',
    // `pms_unidad:read` y `maestro:moneda:read` se incluyen para que unidad y
    // moneda lleguen pobladas (id/nombre) en vez de como IRIs sueltas, sin tener
    // que duplicar grupos de serialización en esas dos entidades.
    normalizationContext: ['groups' => ['pms_tarifa:read', 'pms_unidad:read', 'maestro:moneda:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['pms_tarifa:write']],
    order: ['fechaInicio' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['unidad' => 'exact', 'moneda' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['activo', 'importante'])]
#[ApiFilter(DateFilter::class, properties: ['fechaInicio', 'fechaFin'])]
#[ApiFilter(OrderFilter::class, properties: ['fechaInicio', 'fechaFin', 'precio', 'prioridad'])]
#[ORM\Entity(repositoryClass: PmsTarifaRangoRepository::class)]
#[ORM\Table(name: 'pms_tarifa_rango')]
#[ORM\HasLifecycleCallbacks]
class PmsTarifaRango
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    /* ======================================================
     * RELACIONES DE NEGOCIO (UUID - BINARY 16)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(
        name: 'unidad_id',
        referencedColumnName: 'id',
        nullable: false
    )]
    #[Assert\NotNull(message: 'La unidad es obligatoria.')]
    #[Groups(['pms_tarifa:read', 'pms_tarifa:write'])]
    private ?PmsUnidad $unidad = null;

    /* ======================================================
     * DATOS DE TARIFA Y RANGO
     * ====================================================== */

    #[ORM\Column(type: 'date')]
    #[Assert\NotNull(message: 'La fecha de inicio es obligatoria.')]
    #[Groups(['pms_tarifa:read', 'pms_tarifa:write'])]
    private ?DateTimeInterface $fechaInicio = null;

    #[ORM\Column(type: 'date')]
    #[Assert\NotNull(message: 'La fecha de fin es obligatoria.')]
    /*
     * `fechaFin` es EXCLUSIVA: marca la salida, igual que en una reserva. El motor
     * normaliza todo rango a `[inicio, fin)` (TarifaDailyPriceFlattener::flatten())
     * y NO pone precio al día `fin`, así que una tarifa puntual de una noche es
     * `fin = inicio + 1 día`, no `fin = inicio`.
     *
     * Antes era `GreaterThanOrEqual` con la idea de admitir «un rango de un solo
     * día». No admitía nada: con `fin === inicio` el flattener descarta el rango
     * entero (`if ($re <= $rs) continue`), o sea que se guardaba sin error y no
     * hacía absolutamente nada — el peor fallo posible, porque es mudo.
     */
    #[Assert\GreaterThan(propertyPath: 'fechaInicio', message: 'La fecha de fin debe ser posterior a la de inicio: es la salida y no lleva tarifa, así que un rango con fin = inicio no cubre ninguna noche.')]
    #[Groups(['pms_tarifa:read', 'pms_tarifa:write'])]
    private ?DateTimeInterface $fechaFin = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotNull(message: 'El precio es obligatorio.')]
    #[Assert\PositiveOrZero(message: 'El precio no puede ser negativo.')]
    #[Groups(['pms_tarifa:read', 'pms_tarifa:write'])]
    private ?string $precio = null;

    /**
     * Moneda: ID Natural (String 3)
     * SE ELIMINA BINARY(16)
     */
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: 'La moneda es obligatoria.')]
    #[Groups(['pms_tarifa:read', 'pms_tarifa:write'])]
    private ?MaestroMoneda $moneda = null;

    #[ORM\Column(type: 'integer', options: ['default' => 2])]
    #[Assert\PositiveOrZero(message: 'La estancia mínima no puede ser negativa.')]
    #[Groups(['pms_tarifa:read', 'pms_tarifa:write'])]
    private int $minStay = 2;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['pms_tarifa:read', 'pms_tarifa:write'])]
    private bool $importante = false;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['pms_tarifa:read', 'pms_tarifa:write'])]
    private int $prioridad = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['pms_tarifa:read', 'pms_tarifa:write'])]
    private bool $activo = true;

    /* ======================================================
     * RELACIONES TÉCNICAS (COLAS PUSH)
     * ====================================================== */

    /** @var Collection<int, PmsRatesPushQueue> */
    #[ORM\OneToMany(mappedBy: 'tarifaRango', targetEntity: PmsRatesPushQueue::class, orphanRemoval: true)]
    private Collection $queues;

    public function __construct()
    {
        $this->queues = new ArrayCollection();

        $this->id = Uuid::v7();
    }

    /**
     * Estado consolidado del push de tarifas a Beds24 (mismo criterio y mismos
     * valores que PmsEventoCalendario::getSyncStatus(), para que el frontend
     * pinte ambos módulos con la misma leyenda).
     *
     * @return string 'local' | 'error' | 'pending' | 'synced'
     */
    #[Groups(['pms_tarifa:read'])]
    public function getSyncStatus(): string
    {
        if ($this->queues->isEmpty()) return 'local';

        $isPending = false;

        foreach ($this->queues as $queue) {
            if ($queue->getStatus() === PmsRatesPushQueue::STATUS_FAILED) return 'error';
            if (in_array($queue->getStatus(), [PmsRatesPushQueue::STATUS_PENDING, PmsRatesPushQueue::STATUS_PROCESSING], true)) {
                $isPending = true;
            }
        }

        return $isPending ? 'pending' : 'synced';
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    #[Groups(['pms_tarifa:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getUnidad(): ?PmsUnidad { return $this->unidad; }
    public function setUnidad(?PmsUnidad $unidad): self { $this->unidad = $unidad; return $this; }

    public function getFechaInicio(): ?DateTimeInterface { return $this->fechaInicio; }
    public function setFechaInicio(?DateTimeInterface $fechaInicio): self { $this->fechaInicio = $fechaInicio; return $this; }

    public function getFechaFin(): ?DateTimeInterface { return $this->fechaFin; }
    public function setFechaFin(?DateTimeInterface $fechaFin): self { $this->fechaFin = $fechaFin; return $this; }

    public function getPrecio(): ?string { return $this->precio; }
    public function setPrecio(?string $precio): self { $this->precio = $precio; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    public function getMinStay(): int { return $this->minStay; }
    public function setMinStay(int $minStay): self { $this->minStay = $minStay; return $this; }

    public function isImportante(): bool { return $this->importante; }
    public function setImportante(bool $importante): self { $this->importante = $importante; return $this; }

    public function getPrioridad(): int { return $this->prioridad; }
    public function setPrioridad(int $prioridad): self { $this->prioridad = $prioridad; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    /** @return Collection<int, PmsRatesPushQueue> */
    public function getQueues(): Collection { return $this->queues; }

    public function addQueue(PmsRatesPushQueue $queue): self
    {
        if (!$this->queues->contains($queue)) {
            $this->queues->add($queue);
            $queue->setTarifaRango($this);
        }
        return $this;
    }

    public function removeQueue(PmsRatesPushQueue $queue): self
    {
        if ($this->queues->removeElement($queue)) {
            if ($queue->getTarifaRango() === $this) {
                $queue->setTarifaRango(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        $unidad = $this->unidad ? $this->unidad->getNombre() : 'Unidad';
        $inicio = $this->fechaInicio ? $this->fechaInicio->format('Y-m-d') : '...';
        $fin = $this->fechaFin ? $this->fechaFin->format('Y-m-d') : '...';

        return sprintf('%s (%s a %s)', $unidad, $inicio, $fin);
    }
}