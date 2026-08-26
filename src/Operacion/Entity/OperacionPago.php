<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use App\Api\Filter\UuidRelacionFilter;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Operacion\Enum\OperacionMedioPago;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un pago a cuenta hecho al proveedor por una Orden de Servicio.
 *
 * ── Para qué ────────────────────────────────────────────────────────────────
 * El operador va abonando al proveedor en partes; esto lleva la cuenta. El saldo no se guarda:
 * se calcula (negociado − Σ pagos) en `OperacionOrdenServicio::getTotalesPorMoneda()`, por
 * moneda. Guardar un saldo sería un dato que hay que mantener a mano y que se desincroniza en
 * cuanto se añade o borra un pago.
 *
 * ── Una moneda, y tiene que ser la de la orden ──────────────────────────────
 * El pago va en la moneda en que se cerró el servicio. No se convierte —criterio de la casa—
 * así que cada pago se resta del saldo de SU moneda.
 *
 * ⚠️ Y **se comprueba aquí**, no sólo en el panel: ver `validarMonedaDeLaOrden()`.
 *
 * ── Se puede borrar, no editar ──────────────────────────────────────────────
 * Un pago mal metido se borra y se rehace: es un hecho puntual, no un documento que se
 * corrige. Sin PUT/PATCH — editar el monto de un pago ya registrado invita a cuadrar la caja
 * cambiando la historia.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para registrar pagos.'
        ),
        new Delete(
            security: "is_granted('" . Roles::OPERACIONES_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar pagos.'
        ),
    ],
    routePrefix: '/ops',
    normalizationContext: ['groups' => ['operacion:pago:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['operacion:pago:write']],
    order: ['fecha' => 'DESC'],
    paginationEnabled: false,
)]
// Relación: va por `UuidRelacionFilter`. Con `SearchFilter` devolvía cero siempre.
#[ApiFilter(UuidRelacionFilter::class, properties: ['ordenServicio' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['fecha'])]
#[ORM\Entity]
// ⚠️ Sin `HasLifecycleCallbacks` el `#[PrePersist]` de `TimestampTrait` NO corre, y el INSERT
// muere con «Column 'created_at' cannot be null». Es el segundo tropiezo de la misma omisión
// —ver el constructor—: usar los traits no basta, hay que darles el gancho.
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'operacion_pago')]
#[ORM\Index(columns: ['orden_servicio_id'], name: 'idx_pago_orden')]
class OperacionPago
{
    use IdTrait;
    use TimestampTrait;

    /**
     * ⚠️ **Sin esto, un pago NO SE PUEDE GUARDAR.**
     *
     * `IdTrait` declara la clave con `GeneratedValue(strategy: 'NONE')`: el UUID lo pone la
     * aplicación, no la base. Quien no llama a `initializeId()` revienta en el `persist()` con
     * `EntityMissingAssignedId`, que no menciona ni al constructor ni al trait.
     *
     * Esta entidad nació sin constructor el 17/08/2026, así que **registrar un pago falló desde
     * el primer día**. No se notó porque el panel se comía el error —`crearPago()` devolvía un
     * booleano y pintaba «No se pudo registrar el pago»— y porque nadie llegó a insistir: la
     * tabla estaba vacía el 21/08. Lo cazó la sonda contra datos reales, que es lo único que
     * ejecuta el `persist()` de verdad; ni PHPStan, ni el contenedor, ni los tests unitarios lo
     * ven.
     *
     * El resto de entidades del módulo sí lo hacen ({@see OperacionOrdenServicio::__construct()}),
     * y también llevan `#[ORM\HasLifecycleCallbacks]`, que es la otra mitad de la misma omisión:
     * sin él, el `#[PrePersist]` de `TimestampTrait` tampoco corre y el INSERT muere por
     * `created_at`. Los dos fallos estaban encadenados y sólo se ven ejecutando un `flush()`.
     */
    public function __construct()
    {
        $this->initializeId();
    }

    #[Assert\NotNull(message: 'El pago tiene que pertenecer a una orden.')]
    #[Groups(['operacion:pago:read', 'operacion:pago:write'])]
    #[ORM\ManyToOne(targetEntity: OperacionOrdenServicio::class, inversedBy: 'pagos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OperacionOrdenServicio $ordenServicio = null;

    #[Assert\NotBlank(message: 'El monto es obligatorio.')]
    #[Assert\Positive(message: 'El monto tiene que ser mayor que cero.')]
    #[Groups(['operacion:pago:read', 'operacion:pago:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $monto = '0.00';

