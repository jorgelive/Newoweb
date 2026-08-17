<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Cotizacion\Entity\CotizacionFile;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),
        new Get(
            security: "is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear órdenes de servicio.'
        ),
        new Put(
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para editar órdenes de servicio.'
        ),
        new Patch(
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para actualizar órdenes de servicio.'
        ),
        new Delete(
            security: "is_granted('" . Roles::OPERACIONES_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar órdenes de servicio.'
        ),
    ],
    routePrefix: '/ops',
    normalizationContext: ['groups' => ['operacion:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['operacion:write']]
)]
#[ORM\Entity]
#[ORM\Table(name: 'operacion_orden_servicio')]
#[ORM\HasLifecycleCallbacks]
class OperacionOrdenServicio
{
    use IdTrait;
    use TimestampTrait;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $numeroOs;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CotizacionFile $file = null;

    // A quién va dirigida la orden: el COMPRADOR, no quien pone el precio.
    //
    // Se llamaba `proveedor*` y era la misma idea con el nombre equivocado — la propia doc
    // decía «a quién le mando la solicitud y le pago», que es la definición del comprador.
    // El nombre viejo hacía creer que la orden iba a quien vende, y eso falla en cuanto la
    // tarifa es de un consorcio al que nadie escribe: le encargas a Futurismo que compre
    // las entradas, y la orden tiene que salir a nombre de Futurismo.
    //
    // Siempre un `Proveedor` del catálogo, también los internos. Ver docs/Cotizaciones.md §6.c.
    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $compradorMaestroId = null;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $compradorNombre = null;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: EstadoOrdenServicioEnum::class, options: ['default' => 'borrador'])]
    private EstadoOrdenServicioEnum $estadoOs = EstadoOrdenServicioEnum::BORRADOR;

    /**
     * El importe de la orden, a nivel de cabecera. **Opcional, y ya no se pide al crearla.**
     *
     * Era obligatorio y con una sola moneda, y eso obligaba a que toda la orden fuese de una
     * moneda: la guarda decía «mezclar monedas deja un total que no suma». El problema no era
     * mezclar — es que el importe estaba en el sitio equivocado. Un proveedor cobra unos
     * servicios en soles y otros en dólares, y partir eso en dos órdenes es partir una gestión
     * que en la vida real es una sola.
     *
     * El dinero vive AHORA en cada ítem, que es donde tiene sentido: `costoCotizado` con su
     * moneda (lo que dijo el cotizador, referencial) y `costoRealOperativo` con la suya (lo
     * que se negoció, editable). La pantalla suma POR MONEDA a partir de ahí.
     *
     * Estos dos campos se conservan sólo como apunte manual de conciliación —al proveedor no
     * se le manda un total— y por eso pasan a ser nulos: un `0.00` obligatorio se lee como un
     * importe, y era mentira.
     */
    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_os', referencedColumnName: 'id', nullable: true)]
    private ?MaestroMoneda $monedaOs = null;

    #[Groups(['operacion:read', 'operacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $totalOs = null;

    /**
     * @var Collection<int, OperacionServicio>
     */
    /**
     * ⚠️ SIN `cascade: remove` ni `orphanRemoval`, y es deliberado.
     *
     * Los tenía, y convertían un gesto reversible en destrucción: borrar una OS
     * equivocada para rehacerla se llevaba por delante las filas del cuadro de tráfico
     * con todo lo que el operador había escrito a mano —hora pactada por teléfono,
     * prestador, costo real, estados de reserva—. Una OS es un documento de compra que
     * agrupa filas; las filas no le pertenecen, existen antes y después de ella.
     *
     * Ahora borrar la OS sólo desasocia (`onDelete: 'SET NULL'` en el lado propietario),
     * que es lo que la documentación decía desde el principio y el mapeo desmentía.
     */
    /** @var Collection<int, OperacionServicio> */
    #[Groups(['operacion:read'])]
    #[ORM\OneToMany(mappedBy: 'ordenServicio', targetEntity: OperacionServicio::class)]
    private Collection $operacionServicios;

    /**
     * @var Collection<int, OperacionMensaje>
     */
    #[Groups(['operacion:read'])]
    #[ORM\OneToMany(mappedBy: 'ordenServicio', targetEntity: OperacionMensaje::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $mensajes;

    public function __construct()
    {
        $this->initializeId();
        $this->operacionServicios = new ArrayCollection();
        $this->mensajes = new ArrayCollection();
    }

    #[Groups(['operacion:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['operacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    public function getNumeroOs(): string { return $this->numeroOs; }
    public function setNumeroOs(string $numeroOs): self { $this->numeroOs = $numeroOs; return $this; }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    public function getCompradorMaestroId(): ?string { return $this->compradorMaestroId; }
    public function setCompradorMaestroId(?string $v): self { $this->compradorMaestroId = $v; return $this; }

    public function getCompradorNombre(): ?string { return $this->compradorNombre; }
    public function setCompradorNombre(?string $v): self { $this->compradorNombre = $v; return $this; }

    public function getEstadoOs(): EstadoOrdenServicioEnum { return $this->estadoOs; }
    public function setEstadoOs(EstadoOrdenServicioEnum $estadoOs): self { $this->estadoOs = $estadoOs; return $this; }

    public function getMonedaOs(): ?MaestroMoneda { return $this->monedaOs; }
    public function setMonedaOs(?MaestroMoneda $monedaOs): self { $this->monedaOs = $monedaOs; return $this; }

    public function getTotalOs(): ?string { return $this->totalOs; }
    public function setTotalOs(?string $totalOs): self { $this->totalOs = $totalOs; return $this; }

    /**
     * Lo que suma la orden, POR MONEDA y sin convertir.
     *
     * Se calcula aquí y no en el front porque los ítems viajan como IRI en el listado: la
     * pantalla no tiene los importes para sumarlos, y engordar el listado con cada servicio
     * entero sería pagar el payload de todos para pintar dos números.
     *
     * Dos columnas por moneda, que son los dos hechos que conviven en una orden:
     *   · `cotizado` — lo que dijo el cotizador. Referencial, no se toca.
     *   · `real`     — lo que se negoció. Cae al cotizado mientras nadie lo cambie, para que
     *                  una orden recién creada no muestre ceros donde hay importes.
     *
     * ⚠️ NO se convierte ni se totaliza en una sola cifra. Una orden puede llevar servicios en
     * soles y en dólares —es una sola gestión con el mismo proveedor— y reducirlo a un número
     * exigiría un tipo de cambio que nadie pactó. Ver docs/Operacion.md §5.4.
     *
     * @return list<array{moneda: string, cotizado: string, real: string}>
     */
    #[Groups(['operacion:read'])]
    #[ApiProperty(openapiContext: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'moneda'   => ['type' => 'string'],
                'cotizado' => ['type' => 'string'],
                'real'     => ['type' => 'string'],
            ],
        ],
    ])]
    public function getTotalesPorMoneda(): array
    {
        /** @var array<string, array{cotizado: float, real: float}> $acumulado */
        $acumulado = [];

        foreach ($this->operacionServicios as $servicio) {
            $cotizado = (float) $servicio->getCostoCotizado();
            $real     = (float) $servicio->getCostoRealOperativo();

            $monedaCotizada = $servicio->getMonedaCotizada()?->getId() ?? '—';
            // La moneda de lo negociado puede ser OTRA: se cotiza en dólares y se cierra en
            // soles. Sin este reparto, ese importe se sumaría bajo la moneda equivocada.
            $monedaReal = $servicio->getMonedaReal()?->getId() ?? $monedaCotizada;

            $acumulado[$monedaCotizada] ??= ['cotizado' => 0.0, 'real' => 0.0];
            $acumulado[$monedaCotizada]['cotizado'] += $cotizado;

            $acumulado[$monedaReal] ??= ['cotizado' => 0.0, 'real' => 0.0];
            // Mientras nadie negocie, «real» es el cotizado: un cero ahí se leería como
            // «pactado en cero», que es lo contrario de «todavía sin pactar».
            $acumulado[$monedaReal]['real'] += $real > 0.0 ? $real : ($monedaReal === $monedaCotizada ? $cotizado : 0.0);
        }

        ksort($acumulado);

        $salida = [];
        foreach ($acumulado as $moneda => $montos) {
            $salida[] = [
                'moneda'   => (string) $moneda,
                'cotizado' => number_format($montos['cotizado'], 2, '.', ''),
                'real'     => number_format($montos['real'], 2, '.', ''),
            ];
        }

        return $salida;
    }

    public function getOperacionServicios(): Collection { return $this->operacionServicios; }

    public function addOperacionServicio(OperacionServicio $operacionServicio): self
    {
        if (!$this->operacionServicios->contains($operacionServicio)) {
            $this->operacionServicios->add($operacionServicio);
            $operacionServicio->setOrdenServicio($this);
        }
        return $this;
    }

    public function removeOperacionServicio(OperacionServicio $operacionServicio): self
    {
        if ($this->operacionServicios->removeElement($operacionServicio)) {
            if ($operacionServicio->getOrdenServicio() === $this) {
                $operacionServicio->setOrdenServicio(null);
            }
        }
        return $this;
    }

    public function getMensajes(): Collection { return $this->mensajes; }

    public function addMensaje(OperacionMensaje $mensaje): self
    {
        if (!$this->mensajes->contains($mensaje)) {
            $this->mensajes->add($mensaje);
            $mensaje->setOrdenServicio($this);
        }
        return $this;
    }

    public function removeMensaje(OperacionMensaje $mensaje): self
    {
        if ($this->mensajes->removeElement($mensaje)) {
            if ($mensaje->getOrdenServicio() === $this) {
                $mensaje->setOrdenServicio(null);
            }
        }
        return $this;
    }
}
