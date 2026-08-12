<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\User;
use App\Pms\ApiPlatform\State\PmsPagoFinancieroProcessor;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Repository\PmsPagoFinancieroRepository;
use App\Security\Roles;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Pago efectivamente recibido de un huésped, registrado por nosotros.
 *
 * A diferencia de PmsCargoFinanciero (que viene de Beds24), el pago es un registro propio:
 * captura el medio de pago, su moneda, el tipo de cambio del día y la comisión aplicada
 * (p. ej. 5.5% en tarjeta). Cuelga de la misma cabecera financiera (PmsInformacionFinanciera).
 */
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('" . Roles::RESERVAS_SHOW . "')"),
        new Get(security: "is_granted('" . Roles::RESERVAS_SHOW . "')"),
        // `processor` en las dos escrituras: resuelve el `cobradorId` (UUID plano) a la
        // relación `User`, que API Platform no sabe hidratar por su cuenta porque `User`
        // no es un ApiResource. Ver PmsPagoFinancieroProcessor.
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para registrar pagos.',
            processor: PmsPagoFinancieroProcessor::class,
        ),
        // `pms_pago:patch` excluye `moneda` a propósito: una vez registrado el pago
        // su moneda queda fija (PmsInformacionFinancieraCoherenciaListener la bloquea
        // de todas formas con DomainException; aquí se evita el 500 innecesario).
        new Patch(
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar pagos.',
            denormalizationContext: ['groups' => ['pms_pago:patch']],
            processor: PmsPagoFinancieroProcessor::class,
        ),
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar pagos.',
        ),
    ],
    routePrefix: '/pms',
    normalizationContext: ['groups' => ['pms_pago:read', 'maestro:moneda:read']],
    denormalizationContext: ['groups' => ['pms_pago:write']],
    order: ['fechaPago' => 'DESC'],
)]
// SIN SearchFilter sobre `informacionFinanciera`: con UUID binarios ese filtro devuelve
// SIEMPRE vacío y sin error (§12.6). Los pagos ya llegan embebidos en la cabecera, que se
// obtiene por `/pms_informacion_financieras/por-reserva/{reservaId}`.
#[ORM\Entity(repositoryClass: PmsPagoFinancieroRepository::class)]
#[ORM\Table(name: 'pms_pago_financiero')]
#[ORM\HasLifecycleCallbacks]
class PmsPagoFinanciero
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: PmsInformacionFinanciera::class, inversedBy: 'pagos')]
    #[ORM\JoinColumn(name: 'informacion_id', referencedColumnName: 'id', nullable: false)]
    #[Groups(['pms_pago:write'])]
    private ?PmsInformacionFinanciera $informacionFinanciera = null;

    /** Moneda del pago (resolver contra maestro; default USD). Los pagos en soles son comunes. */
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['pms_pago:read', 'pms_pago:write'])]
    private ?MaestroMoneda $moneda = null;

    /**
     * Importe NETO que se imputa a la reserva, sin el recargo del medio de pago.
     *
     * Es el único que entra al rollup de `total_pagos` (§12.2): el recargo por tarjeta lo
     * paga el huésped por encima, pero cubre el coste de la pasarela — no abona su estancia.
     * Lo que se le cobra de verdad a la tarjeta es `montoTotalCobrado()`.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['pms_pago:read', 'pms_pago:write', 'pms_pago:patch'])]
    private string $monto = '0.00';

    /** Tipo de cambio venta USD→PEN del día del pago (snapshot; usado cuando la moneda es soles). */
    #[ORM\Column(name: 'tipo_cambio', type: 'decimal', precision: 10, scale: 3, nullable: true)]
    #[Groups(['pms_pago:read', 'pms_pago:write', 'pms_pago:patch'])]
    private ?string $tipoCambio = null;

    #[ORM\Column(name: 'medio_pago', type: 'string', length: 30, enumType: PmsMedioPago::class)]
    #[Groups(['pms_pago:read', 'pms_pago:write', 'pms_pago:patch'])]
    private PmsMedioPago $medioPago = PmsMedioPago::EFECTIVO;

    /**
     * Recargo del medio de pago en **PORCENTAJE** (5.50 = 5.5%), no en importe.
     *
     * Se guarda el porcentaje y no el importe calculado porque es el dato que de verdad
     * define la operación: si luego se corrige el monto, el recargo sigue siendo correcto
     * sin tener que recalcularlo a mano. El importe sale de `montoComision()`.
     *
     * Por defecto lo propone `PmsMedioPago::comisionPorcentaje()` (5.5 en tarjeta, 0 en el
     * resto), pero el operador puede pisarlo.
     */
    #[ORM\Column(name: 'comision_porcentaje', type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Groups(['pms_pago:read', 'pms_pago:write', 'pms_pago:patch'])]
    private ?string $comisionPorcentaje = null;

    #[ORM\Column(name: 'fecha_pago', type: 'date')]
    #[Groups(['pms_pago:read', 'pms_pago:write', 'pms_pago:patch'])]
    private ?DateTimeInterface $fechaPago = null;

    /** Referencia externa (nº de operación de Western Union, transferencia, etc.). */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Groups(['pms_pago:read', 'pms_pago:write', 'pms_pago:patch'])]
    private ?string $referencia = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['pms_pago:read', 'pms_pago:write', 'pms_pago:patch'])]
    private ?string $notas = null;

    /**
     * ¿Es el depósito que genera el sistema para las OTA de pago total (§12.8)?
     *
     * Airbnb y VRBO cobran al huésped y nos depositan: no hay un pago que registrar a mano, y
     * sin él la reserva aparecía eternamente como impagada. El sistema lo crea y lo mantiene
     * cuadrado con los cargos.
     *
     * La marca existe para poder ENCONTRARLO después sin adivinar por medio de pago o
     * referencia. Y se apaga sola en cuanto el operador toca el importe, la fecha o el medio:
     * a partir de ahí manda su criterio y el sistema deja de pisarlo.
     */
    #[ORM\Column(name: 'es_automatico', type: 'boolean', options: ['default' => false])]
    #[Groups(['pms_pago:read'])]
    private bool $esAutomatico = false;

    /**
     * Quién RECIBIÓ el dinero de manos del huésped.
     *
     * ⚠️ No es quien registró el pago en el sistema: el efectivo lo cobra la persona que está
     * en la casita —la limpiadora, el de mantenimiento— y lo apunta después otra, o el propio
     * agente por chat. Confundirlos haría que todo el efectivo figurase a nombre del operador
     * de recepción, que es justo lo que impide cuadrar la caja.
     *
     * NULL a propósito en dos casos legítimos: los pagos anteriores a este campo, y los
     * depósitos automáticos de las OTA (`esAutomatico`), donde no hay nadie que cobre.
     *
     * ⚠️ SIN grupos de serialización, y es deliberado: `User` no es un `ApiResource`, así que
     * API Platform no sabe convertirlo ni a IRI al leer ni desde IRI al escribir. Con los
     * grupos puestos, el JSON traía un `cobrador: {}` vacío y el campo era **imposible de
     * asignar** desde la SPA. Para eso están `getCobradorId()` / `setCobradorId()`, que
     * hablan en UUID plano — el mismo que sirve `/tipo/user/enum/pms/cobradores`.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'cobrador_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $cobrador = null;

    /**
     * UUID del cobrador tal como llega en el payload, ANTES de resolverse a un `User`.
     *
     * Virtual (no mapeado): sólo es el buzón donde el denormalizador deja el id para que
     * {@see \App\Pms\ApiPlatform\State\PmsPagoFinancieroProcessor} lo cambie por la entidad.
     * La entidad no puede resolverlo sola porque no tiene acceso al EntityManager.
     */
    private ?string $cobradorIdEntrante = null;

    /** ¿Vino `cobradorId` en el payload? Distingue «no lo mandaron» de «lo mandaron vacío». */
    private bool $cobradorIdRecibido = false;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    // =========================================================================
    // GETTERS Y SETTERS
    // =========================================================================

    #[Groups(['pms_pago:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getInformacionFinanciera(): ?PmsInformacionFinanciera { return $this->informacionFinanciera; }
    public function setInformacionFinanciera(?PmsInformacionFinanciera $info): self { $this->informacionFinanciera = $info; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    public function getMonto(): string { return $this->monto; }
    public function setMonto(string $monto): self { $this->monto = $monto; return $this; }

    public function getTipoCambio(): ?string { return $this->tipoCambio; }
    public function setTipoCambio(?string $tipoCambio): self { $this->tipoCambio = $tipoCambio; return $this; }

    public function getMedioPago(): PmsMedioPago { return $this->medioPago; }
    public function setMedioPago(PmsMedioPago $medioPago): self { $this->medioPago = $medioPago; return $this; }

    public function getComisionPorcentaje(): ?string { return $this->comisionPorcentaje; }
    public function setComisionPorcentaje(?string $porcentaje): self { $this->comisionPorcentaje = $porcentaje; return $this; }

    /**
     * Importe del recargo: `monto × porcentaje / 100`. Derivado, nunca se guarda —
     * así no puede quedar desfasado si se corrige el monto.
     */
    #[Groups(['pms_pago:read'])]
    public function getMontoComision(): string
    {
        $pct = (float) ($this->comisionPorcentaje ?? '0');
        return number_format((float) $this->monto * $pct / 100, 2, '.', '');
    }

    /**
     * Lo que se le cobra REALMENTE al huésped: neto + recargo. Es la cifra que se pasa por
     * la tarjeta; `monto` (el neto) es la que abona su estancia.
     */
    #[Groups(['pms_pago:read'])]
    public function getMontoTotalCobrado(): string
    {
        return number_format((float) $this->monto + (float) $this->getMontoComision(), 2, '.', '');
    }

    public function getFechaPago(): ?DateTimeInterface { return $this->fechaPago; }
    public function setFechaPago(?DateTimeInterface $fechaPago): self { $this->fechaPago = $fechaPago; return $this; }

    public function getReferencia(): ?string { return $this->referencia; }
    public function setReferencia(?string $referencia): self { $this->referencia = $referencia; return $this; }

    public function getNotas(): ?string { return $this->notas; }
    public function setNotas(?string $notas): self { $this->notas = $notas; return $this; }

    /**
     * Por qué NO se puede borrar este pago, o null si sí se puede.
     *
     * Fuente única de la regla: la usa el listener de coherencia para vetar el borrado y la
     * SPA para no ofrecer un basurero que sólo puede fallar. Antes la condición vivía sólo
     * dentro del listener, así que el frontend pintaba el botón, el operador lo pulsaba y se
     * llevaba un error — el sistema ofreciendo una acción que él mismo iba a rechazar.
     *
     * Mismo patrón que `PmsEventoCalendario::getMotivoNoBorrable()`.
     */
    public function getMotivoNoBorrable(): ?string
    {
        // Ojo: `esAutomatico` NO es "lo creó el sistema", es "el sistema lo REGENERA solo".
        // Marca el depósito espejo de las OTA de pago total (§12.8). Un cobro por pasarela
        // también lo crea el sistema y sí es borrable, justamente porque no reaparece.
        if ($this->esAutomatico) {
            return 'Es el depósito automático del canal: se regenera solo mientras la reserva '
                . 'tenga cargos. Para que desaparezca, quita los cargos.';
        }

        return null;
    }

    /** Se serializa para que la SPA decida si pinta el basurero. */
    #[Groups(['pms_pago:read'])]
    public function isBorrable(): bool
    {
        return null === $this->getMotivoNoBorrable();
    }

    /** El motivo viaja al frontend para poder explicarlo en el tooltip, no sólo ocultarlo. */
    #[Groups(['pms_pago:read'])]
    public function getMotivoNoBorrableTexto(): ?string
    {
        return $this->getMotivoNoBorrable();
    }

    public function isEsAutomatico(): bool { return $this->esAutomatico; }
    public function setEsAutomatico(bool $esAutomatico): self { $this->esAutomatico = $esAutomatico; return $this; }

    public function getCobrador(): ?User { return $this->cobrador; }
    public function setCobrador(?User $cobrador): self { $this->cobrador = $cobrador; return $this; }

    /**
     * El cobrador en UUID plano: lo que la SPA lee para preseleccionar el desplegable y lo
     * que manda al guardar. Ver la nota de `$cobrador` sobre por qué no viaja la relación.
     */
    #[Groups(['pms_pago:read', 'pms_pago:write', 'pms_pago:patch'])]
    public function getCobradorId(): ?string
    {
        return $this->cobrador?->getId()?->__toString();
    }

    /**
     * Guarda el UUID recibido; quien lo convierte en `User` es el processor.
     *
     * Cadena vacía y `null` significan lo mismo —«sin cobrador»— porque un `<select>` con la
     * opción en blanco manda `''`, y esa es la forma de DESASIGNAR a quien se puso por error.
     */
    #[Groups(['pms_pago:write', 'pms_pago:patch'])]
    public function setCobradorId(?string $cobradorId): self
    {
        $cobradorId = trim((string) $cobradorId);

        $this->cobradorIdEntrante = $cobradorId === '' ? null : $cobradorId;
        $this->cobradorIdRecibido = true;

        return $this;
    }

    /** @internal Lo consume el processor; no forma parte del contrato de la entidad. */
    public function getCobradorIdEntrante(): ?string { return $this->cobradorIdEntrante; }

    /**
     * @internal Un PATCH que no menciona `cobradorId` no debe borrar el que ya estaba: sin
     * esta marca, el processor no podría distinguirlo de un PATCH que lo manda vacío aposta.
     */
    public function isCobradorIdRecibido(): bool { return $this->cobradorIdRecibido; }

    /**
     * Nombre del cobrador listo para pintar, sin arrastrar la entidad `User` al JSON.
     *
     * Se serializa aparte de la relación porque el panel y el agente sólo necesitan el
     * nombre: exponer el `User` entero metería email y roles en una respuesta que se
     * consulta desde el chat.
     */
    #[Groups(['pms_pago:read'])]
    public function getCobradorNombre(): ?string
    {
        return $this->cobrador?->getFullname() ?: null;
    }
}
