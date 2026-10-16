<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Cotizacion\ApiPlatform\State\CotizacionPagoProcessor;
use App\Cotizacion\Enum\CotizacionMedioPagoEnum;
use App\Cotizacion\Repository\CotizacionPagoRepository;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\User;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un pago del cliente a cuenta de un expediente de turismo (16/10/2026).
 *
 * Hasta hoy no había dónde apuntarlo: `OperacionPago` es lo que se le paga al PROVEEDOR. Sin esto,
 * el saldo de un viaje vivía en la cabeza de quien cobró, «Cómo reservar» seguía pidiendo el
 * adelanto a quien ya lo había pagado y ningún pasajero de turismo era nunca «cliente» para el
 * agente (`CotizacionFile::vinculoComercial()`). Ver docs/Cotizaciones.md §6.a.
 *
 * ── Del EXPEDIENTE, no de una versión ───────────────────────────────────────
 * Las versiones se clonan, pasan a histórico y abren operativa; el dinero tiene que sobrevivir a
 * todo eso. `propuesta` dice qué número se estaba pagando —por eso es un entero y no una relación:
 * la v2 confirmada y su operativa comparten número—, y la cuenta toma el total de la que manda
 * ({@see \App\Cotizacion\Dto\CuentaDelExpediente}).
 *
 * ── Una moneda, y su equivalente ────────────────────────────────────────────
 * Los totales de una cotización están en dólares. Un pago en soles guarda **el tipo de cambio de
 * ese día** y descuenta su equivalente ({@see self::equivalenteUsd()}): es lo que pidió Jorge, y lo
 * mismo que hace la cuenta de alojamiento con un pago en otra moneda.
 *
 * ── No se borra ni se edita: se ANULA ───────────────────────────────────────
 * Un pago mal metido se anula con su motivo y se vuelve a registrar. Borrarlo dejaría la caja
 * contando una historia distinta de la que pasó, y editar el importe invita a cuadrarla cambiando
 * el pasado. Por eso no hay `Delete` ni un `Patch` general: sólo `/anular`.
 *
 * ── Registrarlo NO confirma la propuesta ────────────────────────────────────
 * Al revés que en alojamiento. Confirmar dispara la operativa y La Biblia, y eso lo decide una
 * persona: la ficha del expediente avisa de que hay un pago sobre una propuesta sin confirmar
 * (`CuentaDelExpediente::$propuestaSinConfirmar`). Decisión de Jorge, 16/10/2026.
 */
#[ApiResource(
    shortName: 'CotizacionPago',
    operations: [
        new Get(security: "is_granted('" . Roles::RESERVAS_SHOW . "')"),
        new Post(
            denormalizationContext: ['groups' => ['cotizacion_pago:write']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para registrar pagos.',
            processor: CotizacionPagoProcessor::class,
        ),
        // Lo único que se cambia de un pago ya registrado. Ver el docblock.
        new Patch(
            uriTemplate: '/cotizacion_pagos/{id}/anular',
            denormalizationContext: ['groups' => ['cotizacion_pago:anular']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para anular pagos.',
            processor: CotizacionPagoProcessor::class,
        ),
    ],
    routePrefix: '/sales',
    normalizationContext: ['groups' => ['cotizacion_pago:read', 'timestamp:read']],
)]
#[ORM\Entity(repositoryClass: CotizacionPagoRepository::class)]
#[ORM\Table(name: 'cotizacion_pago')]
#[ORM\Index(name: 'idx_cotizacion_pago_fecha', columns: ['fecha_pago'])]
#[ORM\HasLifecycleCallbacks]
class CotizacionPago
{
    use IdTrait;
    use TimestampTrait;

    /** Las monedas en que se cobra. Las de la cotización: dólares y soles. */
    public const array MONEDAS = ['USD', 'PEN'];

    /**
     * Sin `CASCADE` a propósito: un pago es un documento contable, y un expediente con pagos no
     * debería poder borrarse sin que alguien decida antes qué pasa con ese dinero.
     */
    #[Groups(['cotizacion_pago:write'])]
    #[Assert\NotNull(message: 'El pago tiene que ser de un expediente.')]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'pagos')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false)]
    private ?CotizacionFile $file = null;

    /** El número de propuesta que se pagaba; `null` = a cuenta del viaje, sin elegir versión. */
    #[Groups(['cotizacion_pago:read', 'file:item:read', 'cotizacion_pago:write'])]
    #[Assert\Positive]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $propuesta = null;