    #[Assert\NotNull(message: 'El pago tiene que llevar moneda.')]
    #[Groups(['operacion:pago:read', 'operacion:pago:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $moneda = null;

    #[Assert\NotNull(message: 'La fecha del pago es obligatoria.')]
    #[Groups(['operacion:pago:read', 'operacion:pago:write'])]
    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $fecha = null;

    /**
     * Por qué medio se le pagó.
     *
     * Obligatorio en la base y no sólo en la validación: la tabla estaba **vacía** al añadir el
     * campo —comprobado, 0 filas—, así que no había ningún pago viejo al que respetarle un «no
     * consta». Permitir `NULL` sin filas que lo necesiten sólo habría metido una rama de dato
     * ausente en la entidad, en el panel y en el desglose, para un caso que no existe.
     *
     * La propiedad sí es `?`: hasta que alguien la elige, no hay valor. Es lo mismo que hacen
     * `$moneda` y `$fecha` aquí al lado.
     */
    #[Assert\NotNull(message: 'Elige por qué medio se pagó.')]
    #[Groups(['operacion:pago:read', 'operacion:pago:write'])]
    #[ORM\Column(name: 'medio_pago', type: 'string', length: 30, enumType: OperacionMedioPago::class)]
    private ?OperacionMedioPago $medioPago = null;

    #[Groups(['operacion:pago:read', 'operacion:pago:write'])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notas = null;

    /** Quién lo registró, resuelto a nombre al guardar. Ver `OperacionPagoListener`. */
    #[Groups(['operacion:pago:read'])]
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $usuarioNombre = null;

    public function getOrdenServicio(): ?OperacionOrdenServicio { return $this->ordenServicio; }
    public function setOrdenServicio(?OperacionOrdenServicio $o): self { $this->ordenServicio = $o; return $this; }

    public function getMonto(): string { return $this->monto; }
    public function setMonto(string $monto): self { $this->monto = $monto; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    public function getFecha(): ?\DateTimeImmutable { return $this->fecha; }
    public function setFecha(?\DateTimeImmutable $fecha): self { $this->fecha = $fecha; return $this; }

    public function getNotas(): ?string { return $this->notas; }
    public function setNotas(?string $notas): self { $this->notas = $notas; return $this; }

    public function getMedioPago(): ?OperacionMedioPago { return $this->medioPago; }
    public function setMedioPago(?OperacionMedioPago $m): self { $this->medioPago = $m; return $this; }

    /** La etiqueta legible, para no duplicar el diccionario en el panel. */
    #[Groups(['operacion:pago:read'])]
    public function getMedioPagoLabel(): ?string { return $this->medioPago?->label(); }

    public function getUsuarioNombre(): ?string { return $this->usuarioNombre; }
    public function setUsuarioNombre(?string $n): self { $this->usuarioNombre = $n; return $this; }

    /**
     * ⚠️ **Sólo se paga en una moneda que la orden tenga.**
     *
     * La regla estaba **sólo en el panel** —el `<select>` se armaba con las monedas de la
     * orden— y una regla que sólo vive en el front no es una regla: cualquier POST directo
     * metía un abono en yenes, y como el saldo se agrupa por moneda, ese pago aparecía como una
     * fila nueva con `pagado` y sin `real`. O sea: dinero declarado como salido contra una deuda
     * que no existe, sin un solo error.
     *
     * Las monedas admitidas salen de los SERVICIOS —lo cotizado y lo negociado—, no de
     * `getTotalesPorMoneda()`, que incluye las monedas de los pagos ya hechos: validarse contra
     * eso haría que el primer pago equivocado legitimara al segundo.
     *
     * Si la orden todavía no tiene ninguna moneda —costos sin cargar— no hay regla que cumplir
     * y se deja pasar. Negarlo bloquearía el adelanto de una orden recién emitida, que es
     * justo cuando más se adelanta.
     */
    #[Assert\Callback]
    public function validarMonedaDeLaOrden(ExecutionContextInterface $contexto): void
    {
        $moneda = $this->moneda?->getId();
        $admitidas = $this->ordenServicio?->monedasDeLosServicios() ?? [];

        if ($moneda === null || $admitidas === [] || in_array($moneda, $admitidas, true)) {
            return;
        }

        $contexto->buildViolation(sprintf(
            'Esta orden se maneja en %s: no se le puede registrar un pago en %s.',
            implode(' y ', $admitidas),
            $moneda
        ))->atPath('moneda')->addViolation();
    }
}
