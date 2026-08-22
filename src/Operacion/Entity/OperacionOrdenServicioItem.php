<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Una línea CONGELADA de una Orden de Servicio: lo que el documento pidió.
 *
 * ## Por qué existe
 *
 * Hasta ahora la Orden no tenía contenido propio: sus ítems eran las filas vivas de La Biblia
 * enlazadas por `orden_servicio_id`. Eso dejaba una contradicción sin salida:
 *
 *     liberas las filas  →  la Orden anulada queda VACÍA
 *     las dejas atadas   →  no se pueden volver a pedir en otra Orden
 *
 * Con la línea congelada las dos cosas son ciertas a la vez. **Anular = soltar el vínculo vivo
 * y conservar el congelado**: la Orden sigue diciendo lo que pidió y la fila queda libre para
 * entrar en la siguiente.
 *
 * Es el mismo movimiento que este código ya hizo dos veces: `CotizacionCottarifa` congela la
 * tarifa y guarda `tarifaMaestraId` como soft-link; `OperacionServicio` congela la cotización y
 * guarda su componente.
 *
 * ## Cuándo se congela
 *
 * Al **EMITIR**, no al crear. Un `borrador` todavía no es un documento: mientras se compone
 * conviene que siga siendo una vista viva, para que corregir un pax en La Biblia se refleje.
 * Ver {@see \App\Operacion\Service\OperacionOrdenEmision}.
 *
 * ## El enlace hacia atrás
 *
 * `operacionServicioId` es un **soft-link** (texto, no relación) a propósito: sobrevive a que
 * la fila se borre y permite preguntar «¿en qué órdenes estuvo este servicio?» aunque el
 * vínculo vivo ya se haya movido a otra. Una relación con `ON DELETE SET NULL` perdería
 * justamente eso.
 */
