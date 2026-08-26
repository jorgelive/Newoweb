<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Api\Filter\UuidRelacionFilter;
use App\Entity\Trait\IdTrait;
use App\Security\Roles;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * El historial de estados de un servicio de La Biblia. Una línea por cambio.
 *
 * ── Por qué una entidad y no un campo ───────────────────────────────────────
 * «Desde cuándo está confirmado» y «¿cuándo se solicitó?» no caben en el propio servicio: son
 * una serie temporal. Guardarlo aparte deja el servicio con SÓLO el estado actual —lo que se
 * lee de un vistazo— y la historia completa a un clic, sin engordar cada fila del cuadro.
 *
 * ── Se escribe en un listener, no a mano ────────────────────────────────────
 * Nadie crea estas filas: las genera {@see \App\Operacion\EventListener\EstadoBitacoraListener}
 * al detectar que un `estadoReservaProveedor` o un `estadoOperacion` cambió. Un cambio que no
 * pasa por el listener —un UPDATE en SQL— no deja rastro, y ésa es exactamente la razón de que
 * las correcciones de estado deban ir por el ORM.
 *
 * ── Sólo lectura por la API ─────────────────────────────────────────────────
 * No hay POST: el registro es un hecho ocurrido, no algo que el operador redacte. Se lista
 * filtrando por servicio para pintar su historial.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),
    ],
    routePrefix: '/ops',
    normalizationContext: ['groups' => ['operacion:bitacora:read', 'timestamp:read']],
    order: ['createdAt' => 'DESC'],
    paginationEnabled: false,
)]
// `operacionServicio` es una relación y con `SearchFilter` no casaba nunca; `campo` es texto
// y ahí `SearchFilter` va bien. Ver el docblock de `UuidRelacionFilter`.
#[ApiFilter(UuidRelacionFilter::class, properties: ['operacionServicio' => 'exact'])]
#[ApiFilter(SearchFilter::class, properties: ['campo' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
#[ORM\Entity]
#[ORM\Table(name: 'operacion_estado_bitacora')]
#[ORM\Index(columns: ['operacion_servicio_id', 'campo'], name: 'idx_bitacora_servicio_campo')]
class OperacionEstadoBitacora
{
    use IdTrait;

    /** Qué estado cambió: `reserva` (con el proveedor) u `operacion` (el servicio en sí). */
    public const string CAMPO_RESERVA = 'reserva';
    public const string CAMPO_OPERACION = 'operacion';

    #[ORM\ManyToOne(targetEntity: OperacionServicio::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OperacionServicio $operacionServicio = null;

    #[Groups(['operacion:bitacora:read'])]
    #[ORM\Column(type: 'string', length: 20)]
    private string $campo;

    /** El estado del que venía. `null` en el primer registro: antes no había nada. */
    #[Groups(['operacion:bitacora:read'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $valorAnterior = null;

    #[Groups(['operacion:bitacora:read'])]
    #[ORM\Column(type: 'string', length: 30)]
    private string $valorNuevo;

    /**
     * Quién lo cambió, como uuid en texto. Nulo si no había sesión (un comando, la
     * reconciliación): el hecho ocurrió igual, sólo que no lo firmó una persona.
     */
    #[Groups(['operacion:bitacora:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $usuarioId = null;

    /** El nombre para pintar, resuelto al escribir: el uuid no se le enseña a nadie. */
    #[Groups(['operacion:bitacora:read'])]
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $usuarioNombre = null;

    #[Groups(['operacion:bitacora:read'])]
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->initializeId();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getOperacionServicio(): ?OperacionServicio { return $this->operacionServicio; }
    public function setOperacionServicio(?OperacionServicio $s): self { $this->operacionServicio = $s; return $this; }

    public function getCampo(): string { return $this->campo; }
    public function setCampo(string $campo): self { $this->campo = $campo; return $this; }

    public function getValorAnterior(): ?string { return $this->valorAnterior; }
    public function setValorAnterior(?string $v): self { $this->valorAnterior = $v; return $this; }

    public function getValorNuevo(): string { return $this->valorNuevo; }
    public function setValorNuevo(string $v): self { $this->valorNuevo = $v; return $this; }

    public function getUsuarioId(): ?string { return $this->usuarioId; }
    public function setUsuarioId(?string $id): self { $this->usuarioId = $id; return $this; }

    public function getUsuarioNombre(): ?string { return $this->usuarioNombre; }
    public function setUsuarioNombre(?string $n): self { $this->usuarioNombre = $n; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