    /** Quién pagó, en un grupo donde cada familia paga lo suyo. Opcional: un viaje de pareja no elige. */
    #[Groups(['cotizacion_pago:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFilepasajero::class)]
    #[ORM\JoinColumn(name: 'pasajero_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?CotizacionFilepasajero $pasajero = null;

    #[Groups(['cotizacion_pago:read', 'file:item:read', 'cotizacion_pago:write'])]
    #[Assert\NotBlank]
    #[Assert\Positive(message: 'El importe tiene que ser mayor que cero.')]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $monto = '0.00';

    #[Groups(['cotizacion_pago:read', 'file:item:read', 'cotizacion_pago:write'])]
    #[Assert\Choice(choices: self::MONEDAS, message: 'Se cobra en dólares (USD) o en soles (PEN).')]
    #[ORM\Column(type: 'string', length: 3)]
    private string $moneda = 'USD';

    /** Soles por dólar el día del pago. Obligatorio en un pago en soles; ver {@see self::equivalenteUsd()}. */
    #[Groups(['cotizacion_pago:read', 'file:item:read', 'cotizacion_pago:write'])]
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $tipoCambio = null;

    #[Groups(['cotizacion_pago:read', 'file:item:read', 'cotizacion_pago:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: CotizacionMedioPagoEnum::class)]
    private CotizacionMedioPagoEnum $medio = CotizacionMedioPagoEnum::TRANSFERENCIA_BANCARIA;

    /** El día en que entró el dinero (hora de pared), no el día en que se apuntó. */
    #[Groups(['cotizacion_pago:read', 'file:item:read', 'cotizacion_pago:write'])]
    #[Assert\NotNull(message: 'Falta la fecha del pago.')]
    #[ORM\Column(type: 'date_immutable')]
    private ?DateTimeImmutable $fechaPago = null;

    /** Nº de operación, voucher, código de Western Union. */
    #[Groups(['cotizacion_pago:read', 'file:item:read', 'cotizacion_pago:write'])]
    #[Assert\Length(max: 100)]
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $referencia = null;

    #[Groups(['cotizacion_pago:read', 'file:item:read', 'cotizacion_pago:write'])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nota = null;

    /** Quién lo apuntó. Lo pone `CotizacionPagoProcessor` con la sesión, nunca el cuerpo de la petición. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'registrado_por_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $registradoPor = null;

    /** El enlace de pasarela que lo cobró, cuando llegue el cobro por Culqi (ver docs/Pendientes.md). */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $enlacePagoId = null;

    #[Groups(['cotizacion_pago:read', 'file:item:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $anulado = false;

    #[Groups(['cotizacion_pago:read', 'file:item:read', 'cotizacion_pago:anular'])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $motivoAnulacion = null;

    #[Groups(['cotizacion_pago:read', 'file:item:read'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $anuladoEn = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'anulado_por_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $anuladoPor = null;

    public function __construct()
    {
        $this->initializeId();
        $this->fechaPago = new DateTimeImmutable('today');
    }

    #[Groups(['cotizacion_pago:read', 'file:item:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /** Un pago en soles sin tipo de cambio no se puede descontar de una cuenta en dólares. */
    #[Assert\Callback]
    public function validar(ExecutionContextInterface $context): void
    {
        if ($this->moneda === 'PEN' && !((float) $this->tipoCambio > 0)) {
            $context->buildViolation('Un pago en soles necesita el tipo de cambio de ese día.')
                ->atPath('tipoCambio')
                ->addViolation();
        }

        $pasajero = $this->pasajero?->getFile();
        if ($pasajero !== null && $this->file !== null && !$pasajero->getId()?->equals($this->file->getId())) {
            $context->buildViolation('Ese pasajero no es de este expediente.')
                ->atPath('pasajero')
                ->addViolation();
        }
    }

    /**
     * Cuánto descuenta de una cuenta en dólares. En soles, al tipo de cambio de ese día.
     *
     * Un pago en soles sin tipo de cambio no debería existir —lo impide {@see self::validar()}—;
     * si aun así llega uno, cuenta 0 en vez de inventar un cambio.
     */
    public function equivalenteUsd(): float
    {
        if ($this->moneda === 'USD') {
            return (float) $this->monto;
        }

        $tc = (float) $this->tipoCambio;

        return $tc > 0 ? round((float) $this->monto / $tc, 2) : 0.0;
    }

    /** ¿Cuenta para el saldo? Todo lo registrado y no anulado. */
    public function cuenta(): bool
    {
        return !$this->anulado;
    }