#[ORM\Entity]
#[ORM\Table(name: 'operacion_orden_servicio_item')]
#[ORM\HasLifecycleCallbacks]
class OperacionOrdenServicioItem
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: OperacionOrdenServicio::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OperacionOrdenServicio $orden = null;

    /** Soft-link a la fila de La Biblia. Ver el docblock de la clase. */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $operacionServicioId = null;

    // ─────────────────────────────────────────────────────────────────────────
    // LO QUE DIJO EL DOCUMENTO — nada de esto se vuelve a tocar
    // ─────────────────────────────────────────────────────────────────────────

    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 255)]
    private string $descripcion = '';

    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $fechaServicio = null;

    /** Tal y como se pidió: «08:30», o nulo si la Orden no fijaba hora. */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $hora = null;

    /**
     * La hora de recojo **ya confirmada por el proveedor** cuando se emitió, o nula.
     *
     * ⚠️ Se guarda aparte de `$hora` porque distingue dos cosas que se parecen y no lo son:
     *
     *     estaba NULA y ahora hay hora   →  el proveedor CONFIRMÓ. Cambio menor.
     *     estaba puesta y ahora es otra  →  MODIFICACIÓN. Hay que reemitir y avisar.
     *
     * Cuando le pides un servicio a un proveedor, la hora te la dice él al confirmar: que
     * aparezca es el final normal del flujo, no un descuido de nadie. Tratarlo como cambio
     * sucio obligaría a reemitir cada orden que sale bien.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $horaRecojoConfirmada = null;

    /**
     * Dónde se recoge y dónde se deja, **congelados al emitir**.
     *
     * No se leen en vivo del catálogo, y es la razón por la que existen estas dos columnas: el
     * documento se construye desde los datos congelados del ítem, así que un punto vivo haría que
     * el proveedor abriera el enlace público la semana siguiente y viera un sitio **distinto del
     * que se le mandó**. Un documento emitido dice lo que decía cuando se emitió; si el catálogo
     * cambia, se reemite y se avisa — que es exactamente lo que ya hace el importe.
     *
     * Nulos cuando el servicio no recoge a nadie (un ticket, una comida) o cuando al emitir aún
     * no se sabía. Nulo no es «en el hotel»: es que no consta, y así sale en el documento.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $puntoRecojoConfirmado = null;

    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $puntoEntregaConfirmado = null;

    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cantidadPax = null;

    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $cantidad = null;

    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $importe = '0.00';

    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MaestroMoneda $moneda = null;

    /** Quién presta, por NOMBRE: el documento no depende de que la ficha siga existiendo. */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $prestadorNombre = null;

    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $prestadorServicioNombre = null;

    public function __construct()
    {
        $this->initializeId();
    }

    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getOrden(): ?OperacionOrdenServicio { return $this->orden; }
    public function setOrden(?OperacionOrdenServicio $v): self { $this->orden = $v; return $this; }

    public function getOperacionServicioId(): ?string { return $this->operacionServicioId; }
    public function setOperacionServicioId(?string $v): self { $this->operacionServicioId = $v; return $this; }

    public function getDescripcion(): string { return $this->descripcion; }
    public function setDescripcion(string $v): self { $this->descripcion = $v; return $this; }

    public function getFechaServicio(): ?DateTimeInterface { return $this->fechaServicio; }
    public function setFechaServicio(?DateTimeInterface $v): self { $this->fechaServicio = $v; return $this; }

    public function getHora(): ?string { return $this->hora; }
    public function setHora(?string $v): self { $this->hora = $v; return $this; }

    public function getHoraRecojoConfirmada(): ?string { return $this->horaRecojoConfirmada; }
    public function setHoraRecojoConfirmada(?string $v): self { $this->horaRecojoConfirmada = $v; return $this; }


    /**
     * «Recoge en X → deja en Y», o `null` si no consta ninguno de los dos.
     *
     * Vive aquí y no en {@see \App\Operacion\Service\OperacionOrdenDocumento} porque lo pintan
     * **dos** superficies —el mensaje al proveedor y la página pública con su PDF— y son el mismo
     * documento visto de dos formas. Escrito dos veces, el día que cambie la redacción cambiará en
     * una sola y nadie lo notará hasta que un proveedor compare lo que le llegó con lo que ve al
     * abrir el enlace.
     *
     * ⚠️ **Si los dos son el mismo sitio se dice UNA vez.** Repetirlo enseña a no leerlo, que es
     * exactamente lo contrario de lo que hace falta el día que sean distintos — la misma razón por
     * la que la hora de recojo sólo sale cuando difiere de la del servicio.
     *
     * ⚠️ **Un punto ausente no se rellena.** Nada de «Recoge en —»: un guion invita a suponer que
     * es el hotel. Callarlo deja claro que hay que preguntarlo, que es la verdad.
     */
    public function rutaParaLaOrden(): ?string
    {
        $recojo = trim((string) $this->puntoRecojoConfirmado);
        $entrega = trim((string) $this->puntoEntregaConfirmado);

        return match (true) {
            $recojo !== '' && $entrega !== '' && $recojo !== $entrega => sprintf('Recoge en %s → deja en %s', $recojo, $entrega),
            $recojo !== '' && $entrega !== '' => sprintf('Recoge y deja en %s', $recojo),
            $recojo !== '' => sprintf('Recoge en %s', $recojo),
            $entrega !== '' => sprintf('Deja en %s', $entrega),
            default => null,
        };
    }

    public function getPuntoRecojoConfirmado(): ?string { return $this->puntoRecojoConfirmado; }
    public function setPuntoRecojoConfirmado(?string $v): self { $this->puntoRecojoConfirmado = $v; return $this; }

    public function getPuntoEntregaConfirmado(): ?string { return $this->puntoEntregaConfirmado; }
    public function setPuntoEntregaConfirmado(?string $v): self { $this->puntoEntregaConfirmado = $v; return $this; }

    public function getCantidadPax(): ?int { return $this->cantidadPax; }
    public function setCantidadPax(?int $v): self { $this->cantidadPax = $v; return $this; }

    public function getCantidad(): ?string { return $this->cantidad; }
    public function setCantidad(?string $v): self { $this->cantidad = $v; return $this; }

    public function getImporte(): string { return $this->importe; }
    public function setImporte(string $v): self { $this->importe = $v; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $v): self { $this->moneda = $v; return $this; }

    public function getPrestadorNombre(): ?string { return $this->prestadorNombre; }
    public function setPrestadorNombre(?string $v): self { $this->prestadorNombre = $v; return $this; }

    public function getPrestadorServicioNombre(): ?string { return $this->prestadorServicioNombre; }
    public function setPrestadorServicioNombre(?string $v): self { $this->prestadorServicioNombre = $v; return $this; }
}
