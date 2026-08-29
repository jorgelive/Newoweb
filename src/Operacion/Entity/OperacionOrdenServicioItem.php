<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use App\Operacion\Enum\VisibilidadPuntoEnum;
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

    /**
     * La VARIANTE de tarifa: «Auto», «Adulto extranjero», «Base».
     *
     * ⚠️ Durante un tiempo fue lo ÚNICO que llevaba la línea, y por eso a los proveedores les
     * llegaron órdenes que decían «Auto» y «Hotel 4 estrellas por grupo» a secas. Sola no dice
     * qué hay que hacer: acompaña a `nombreComponente`, no lo sustituye.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 255)]
    private string $descripcion = '';

    /**
     * QUÉ hay que hacer: el nombre del componente. Es el encargo, y va en grande.
     *
     * «Transporte desde Estación de Ollantaytambo a Cusco». Nulo sólo en las órdenes emitidas
     * antes de que existiera el campo.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $nombreComponente = null;

    /**
     * CUÁNDO y en qué momento del programa: el nombre del segmento.
     *
     * «Traslado a la estación de Ollantaytambo». Es el dato que el componente NO puede llevar:
     * el origen y el destino viven en el segmento —`inicioPunto`/`finPunto`, y a veces como
     * modo, «acaba en el alojamiento del pasajero»— mientras que `travel_componente` no tiene
     * esas columnas. Lo que el nombre del componente dice de la ruta es prosa duplicada.
     *
     * ⚠️ Hasta el 29/08/2026 se calculaba en La Biblia (`OperacionServicio::$nombreSegmento`) y
     * **no se copiaba aquí**: al proveedor no le llegaba nunca. Por eso el nombre del componente
     * tenía que cargar con la ruta, y de ahí salieron veintinueve tarifas que sólo se
     * diferenciaban en el destino. Nulo en las órdenes emitidas antes de esa fecha que no se
     * hayan rellenado con `app:operacion:rellenar-nombre-segmento`.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $nombreSegmento = null;

    /**
     * DÓNDE encaja: el día del itinerario. Va en pequeño, como referencia.
     *
     * Se congela junto al nombre del componente y **nunca lo sustituye**. Que pudiera ocupar su
     * sitio es lo que hacía que un traslado apareciera como «Full Day HUAYNA: MAPI OLLA CUZ».
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $contextoServicio = null;

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
     * que se le mandó**. Un documento emitido dice lo que decía cuando se emitió.
     *
     * ⚠️ **La vigilancia es PARCIAL, y hay que saberlo.** `OperacionOrdenServicio::getDivergencias()`
     * avisa si el operador corrige el punto después de emitir, pero **no** si cambia el segmento
     * en el catálogo o el hotel en el expediente: volver a derivarlo necesita consultas y eso se
     * pinta por fila. El hueco está en docs/Operacion.md §12.
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

    /**
     * Si el recojo y la entrega de esta línea se le imprimen al proveedor, **en ESTE documento**.
     *
     * Se congela al emitir desde la fila viva, y a partir de ahí manda esto. Se puede cambiar con
     * la orden ya emitida —`POST /ops/orden-servicios/{id}/rutas`— y **eso no obliga a reemitir**:
     * ocultar un renglón no cambia lo que el proveedor tiene que hacer, dice menos. Cambiar el
     * TEXTO de un punto sí sería otra cosa, y ésa sigue pasando por anular y reemitir.
     *
     * ⚠️ Es el mismo tipo de excepción que ya tenía `horaRecojoConfirmada` con
     * {@see OperacionOrdenServicio::aplicarCambiosMenores()}: la regla nunca fue «el ítem no se
     * toca», sino **«el pacto no se toca»**.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(name: 'visibilidad_recojo', type: 'string', length: 12, enumType: VisibilidadPuntoEnum::class, options: ['default' => 'auto'])]
    private VisibilidadPuntoEnum $visibilidadRecojo = VisibilidadPuntoEnum::AUTO;

    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(name: 'visibilidad_entrega', type: 'string', length: 12, enumType: VisibilidadPuntoEnum::class, options: ['default' => 'auto'])]
    private VisibilidadPuntoEnum $visibilidadEntrega = VisibilidadPuntoEnum::AUTO;

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

    /**
     * Lo que se le dijo al proveedor, **congelado**.
     *
     * Sale de {@see OperacionServicio::getNotasPrestadorEfectivas()} al emitir: la redacción del
     * operador si la hay, si no los detalles que la cotización marcó para `prestador`.
     *
     * ⚠️ Copia y no enlace, por lo mismo que los puntos de recojo: leerlo en vivo haría que el
     * proveedor abriera el enlace público la semana siguiente y viera instrucciones DISTINTAS de
     * las que se le mandaron. Un documento emitido dice lo que decía al emitirse.
     *
     * @var list<string>
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'json')]
    private array $notasPrestador = [];

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

    /** @var list<string> */
    private const DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    /** @var list<string> */
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * «Mié 2 sep» — la etiqueta con la que se agrupan las líneas por jornada.
     *
     * Lleva el nombre del día y no sólo el número porque el proveedor cuadra su semana por días:
     * «el miércoles» le dice de un vistazo lo que «02/09/2026» le obliga a calcular. Sin año —una
     * orden no cruza de año— para que quepa en la pantalla de un teléfono.
     *
     * ⚠️ **Vive aquí y no en cada plantilla.** La componen el texto que se le manda
     * ({@see \App\Operacion\Service\OperacionOrdenDocumento}), la página pública y el PDF; con
     * el mapa copiado en Twig, el día que alguien corrija «mié» quedaría corregido en una sola.
     *
     * Los nombres van a mano y no con `IntlDateFormatter`, que es lo que ya decidió `PmsFrentes`:
     * traer intl para doce cadenas cuesta más de lo que ahorra.
     */
    public function getEtiquetaDia(): string
    {
        $fecha = $this->fechaServicio;

        if ($fecha === null) {
            return 'Sin fecha';
        }

        return sprintf(
            '%s %d %s',
            ucfirst(self::DIAS[(int) $fecha->format('w')]),
            (int) $fecha->format('j'),
            self::MESES[(int) $fecha->format('n') - 1],
        );
    }

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
    public function rutaParaLaOrden(bool $conRecojo = true, bool $conEntrega = true): ?string
    {
        // Por lado, porque la cadena decide por lado: el primero enseña su recojo y el último su
        // entrega. Antes esto era todo o nada y obligaba a componer la frase fuera.
        $recojo = $conRecojo ? trim((string) $this->puntoRecojoConfirmado) : '';
        $entrega = $conEntrega ? trim((string) $this->puntoEntregaConfirmado) : '';

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

    public function getVisibilidadRecojo(): VisibilidadPuntoEnum { return $this->visibilidadRecojo; }
    public function setVisibilidadRecojo(VisibilidadPuntoEnum $v): self { $this->visibilidadRecojo = $v; return $this; }

    public function getVisibilidadEntrega(): VisibilidadPuntoEnum { return $this->visibilidadEntrega; }
    public function setVisibilidadEntrega(VisibilidadPuntoEnum $v): self { $this->visibilidadEntrega = $v; return $this; }

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

    /** @return list<string> */
    public function getNotasPrestador(): array { return $this->notasPrestador; }

    /** @param list<string> $notas */
    public function setNotasPrestador(array $notas): self
    {
        $this->notasPrestador = array_values(array_filter(
            array_map(static fn (string $n): string => trim($n), $notas),
            static fn (string $n): bool => $n !== '',
        ));

        return $this;
    }

    public function getNombreComponente(): ?string { return $this->nombreComponente; }
    public function setNombreComponente(?string $v): self { $this->nombreComponente = $v; return $this; }

    public function getNombreSegmento(): ?string { return $this->nombreSegmento; }
    public function setNombreSegmento(?string $v): self { $this->nombreSegmento = $v; return $this; }

    public function getContextoServicio(): ?string { return $this->contextoServicio; }
    public function setContextoServicio(?string $v): self { $this->contextoServicio = $v; return $this; }

    /**
     * El encargo tal y como se lee: el componente, y si no lo hay la variante.
     *
     * Una sola fuente para las TRES superficies —la web, el PDF y el texto de WhatsApp—, que es
     * lo que evita que se arreglen dos y la tercera siga diciendo «Auto».
     */
    public function getTituloParaProveedor(): string
    {
        return trim($this->nombreComponente ?? '') ?: $this->descripcion;
    }

    /**
     * El calificador que acompaña al título, o null si repetiría lo mismo.
     *
     * En los componentes manuales la variante suele ser el mismo texto que el nombre, y
     * «Traslado a la Huacachina — Traslado a la Huacachina» enseña a no leer la línea.
     */
    /**
     * El MOMENTO concreto del programa, o null si no añade nada.
     *
     * Se calla cuando repite el encargo o la variante — con los componentes de un solo uso el
     * segmento y el componente se llaman casi igual, y «Transporte Cusco - Ollanta · Transporte
     * Cusco - Ollanta» enseña a no leer la línea, que es el mismo error que ya costó caro con
     * la variante de tarifa.
     */
    public function getMomentoParaProveedor(): ?string
    {
        $momento = trim($this->nombreSegmento ?? '');

        if ($momento === '' || $momento === $this->getTituloParaProveedor() || $momento === trim($this->descripcion)) {
            return null;
        }

        return $momento;
    }

    public function getVarianteParaProveedor(): ?string
    {
        $variante = trim($this->descripcion);

        if ($variante === '' || $variante === trim($this->nombreComponente ?? '')) {
            return null;
        }

        return $variante;
    }
}
