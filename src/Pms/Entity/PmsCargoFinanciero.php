<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Repository\PmsCargoFinancieroRepository;
use App\Security\Roles;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Un concepto financiero individual de una reserva Beds24 (invoiceItem).
 *
 * Espejo estructural de App\Message\Entity\Message: el hijo dentro de la "conversación"
 * financiera (PmsInformacionFinanciera). Cada fila corresponde 1:1 a un invoiceItem de
 * Beds24 (costo de alojamiento, limpieza, cargo por servicio, pagos, etc.).
 *
 * VERDAD HISTÓRICA: se guarda el invoiceItem tal cual llegó (subType/description/amount)
 * sin interpretarlo. La clasificación (alojamiento vs limpieza vs servicio) es el subTipo
 * de Beds24; no se transforma aquí.
 */
#[ApiResource(
    operations: [
        new Get(security: "is_granted('" . Roles::RESERVAS_SHOW . "')"),
        // Post crea un cargo MANUAL (beds24ItemId = null). Es el camino de las
        // reservas directas, que nunca reciben invoiceItems del canal.
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear cargos.',
        ),
        // `pms_cargo:patch` excluye moneda y tipoCambio: quedan fijos al registrar el
        // importe (el listener de coherencia bloquea el cambio de moneda de todas formas).
        new Patch(
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar cargos.',
            denormalizationContext: ['groups' => ['pms_cargo:patch']],
        ),
        // Delete SÓLO para cargos manuales: borrar uno de Beds24 no serviría de nada
        // (el siguiente pull lo recrearía) y desincronizaría el saldo mientras tanto.
        // Lo veta PmsInformacionFinancieraCoherenciaListener::assertCargoBorrable().
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar cargos.',
        ),
    ],
    routePrefix: '/pms',
    normalizationContext: ['groups' => ['pms_cargo:read', 'maestro:moneda:read']],
    denormalizationContext: ['groups' => ['pms_cargo:write']],
)]
#[ORM\Entity(repositoryClass: PmsCargoFinancieroRepository::class)]
#[ORM\Table(name: 'pms_cargo_financiero')]
#[ORM\UniqueConstraint(name: 'uq_cargo_info_item', columns: ['informacion_id', 'beds24_item_id'])]
#[ORM\HasLifecycleCallbacks]
class PmsCargoFinanciero
{
    use IdTrait;
    use TimestampTrait;

    public const string TIPO_CARGO = 'charge';
    public const string TIPO_PAGO  = 'payment';

    #[ORM\ManyToOne(targetEntity: PmsInformacionFinanciera::class, inversedBy: 'cargos')]
    #[ORM\JoinColumn(name: 'informacion_id', referencedColumnName: 'id', nullable: false)]
    #[Groups(['pms_cargo:write'])]
    private ?PmsInformacionFinanciera $informacionFinanciera = null;

    /**
     * Estancia a la que se imputa el cargo, para reservas con varias casitas.
     *
     * Sólo lo usan los cargos MANUALES: los de Beds24 se atribuyen por `beds24BookingId`
     * (§11.6), que ya identifica su booking. Aquí no hay booking del canal, así que el
     * operador elige la estancia a mano. NULL = cargo de la reserva en conjunto (lo normal
     * cuando sólo hay una casita).
     */
    #[ORM\ManyToOne(targetEntity: PmsEventoCalendario::class)]
    #[ORM\JoinColumn(name: 'evento_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?PmsEventoCalendario $evento = null;

    /**
     * ID del invoiceItem en Beds24. Llave de dedupe dentro del padre.
     *
     * NULL = cargo **manual**, creado a mano por el operador (típico de una reserva
     * directa, que no recibe información financiera del canal). MySQL admite varios
     * NULL en el índice único, así que el dedupe de los cargos de Beds24 sigue intacto.
     */
    #[ORM\Column(name: 'beds24_item_id', type: 'string', length: 50, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $beds24ItemId = null;