    /**
     * Lo anula. Es de ida: un pago anulado no vuelve, se registra otro.
     *
     * @throws \DomainException si ya estaba anulado o falta el motivo.
     */
    public function anular(string $motivo, ?User $quien, DateTimeImmutable $cuando): void
    {
        if ($this->anulado) {
            throw new \DomainException('Ese pago ya está anulado.');
        }

        if (trim($motivo) === '') {
            throw new \DomainException('Para anular un pago hace falta decir por qué.');
        }

        $this->anulado = true;
        $this->motivoAnulacion = trim($motivo);
        $this->anuladoPor = $quien;
        $this->anuladoEn = $cuando;
    }

    /** Para la ficha: el nombre de quien pagó, sin exponer la relación entera. */
    #[Groups(['cotizacion_pago:read', 'file:item:read'])]
    public function getPasajeroNombre(): ?string
    {
        if ($this->pasajero === null) {
            return null;
        }

        $nombre = trim(sprintf('%s %s', $this->pasajero->getNombre() ?? '', $this->pasajero->getApellido() ?? ''));

        return $nombre !== '' ? $nombre : null;
    }

    /** El id del pasajero, para preseleccionarlo; la relación entera no viaja. */
    #[Groups(['cotizacion_pago:read', 'file:item:read'])]
    public function getPasajeroId(): ?string
    {
        return $this->pasajero?->getId()?->toRfc4122();
    }

    #[Groups(['cotizacion_pago:read', 'file:item:read'])]
    public function getRegistradoPorNombre(): ?string
    {
        return $this->registradoPor?->getFullname() ?: null;
    }

    #[Groups(['cotizacion_pago:read', 'file:item:read'])]
    public function getAnuladoPorNombre(): ?string
    {
        return $this->anuladoPor?->getFullname() ?: null;
    }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    public function getPropuesta(): ?int { return $this->propuesta; }
    public function setPropuesta(?int $propuesta): self { $this->propuesta = $propuesta; return $this; }

    public function getPasajero(): ?CotizacionFilepasajero { return $this->pasajero; }
    public function setPasajero(?CotizacionFilepasajero $pasajero): self { $this->pasajero = $pasajero; return $this; }

    public function getMonto(): string { return $this->monto; }
    public function setMonto(string $monto): self { $this->monto = $monto; return $this; }

    public function getMoneda(): string { return $this->moneda; }
    public function setMoneda(string $moneda): self { $this->moneda = strtoupper(trim($moneda)); return $this; }

    public function getTipoCambio(): ?string { return $this->tipoCambio; }
    public function setTipoCambio(?string $tipoCambio): self { $this->tipoCambio = $tipoCambio !== null && trim($tipoCambio) !== '' ? $tipoCambio : null; return $this; }

    public function getMedio(): CotizacionMedioPagoEnum { return $this->medio; }
    public function setMedio(CotizacionMedioPagoEnum $medio): self { $this->medio = $medio; return $this; }

    public function getFechaPago(): ?DateTimeImmutable { return $this->fechaPago; }
    public function setFechaPago(?DateTimeImmutable $fechaPago): self { $this->fechaPago = $fechaPago; return $this; }

    public function getReferencia(): ?string { return $this->referencia; }
    public function setReferencia(?string $referencia): self { $this->referencia = $referencia !== null && trim($referencia) !== '' ? trim($referencia) : null; return $this; }

    public function getNota(): ?string { return $this->nota; }
    public function setNota(?string $nota): self { $this->nota = $nota !== null && trim($nota) !== '' ? trim($nota) : null; return $this; }

    public function getRegistradoPor(): ?User { return $this->registradoPor; }
    public function setRegistradoPor(?User $registradoPor): self { $this->registradoPor = $registradoPor; return $this; }

    public function getEnlacePagoId(): ?Uuid { return $this->enlacePagoId; }
    public function setEnlacePagoId(?Uuid $enlacePagoId): self { $this->enlacePagoId = $enlacePagoId; return $this; }

    public function isAnulado(): bool { return $this->anulado; }

    public function getMotivoAnulacion(): ?string { return $this->motivoAnulacion; }

    /**
     * Sólo lo escribe el `Patch` de `/anular`: el procesador lee el motivo y llama a
     * {@see self::anular()}, que es quien cambia el estado. Un motivo suelto no anula nada.
     */
    public function setMotivoAnulacion(?string $motivoAnulacion): self { $this->motivoAnulacion = $motivoAnulacion; return $this; }

    public function getAnuladoEn(): ?DateTimeImmutable { return $this->anuladoEn; }
    public function getAnuladoPor(): ?User { return $this->anuladoPor; }
}
