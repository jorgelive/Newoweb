<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeImmutable;
use DateTimeInterface;
use App\Operacion\Enum\VisibilidadPuntoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
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
     * Dónde iba esta línea en el itinerario, congelado.
     *
     * `día × 1.000.000 + posición del servicio × 1.000 + orden del segmento`, tal y como lo
     * calculaba La Biblia el día de la emisión. Desempata a las líneas **sin hora**, que antes
     * quedaban a merced del orden de lectura de la colección.
     *
     * Congelado y no leído en vivo por lo mismo que el resto: una orden emitida se lee igual
     * dentro de un año aunque el itinerario se haya reordenado. Nulo en las emitidas antes del
     * 29/08/2026, y entonces desempata como antes.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ordenItinerario = null;

    /**
     * Qué NATURALEZA tiene lo contratado, congelada.
     *
     * No es decorativo: decide cuál de los dos nombres va en grande
     * ({@see ComponenteTipoEnum::mandaElSegmento()}). Congelarlo, y no leerlo del maestro al
     * pintar, es lo que hace que una orden emitida siga leyéndose igual dentro de un año aunque
     * el catálogo cambie de opinión.
     *
     * Nulo en las emitidas antes del 29/08/2026 que no se hayan rellenado; en ese caso manda el
     * componente, que es como se leían entonces.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $tipoComponente = null;

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

    /**
     * De qué grupo es esta línea. Sólo se pinta si la orden lleva más de uno.
     *
     * ⚠️ Congelado y no leído en vivo: una orden emitida dice de quién era el día que se mandó,
     * aunque después el expediente se renombre.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(name: 'nombre_grupo', type: 'string', length: 120, nullable: true)]
    private ?string $nombreGrupo = null;

    /** Cuándo termina el encargo: el checkout, el último día cubierto. Nulo si acaba el mismo día. */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(name: 'fecha_fin', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaFin = null;

    /**
     * Cómo se llama lo que cuenta `cantidad`, en singular: «noche», «día», «desayuno».
     *
     * ⚠️ Un número pelado en un documento que lee un PROVEEDOR es una invitación a que pregunte.
     * «4» al hotelero y «5» al de los seguros era la misma casilla diciendo cosas distintas.
     * Vacío = no hay unidad que nombrar (un ticket, un traslado) y se pinta el número solo.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(name: 'sustantivo_unidad', type: 'string', length: 30, nullable: true)]
    private ?string $sustantivoUnidad = null;

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

    public function getNombreGrupo(): ?string { return $this->nombreGrupo; }
    public function setNombreGrupo(?string $v): self { $this->nombreGrupo = ($v === '' ? null : $v); return $this; }

    public function getFechaFin(): ?DateTimeImmutable { return $this->fechaFin; }
    public function setFechaFin(?DateTimeImmutable $v): self { $this->fechaFin = $v; return $this; }

    /**
     * «hasta el jue 4 sep» — la salida, cuando el encargo dura más de un día.
     *
     * ⚠️ Se calla si acaba el mismo día: repetir la fecha del encabezado en cada línea enseña a
     * no leerla. Y con el día de la semana, como el encabezado: a un hotelero le dice más «jueves»
     * que «04/09», que es el formato que hay que traducir mentalmente.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getHastaParaProveedor(): ?string
    {
        if ($this->fechaFin === null || $this->fechaServicio === null) {
            return null;
        }

        if ($this->fechaFin->format('Y-m-d') === $this->fechaServicio->format('Y-m-d')) {
            return null;
        }

        // ⚠️ **Sólo lo que DURA, y ésa es una regla que este repo ya tenía escrita: cruzar
        // medianoche no es durar dos días.** En producción los que acaban «al día siguiente» sin
        // ser periodos son un traslado urbano de 30 minutos (23:30 → 00:00), un vuelo nocturno y
        // dos con la duración mal puesta —un traslado de aeropuerto de 25 horas—. A ninguno le
        // corresponde una salida: decir «hasta el 1 sep» de media hora de coche es ruido, y en
        // los dos últimos sería repetir un error de datos en un documento que firma la agencia.
        //
        // El marcador de «esto dura» es tener unidad que nombrar: noches, días, desayunos.
        if (trim((string) $this->sustantivoUnidad) === '') {
            return null;
        }

        return sprintf(
            'hasta el %s %d %s',
            self::DIAS[(int) $this->fechaFin->format('w')],
            (int) $this->fechaFin->format('j'),
            self::MESES[(int) $this->fechaFin->format('n') - 1],
        );
    }

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

    public function getSustantivoUnidad(): ?string { return $this->sustantivoUnidad; }
    public function setSustantivoUnidad(?string $v): self { $this->sustantivoUnidad = ($v === '' ? null : $v); return $this; }

    /**
     * «4 noches», «5 días», «5 desayunos» — o el número a secas si no hay unidad que nombrar.
     *
     * La redacción vive aquí y no en la plantilla porque la leen el documento público, el PDF y
     * el mensaje al proveedor. Escrita en cada uno, cambiaría en uno solo el día que se toque.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getCantidadParaProveedor(): ?string
    {
        $n = $this->cantidad === null ? null : (int) round((float) $this->cantidad);
        if ($n === null || $n <= 0) {
            return null;
        }

        $palabra = trim((string) $this->sustantivoUnidad);
        if ($palabra === '') {
            return (string) $n;
        }

        if ($n === 1) {
            return sprintf('%d %s', $n, $palabra);
        }

        $plural = preg_match('/[aeiouáéíóú]$/iu', $palabra) === 1 ? $palabra . 's' : $palabra . 'es';

        return sprintf('%d %s', $n, $plural);
    }

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

    public function getOrdenItinerario(): ?int { return $this->ordenItinerario; }
    public function setOrdenItinerario(?int $v): self { $this->ordenItinerario = $v; return $this; }

    public function getTipoComponente(): ?string { return $this->tipoComponente; }
    public function setTipoComponente(?string $v): self { $this->tipoComponente = $v; return $this; }

    public function getNombreSegmento(): ?string { return $this->nombreSegmento; }
    public function setNombreSegmento(?string $v): self { $this->nombreSegmento = $v; return $this; }

    public function getContextoServicio(): ?string { return $this->contextoServicio; }
    public function setContextoServicio(?string $v): self { $this->contextoServicio = $v; return $this; }

    /**
     * El encargo tal y como se lee: **el SEGMENTO**, y si no lo hay el componente o la variante.
     *
     * Una sola fuente para las TRES superficies —la web, el PDF y el texto de WhatsApp—, que es
     * lo que evita que se arreglen dos y la tercera siga diciendo «Auto».
     *
     * ## Por qué el segmento y no el componente (29/08/2026)
     *
     * Estuvo al revés hasta ese día, y era correcto mientras cada componente se nombrara solo. El
     * refactor de transporte lo volvió falso: los componentes pasaron a ser rutas genéricas
     * —«Transporte Cusco ↔ Ollanta (ida o vuelta)», que sirve a tres segmentos distintos— porque
     * el origen y el destino los guarda el segmento, no ellos.
     *
     * Con el genérico arriba, al proveedor le llegaba en grande un nombre con **una flecha de dos
     * puntas** y el destino de hoy en letra pequeña. El paréntesis «(ida o vuelta)» tapaba el
     * agujero avisando de que había que bajar la vista; esto lo cierra poniendo arriba el dato que
     * de verdad identifica el encargo.
     *
     * La cascada conserva los casos que no son un tramo —los bastones de Vinicunca no tienen
     * segmento— cayendo al componente y luego a la variante.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getTituloParaProveedor(): string
    {
        $componente = trim($this->nombreComponente ?? '');
        $segmento = trim($this->nombreSegmento ?? '');

        if ($this->mandaElSegmento() && $segmento !== '') {
            return $segmento;
        }

        // ⚠️ El último peldaño NO puede quedar vacío: `descripcion` admite cadena vacía, y con
        // todo en blanco la línea salía como un `**` sin nada dentro en el WhatsApp. Se cae al
        // tipo, que es genérico pero nunca miente — el mismo criterio que el espejo de `util`.
        return $componente
            ?: ($segmento
            ?: (trim($this->descripcion) ?: ((string) $this->tipoComponente ?: 'Servicio')));
    }

    /** El tipo congelado decide. Sin tipo —órdenes viejas— manda el componente, como entonces. */
    private function mandaElSegmento(): bool
    {
        return ComponenteTipoEnum::tryFrom((string) $this->tipoComponente)?->mandaElSegmento() ?? false;
    }

    /**
     * La regla que gobierna las cuatro ranuras: **cada una se calla si repite alguna de las que
     * ya salieron antes en la línea**.
     *
     * Sustituye a cuatro comparaciones sueltas que no coincidían entre sí ni con su espejo en
     * `util`. La variante, por ejemplo, se callaba comparando contra el **componente** — que
     * desde que manda el segmento ya no es lo que hay arriba—, así que:
     *
     * - variante = segmento → PHP la imprimía y quedaba **el mismo texto dos veces** en el
     *   WhatsApp y en el PDF;
     * - variante = componente → `util` la enseñaba junto al secundario, que era ese mismo texto.
     *
     * Fallaba en los dos sentidos y en superficies distintas, que es lo que pasa cuando dos
     * espejos comparan contra cosas parecidas pero no iguales. Una sola regla acumulativa no
     * tiene ese problema: no hay contra qué equivocarse.
     *
     * @param list<string|null> $anteriores
     */
    private function calladoSiRepite(?string $texto, array $anteriores): ?string
    {
        $limpio = trim((string) $texto);

        if ($limpio === '') {
            return null;
        }

        foreach ($anteriores as $previo) {
            if ($limpio === trim((string) $previo)) {
                return null;
            }
        }

        return $limpio;
    }

    /**
     * El OTRO de los dos nombres, o null si no añade nada.
     *
     * ⚠️ **Sólo para La Biblia, no para el proveedor** (29/08/2026). Al operador le sirve saber
     * de qué componente del catálogo salió la fila —es como lo busca—; al proveedor no le dice
     * nada que no diga ya el encargo, y desde la fusión por sentido es **literalmente menos
     * preciso**: debajo de «Transporte desde el Aeropuerto de Lima al hotel en Lima» ponía
     * «Transporte Aeropuerto Lima ↔ Miraflores (ida o vuelta)», que es la misma ruta sin el
     * sentido. Dos textos donde uno ya era el bueno.
     *
     * La regla de prioridad decide cuál gana; el que pierde se queda dentro.
     *
     * Cuál sea lo decide el tipo: en un traslado manda el segmento y aquí baja el componente; en
     * una entrada, al revés. Así la línea lleva siempre los dos datos —el encargo y el momento—
     * y nunca dos veces el mismo.
     *
     * Sigue haciendo falta aunque el título ya identifique la fila: dice qué vehículo y de qué
     * proveedor es, que el segmento no dice.
     *
     * ⚠️ El docblock que había aquí describía **la variante de tarifa**, no este método: quedó
     * apilado encima al renombrarlo. Un comentario que describe otra cosa es peor que ninguno,
     * porque se lee como si fuera cierto.
     */
    public function getSecundarioParaProveedor(): ?string
    {
        // El que NO subió: si manda el segmento, aquí va el componente, y al revés. Así la línea
        // siempre lleva los dos datos y nunca dos veces el mismo.
        $tipo = ComponenteTipoEnum::tryFrom((string) $this->tipoComponente);

        // En una excursión el segmento es sólo un capítulo de lo comprado: enseñarlo encoge el
        // encargo. Ver ComponenteTipoEnum::ocultaElSegmento().
        if ($tipo?->ocultaElSegmento() === true) {
            return null;
        }

        $secundario = $this->mandaElSegmento() ? $this->nombreComponente : $this->nombreSegmento;

        return $this->calladoSiRepite($secundario, [$this->getTituloParaProveedor()]);
    }

    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getVarianteParaProveedor(): ?string
    {
        return $this->calladoSiRepite($this->descripcion, [$this->getTituloParaProveedor()]);
    }

    /**
     * El DÍA del itinerario, o null si repite algo de lo ya dicho.
     *
     * Existe para que el documento y el twig no tengan que repetir la comparación cada uno por su
     * cuenta — antes sólo miraban contra el título, así que un contexto igual al componente salía
     * duplicado en el PDF.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getDiaParaProveedor(): ?string
    {
        return $this->calladoSiRepite($this->contextoServicio, [
            $this->getTituloParaProveedor(),
            $this->getVarianteParaProveedor(),
        ]);
    }
    /**
     * La línea que lee el proveedor, compuesta desde los datos CONGELADOS de este ítem.
     *
     * ── Por qué vive aquí y no en el servicio que arma el documento ─────────
     *
     * Porque hay que poder componer **la misma línea desde La Biblia viva** y compararlas: si el
     * texto que el proveedor tiene en la mano ya no coincide con el que saldría hoy, la orden hay
     * que reemitirla. Con la composición dentro de un servicio, la comparación necesitaba
     * duplicarla; aquí la hacen los dos lados con el mismo código.
     *
     * ⚠️ **Y eso convierte la vigilancia en algo que se cumple solo.** Antes
     * `OperacionOrdenServicio::getDivergencias()` miraba una lista de campos escogidos a mano: de
     * los doce datos que esta línea imprime, vigilaba cuatro. Un cambio de prestador, de título o
     * de variante no decía nada — y la ausencia de aviso se lee como «está todo bien». El día que
     * se añada un dato más a esta línea, entra vigilado sin que nadie se acuerde.
     *
     * La regla que sale de ahí, y que ya estaba escrita al revés en el caso del importe: **se
     * vigila lo que el documento imprime, y sólo eso.** Lo que no se imprime —el importe— no puede
     * generar más que alarmas falsas.
     *
     * @param ?string $ruta       La línea de recojo/entrega, que la compone la ORDEN según qué le
     *                            toca enseñar a cada ítem. Se pasa de fuera y no se deriva aquí:
     *                            al comparar contra La Biblia se le da el mismo valor a los dos
     *                            lados, porque los puntos ya tienen su propia vigilancia.
     * @param bool    $multigrupo Si la orden lleva varios expedientes. Con uno solo, el encabezado
     *                            ya dijo de quién es y repetirlo por renglón es ruido.
     */
    public function lineaParaProveedor(
        ?string $ruta = null,
        bool $multigrupo = false,
        ?string $comprador = null,
    ): string {
        // ⚠️ El comprador entra por parámetro y no se lee siempre de la orden, porque al comparar
        // contra La Biblia el ítem que se compone es TRANSITORIO y no tiene orden: leyéndolo de
        // ahí salía vacío, el prestador «difería» de la nada y la línea viva imprimía un «opera X»
        // que la congelada no tenía. Cinco falsos positivos en la primera prueba, todos por esto.
        $comprador ??= $this->getOrden()?->getCompradorNombre();
        // ⚠️ La fecha YA NO va en la línea: la lleva el encabezado del día. Repetirla en cada
        // renglón era la mitad del ancho gastado en un dato que no cambia dentro del bloque.
        $partes = [];

        $hora = trim((string) $this->getHora());

        if ($hora !== '') {
            $partes[] = $hora;
        }

        // QUÉ hay que hacer, en negrita, y la variante de tarifa detrás entre paréntesis.
        //
        // ⚠️ Antes aquí iba `getDescripcion()` a secas, que es SÓLO la variante: al que hacía el
        // traslado Ollantaytambo→Cusco le llegaba una línea que decía «Auto», y al hotelero
        // «Hotel 4 estrellas por grupo». La variante importa —distingue el auto de la van— pero
        // como calificador de un encargo, no como el encargo.
        $partes[] = sprintf('*%s*', $this->getTituloParaProveedor());

        if (($variante = $this->getVarianteParaProveedor()) !== null) {
            $partes[] = $variante;
        }

        // QUÉ exactamente se le contrata: la habitación, la clase de tren. Va después de la
        // variante porque la concreta —«Alojamiento en Cusco · Hotel 4 estrellas · Habitación
        // premium»— y es el dato con el que el hotelero busca la reserva.
        //
        // Se calla si repite lo que ya se dijo: cuando el componente tiene servicio de prestador,
        // `resolverDescripcion()` lo usa como descripción, así que variante y servicio coinciden.
        $servicio = trim((string) $this->getPrestadorServicioNombre());

        if ($servicio !== '' && $servicio !== $variante && $servicio !== $this->getTituloParaProveedor()) {
            $partes[] = $servicio;
        }

        // La hora de recojo CONFIRMADA es la que vale; si no la hay todavía, no se inventa.
        //
        // ⚠️ Y sólo se dice cuando DIFIERE de la hora del servicio. En los datos reales coinciden
        // casi siempre —«04:00 · Tacama · recojo 04:00»— y repetir el mismo dato dos veces por
        // línea enseña a no leerlo, que es exactamente lo contrario de lo que hace falta el día
        // que sí sean distintas.
        if (($recojo = trim((string) $this->getHoraRecojoConfirmada())) !== '' && $recojo !== $hora) {
            $partes[] = sprintf('recojo %s', $recojo);
        }

        if (($pax = $this->getCantidadPax()) !== null && $pax > 0) {
            $partes[] = sprintf('%d pax', $pax);
        }

        // ⚠️ **Cuánto y hasta cuándo**, que es lo que faltaba. Al hotelero le llegaba «Lun 31 ago
        // · Habitación Superior · 2 pax» — la entrada, sin salida y sin número de noches: el
        // encargo sin su duración, y con la parte que más se pregunta por teléfono.
        //
        // Las dos redacciones viven en el ÍTEM, que es quien las pinta también en la página
        // pública: escritas aquí también, cambiarían en un sitio y no en el otro.
        if (($cuanto = $this->getCantidadParaProveedor()) !== null && $this->getSustantivoUnidad() !== null) {
            $partes[] = $cuanto;
        }

        if (($hasta = $this->getHastaParaProveedor()) !== null) {
            $partes[] = $hasta;
        }

        // ── Dónde recoge y dónde deja ───────────────────────────────────────
        //
        // Va en su propio renglón: metida en la ristra de la línea, entre la hora y los pax, una
        // dirección de cuarenta caracteres sepulta todo lo demás. La redacción la compone el
        // ítem, que es también quien la pinta en la página pública — ver `rutaParaLaOrden()`.
        $ruta = $ruta;

        // El prestador va sólo cuando NO es el destinatario: si coinciden, decírselo es ruido.
        $prestador = trim((string) $this->getPrestadorNombre());
        $comprador = trim((string) $comprador);

        if ($prestador !== '' && $prestador !== $comprador) {
            $partes[] = sprintf('opera %s', $prestador);
        }

        // El reloj marca dónde empieza cada servicio, que es lo que se busca al repasar el día.
        // Un icono y no un guion porque en una lista de cinco el ojo salta a la forma, no al signo.
        // El día del itinerario, sin negrita y al final: sitúa el servicio sin competir con él.
        //
        // La decisión de callarlo vive en la ENTIDAD (`getDiaParaProveedor()`), no aquí: antes se
        // comparaba sólo contra el título y el twig hacía lo mismo por su cuenta, así que un día
        // igual al componente salía duplicado. Una regla en un sitio, tres superficies que la
        // consumen.
        if (($dia = $this->getDiaParaProveedor()) !== null) {
            $partes[] = $dia;
        }

        // De quién es la línea. **Sólo con más de un grupo**: con uno, el encabezado ya lo dijo y
        // repetirlo en cada renglón es ruido.
        if ($multigrupo && ($grupo = trim((string) $this->getNombreGrupo())) !== '') {
            $partes[] = $grupo;
        }

        $linea = '🕐 ' . implode('  ·  ', $partes);

        // El pin va en su propio renglón, alineado bajo el reloj: es una dirección larga y metida
        // en la ristra sepulta la hora y los pax. Ver el comentario de arriba.
        if ($ruta !== null) {
            $linea .= "\n📍 " . $ruta;
        }

        // ── LO QUE HAY QUE SABER PARA OPERARLO ──────────────────────────────
        //
        // ⚠️ **Faltaba entero.** Aquí vive «Delta LATAM LA-2695 Aterriza 22:00», que es el dato
        // con el que un chófer decide a qué hora sale de casa. Estaba en La Biblia y en la página
        // pública, pero NO en el mensaje — o sea que por WhatsApp o correo, que es por donde el
        // proveedor lo recibe de verdad, se le pedía recoger en un aeropuerto sin decirle el
        // vuelo. Una línea por nota, porque son frases y encadenadas no se leen.
        foreach ($this->getNotasPrestador() as $nota) {
            $linea .= "\n📝 " . $nota;
        }

        return $linea;
    }

    /**
     * La foto de una fila de La Biblia, en el momento de emitir.
     *
     * ── Por qué es una fábrica y no código suelto en la emisión ─────────────
     *
     * Porque hace falta **dos veces**: al emitir, para congelar el documento; y al vigilar, para
     * componer «lo que diría la línea hoy» y compararlo con lo que se mandó
     * ({@see \App\Operacion\Entity\OperacionOrdenServicio::getDivergencias()}). Con el mapeo
     * escrito dentro del servicio de emisión, la vigilancia tenía que repetirlo — y un mapeo
     * repetido se desincroniza el día que alguien añada un campo en un solo lado.
     *
     * ⚠️ **Los PUNTOS no se resuelven aquí, a propósito.** Necesitan el expediente y la cadena de
     * alojamiento, o sea consultas y un servicio; los pone la emisión después. Para comparar no
     * hacen falta: los puntos ya tienen su propia vigilancia, más específica que un diff de texto.
     */
    public static function desdeServicio(\App\Operacion\Entity\OperacionServicio $servicio): self
    {
        $negociado = (float) $servicio->getCostoNegociado();

        $item = new self();
        $item
            ->setOperacionServicioId((string) $servicio->getId())
            // Los DOS, siempre: qué es y dónde encaja. `descripcion` sola es la
            // variante de tarifa, y sola le decía «Auto» al que hace el traslado.
            ->setDescripcion($servicio->getDescripcionServicio())
            ->setNombreComponente($servicio->getNombreComponente())
            // El MOMENTO: sin él, el componente tiene que cargar con la ruta en su
            // nombre, y eso es lo que multiplicó las tarifas por destino.
            ->setNombreSegmento($servicio->getNombreSegmento())
            // El TIPO decide cuál de los dos nombres va en grande, así que se congela
            // con ellos: leerlo del maestro al pintar haría que una orden emitida se
            // leyera distinta el día que el catálogo cambie de opinión.
            ->setTipoComponente($servicio->getTipoComponente())
            // Dónde iba en el itinerario: desempata las líneas sin hora.
            ->setOrdenItinerario($servicio->getOrdenItinerario())
            ->setContextoServicio($servicio->getContextoServicio())
            ->setFechaServicio($servicio->getFechaServicio())
            // La hora que se pidió: la pactada si la hay, si no la vendida.
            ->setHora($servicio->getHoraRecojo() ?? $servicio->getHoraComponente())
            // Nula si el proveedor todavía no la ha confirmado. Es lo que distingue
            // «confirmó» de «cambió» cuando aparezca. Ver el docblock del ítem.
            ->setHoraRecojoConfirmada($servicio->getHoraRecojo())
            ->setCantidadPax($servicio->getCantidadPax())
            ->setCantidad((string) $servicio->getCantidadComponente())
            // Y en qué se cuenta: «4 noches» le dice al hotelero lo que «4» no le dice.
            ->setSustantivoUnidad($servicio->getSustantivoUnidad())
            // Y cuándo acaba: «4 noches» sin salida sigue dejando al hotelero a medias.
            ->setFechaFin($servicio->getFechaFinServicio())
            // De quién es la línea. Se pinta sólo si la orden lleva varios grupos.
            ->setNombreGrupo($servicio->getFile()?->getNombreGrupo())
            // Mientras nadie negocie, lo que se pide es lo cotizado: un cero se leería
            // como «pactado en cero», que es lo contrario de «todavía sin pactar».
            ->setImporte($negociado > 0.0 ? $servicio->getCostoNegociado() : $servicio->getCostoCotizado())
            ->setMoneda($negociado > 0.0
                ? ($servicio->getMonedaNegociada() ?? $servicio->getMonedaCotizada())
                : $servicio->getMonedaCotizada())
            // Por NOMBRE y el EFECTIVO: el documento no depende de que la ficha siga
            // existiendo, y lo que se pidió es lo que operaciones decidió.
            ->setPrestadorNombre($servicio->getPrestadorEfectivoNombre())
            ->setPrestadorServicioNombre($servicio->getPrestadorServicioEfectivoNombre())
            // Lo que hay que contarle: su redacción si el operador la escribió, si no los
            // detalles que la cotización marcó para él. Congelado, como todo lo demás.
            ->setNotasPrestador($servicio->getNotasPrestadorEfectivas());

        return $item;
    }

}