    /**
     * ID del booking de Beds24 al que pertenece este cargo.
     * Importa: una reserva-grupo (master) agrupa varios bookings.
     */
    #[ORM\Column(name: 'beds24_booking_id', type: 'string', length: 50, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $beds24BookingId = null;

    /** ID de la factura en Beds24. Sólo lo trae el endpoint on-demand, NO el webhook. */
    #[ORM\Column(name: 'beds24_invoice_id', type: 'string', length: 50, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $beds24InvoiceId = null;

    /** ID del facturado (invoiceeId) en Beds24, si aplica. */
    #[ORM\Column(name: 'beds24_invoicee_id', type: 'string', length: 50, nullable: true)]
    private ?string $beds24InvoiceeId = null;

    /** type de Beds24: 'charge' | 'payment'. */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $tipo = null;

    /** subType de Beds24 (8 = alojamiento, 11 = limpieza/servicio, etc.). Verdad histórica cruda. */
    #[ORM\Column(name: 'sub_tipo', type: 'integer', nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?int $subTipo = null;

    /** Clasificación estandarizada y traducible derivada de subTipo/descripción. */
    #[ORM\Column(name: 'tipo_cargo', type: 'string', length: 20, enumType: PmsTipoCargo::class, nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?PmsTipoCargo $tipoCargo = null;

    /** Moneda del importe (resolver contra maestro; default USD si no llega). */
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write'])]
    private ?MaestroMoneda $moneda = null;

    /** Tipo de cambio venta USD→PEN del día de registro (snapshot histórico). */
    #[ORM\Column(name: 'tipo_cambio', type: 'decimal', precision: 10, scale: 3, nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write'])]
    private ?string $tipoCambio = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?string $descripcion = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $estado = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $cantidad = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?string $monto = null;

    #[ORM\Column(name: 'total_linea', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?string $totalLinea = null;

    #[ORM\Column(name: 'tasa_iva', type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $tasaIva = null;

    /** createdBy de Beds24 (id del usuario/canal que lo generó). */
    #[ORM\Column(name: 'creado_por_beds24', type: 'string', length: 50, nullable: true)]
    private ?string $creadoPorBeds24 = null;

    /** createTime de Beds24. */
    #[ORM\Column(name: 'fecha_creacion_beds24', type: 'datetime', nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?DateTimeInterface $fechaCreacionBeds24 = null;

    /** invoiceDate de Beds24. Sólo lo trae el endpoint on-demand. */
    #[ORM\Column(name: 'fecha_factura', type: 'date', nullable: true)]
    private ?DateTimeInterface $fechaFactura = null;

    /** @param string|null $beds24ItemId NULL para un cargo manual (ver el campo). */
    public function __construct(?string $beds24ItemId = null)
    {
        $this->id = Uuid::v7();
        $this->beds24ItemId = $beds24ItemId;
    }

    /**
     * Invariantes de los cargos MANUALES (los de Beds24 llegan con todo resuelto):
     *
     * - `tipo = 'charge'`: el rollup de la cabecera sólo suma los `charge`
     *   (ver PmsInformacionFinancieraRecalculoService). Sin esto un cargo manual
     *   se guardaría pero no afectaría al saldo, que es justo lo que se espera de él.
     * - `fechaCreacionBeds24 = ahora`: la colección se ordena por esa fecha; sin
     *   valor los cargos manuales se apilarían al principio y sin fecha en la UI.
     */
    #[ORM\PrePersist]
    public function aplicarDefectosDeCargoManual(): void
    {
        if (!$this->isManual()) {
            return;
        }

        $this->tipo ??= self::TIPO_CARGO;
        $this->fechaCreacionBeds24 ??= new DateTimeImmutable();
    }

    /** ¿Lo creó un operador a mano (true) o llegó sincronizado desde Beds24 (false)? */
    #[Groups(['pms_cargo:read'])]
    public function isManual(): bool
    {
        return $this->beds24ItemId === null;
    }

    // =========================================================================
    // GETTERS Y SETTERS
    // =========================================================================

    #[Groups(['pms_cargo:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getInformacionFinanciera(): ?PmsInformacionFinanciera { return $this->informacionFinanciera; }
    public function setInformacionFinanciera(?PmsInformacionFinanciera $info): self { $this->informacionFinanciera = $info; return $this; }

    public function getEvento(): ?PmsEventoCalendario { return $this->evento; }
    public function setEvento(?PmsEventoCalendario $evento): self { $this->evento = $evento; return $this; }

    public function getBeds24ItemId(): ?string { return $this->beds24ItemId; }
    public function setBeds24ItemId(?string $id): self { $this->beds24ItemId = $id; return $this; }

    public function getBeds24BookingId(): ?string { return $this->beds24BookingId; }
    public function setBeds24BookingId(?string $id): self { $this->beds24BookingId = $id; return $this; }

    public function getBeds24InvoiceId(): ?string { return $this->beds24InvoiceId; }
    public function setBeds24InvoiceId(?string $id): self { $this->beds24InvoiceId = $id; return $this; }

    public function getBeds24InvoiceeId(): ?string { return $this->beds24InvoiceeId; }
    public function setBeds24InvoiceeId(?string $id): self { $this->beds24InvoiceeId = $id; return $this; }

    public function getTipo(): ?string { return $this->tipo; }
    public function setTipo(?string $tipo): self { $this->tipo = $tipo; return $this; }

    public function getSubTipo(): ?int { return $this->subTipo; }
    public function setSubTipo(?int $subTipo): self { $this->subTipo = $subTipo; return $this; }

    public function getTipoCargo(): ?PmsTipoCargo { return $this->tipoCargo; }
    public function setTipoCargo(?PmsTipoCargo $tipoCargo): self { $this->tipoCargo = $tipoCargo; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    public function getTipoCambio(): ?string { return $this->tipoCambio; }
    public function setTipoCambio(?string $tipoCambio): self { $this->tipoCambio = $tipoCambio; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): self { $this->descripcion = $descripcion; return $this; }

    public function getEstado(): ?string { return $this->estado; }
    public function setEstado(?string $estado): self { $this->estado = $estado; return $this; }

    public function getCantidad(): ?string { return $this->cantidad; }
    public function setCantidad(?string $cantidad): self { $this->cantidad = $cantidad; return $this; }

    public function getMonto(): ?string { return $this->monto; }
    public function setMonto(?string $monto): self { $this->monto = $monto; return $this; }

    public function getTotalLinea(): ?string { return $this->totalLinea; }
    public function setTotalLinea(?string $totalLinea): self { $this->totalLinea = $totalLinea; return $this; }

    public function getTasaIva(): ?string { return $this->tasaIva; }
    public function setTasaIva(?string $tasaIva): self { $this->tasaIva = $tasaIva; return $this; }

    public function getCreadoPorBeds24(): ?string { return $this->creadoPorBeds24; }
    public function setCreadoPorBeds24(?string $creadoPor): self { $this->creadoPorBeds24 = $creadoPor; return $this; }

    public function getFechaCreacionBeds24(): ?DateTimeInterface { return $this->fechaCreacionBeds24; }
    public function setFechaCreacionBeds24(?DateTimeInterface $fecha): self { $this->fechaCreacionBeds24 = $fecha; return $this; }

    public function getFechaFactura(): ?DateTimeInterface { return $this->fechaFactura; }
    public function setFechaFactura(?DateTimeInterface $fecha): self { $this->fechaFactura = $fecha; return $this; }

    /** ¿Es un cargo (charge) frente a un pago (payment)? */
    public function esCargo(): bool
    {
        return $this->tipo === self::TIPO_CARGO;
    }
}
