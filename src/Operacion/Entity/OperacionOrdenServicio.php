<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Operacion\ApiPlatform\Dto\CambiarEstadoOrdenInput;
use App\Operacion\ApiPlatform\Dto\EmitirOrdenInput;
use App\Operacion\ApiPlatform\State\AplicarCambiosMenoresProcessor;
use App\Operacion\ApiPlatform\State\CambiarEstadoOrdenProcessor;
use App\Operacion\ApiPlatform\State\EmitirOrdenProcessor;
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

        // ─────────────────────────────────────────────────────────────────────
        // ACCIONES — lo que un PATCH no puede hacer bien
        //
        // Crear una orden eran N `PATCH` para atar cada fila más un `POST` para la cabecera,
        // orquestados desde el navegador: si la pestaña se caía en medio quedaban filas
        // atadas a una orden que no llegó a existir. Y las reglas vivían la mitad en
        // `conflictoSeleccion` del front, así que dos pestañas armaban lo que la vista
        // impedía.
        //
        // Mismo patrón que `/cotizacions/{id}/operacion/aplicar`: una llamada, una
        // transacción, y la respuesta trae ya el estado nuevo.
        // ─────────────────────────────────────────────────────────────────────
        new Post(
            uriTemplate: '/orden-servicios/emitir',
            denormalizationContext: ['groups' => ['operacion:orden:write']],
            input: EmitirOrdenInput::class,
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para emitir órdenes de servicio.',
            read: false,
            processor: EmitirOrdenProcessor::class
        ),
        // Lo que NO obliga a reemitir: el proveedor confirmó la hora. Se actualiza el
        // documento y se avisa —confirmación, no modificación—, y la orden sigue válida.
        new Post(
            uriTemplate: '/orden-servicios/{id}/aplicar-menores',
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para actualizar órdenes de servicio.',
            read: false,
            deserialize: false,
            validate: false,
            processor: AplicarCambiosMenoresProcessor::class
        ),
        new Post(
            uriTemplate: '/orden-servicios/{id}/estado',
            denormalizationContext: ['groups' => ['operacion:orden:write']],
            input: CambiarEstadoOrdenInput::class,
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para cambiar el estado de una orden.',
            read: false,
            processor: CambiarEstadoOrdenProcessor::class
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
// ⚠️ El índice se nombra AQUÍ y no con `unique: true` en la columna: aquello hace que Doctrine
// invente un nombre (`UNIQ_76415CAF823D5230`) que la migración no puede predecir, y
// `schema:validate` se queda en rojo pidiendo renombrarlo.
#[ORM\UniqueConstraint(name: 'UNIQ_OS_TOKEN_PUBLICO', columns: ['token_publico'])]
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

    /**
     * ⚠️ **Sin `operacion:write`, a propósito.** Se mueve por `/orden-servicios/{id}/estado`.
     *
     * Emitir congela el contenido y anular suelta las filas: dos cosas que un `PATCH` genérico
     * no sabe distinguir de corregir el número de la orden. Con dos puertas a la misma
     * transición, las reglas se escapan por la que no mira nadie.
     */
    #[Groups(['operacion:read'])]
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
     * moneda (lo que dijo el cotizador, referencial) y `costoNegociado` con la suya (lo
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
     * Las líneas CONGELADAS. Se llenan al emitir y no se vuelven a tocar.
     *
     * @var Collection<int, OperacionOrdenServicioItem>
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\OneToMany(mappedBy: 'orden', targetEntity: OperacionOrdenServicioItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /**
     * La Orden a la que ésta sustituye.
     *
     * Anular sin decir qué la reemplaza deja la historia coja: «OS-014 anulada → OS-021» es la
     * pregunta que se hace de verdad cuando un proveedor reclama.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $reemplazaA = null;

    /**
     * @var Collection<int, OperacionMensaje>
     */
    #[Groups(['operacion:read'])]
    #[ORM\OneToMany(mappedBy: 'ordenServicio', targetEntity: OperacionMensaje::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $mensajes;

    /**
     * Pagos a cuenta hechos al proveedor. No se serializan en el listado (se piden aparte al
     * abrir el modal de pagos): son de una orden a la vez, no de las 3 que caben en pantalla.
     * @var Collection<int, OperacionPago>
     */
    #[ORM\OneToMany(mappedBy: 'ordenServicio', targetEntity: OperacionPago::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fecha' => 'DESC'])]
    private Collection $pagos;

    public function __construct()
    {
        $this->initializeId();
        $this->operacionServicios = new ArrayCollection();
        $this->items = new ArrayCollection();
        $this->mensajes = new ArrayCollection();
        $this->pagos = new ArrayCollection();
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
     * @return list<array{moneda: string, cotizado: string, real: string, pagado: string, saldo: string}>
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
                'pagado'   => ['type' => 'string'],
                'saldo'    => ['type' => 'string'],
            ],
        ],
    ])]
    public function getTotalesPorMoneda(): array
    {
        /** @var array<string, array{cotizado: float, real: float, pagado: float}> $acumulado */
        $acumulado = [];

        // Los pagos primero, para que una moneda con pagos pero sin servicio (raro, pero
        // posible tras editar) aparezca igual en el desglose y no esconda un abono.
        foreach ($this->pagos as $pago) {
            $m = $pago->getMoneda()?->getId() ?? '—';
            $acumulado[$m] ??= ['cotizado' => 0.0, 'real' => 0.0, 'pagado' => 0.0];
            $acumulado[$m]['pagado'] += (float) $pago->getMonto();
        }

        foreach ($this->operacionServicios as $servicio) {
            $cotizado = (float) $servicio->getCostoCotizado();
            $real     = (float) $servicio->getCostoNegociado();

            $monedaCotizada = $servicio->getMonedaCotizada()?->getId() ?? '—';
            // La moneda de lo negociado puede ser OTRA: se cotiza en dólares y se cierra en
            // soles. Sin este reparto, ese importe se sumaría bajo la moneda equivocada.
            $monedaNegociada = $servicio->getMonedaNegociada()?->getId() ?? $monedaCotizada;

            $acumulado[$monedaCotizada] ??= ['cotizado' => 0.0, 'real' => 0.0, 'pagado' => 0.0];
            $acumulado[$monedaCotizada]['cotizado'] += $cotizado;

            $acumulado[$monedaNegociada] ??= ['cotizado' => 0.0, 'real' => 0.0, 'pagado' => 0.0];
            // Mientras nadie negocie, «real» es el cotizado: un cero ahí se leería como
            // «pactado en cero», que es lo contrario de «todavía sin pactar».
            $acumulado[$monedaNegociada]['real'] += $real > 0.0 ? $real : ($monedaNegociada === $monedaCotizada ? $cotizado : 0.0);
        }

        ksort($acumulado);

        $salida = [];
        foreach ($acumulado as $moneda => $montos) {
            // Saldo = lo negociado − lo ya pagado. Es lo que falta por abonar al proveedor.
            $saldo = $montos['real'] - $montos['pagado'];
            $salida[] = [
                'moneda'   => (string) $moneda,
                'cotizado' => number_format($montos['cotizado'], 2, '.', ''),
                'real'     => number_format($montos['real'], 2, '.', ''),
                'pagado'   => number_format($montos['pagado'], 2, '.', ''),
                'saldo'    => number_format($saldo, 2, '.', ''),
            ];
        }

        return $salida;
    }

    /**
     * La llave del enlace público que se le manda al proveedor.
     *
     * ── Por qué no vale el UUID ni el número de OS ──────────────────────────
     * El `numeroOs` es adivinable de cabo a rabo —`OS-20260817-938`, tres dígitos— y el UUID es
     * un **v7**: lleva la marca de tiempo delante, así que dos órdenes creadas el mismo día
     * comparten prefijo. Ninguno de los dos sirve como llave de algo que se abre **sin sesión**.
     *
     * Esto son 32 caracteres de azar. No es un secreto fuerte —quien tenga el enlace ve la
     * orden, igual que con el enlace público de una cotización— pero no se acierta probando.
     *
     * ⚠️ Se genera al EMITIR y no al crear: un borrador no tiene nada que enseñar, y una llave
     * que existe antes de que haya documento invita a compartir un enlace vacío.
     */
    #[ORM\Column(name: 'token_publico', type: 'string', length: 32, nullable: true)]
    #[Groups(['operacion:read'])]
    private ?string $tokenPublico = null;

    public function getTokenPublico(): ?string { return $this->tokenPublico; }

    /** Idempotente: si ya tiene llave, se queda con la suya — el enlace ya está en manos de alguien. */
    public function asegurarTokenPublico(): self
    {
        if ($this->tokenPublico === null) {
            $this->tokenPublico = bin2hex(random_bytes(16));
        }

        return $this;
    }

    /** @return Collection<int, OperacionPago> */
    public function getPagos(): Collection { return $this->pagos; }

    /**
     * Añade un pago manteniendo los DOS lados de la relación.
     *
     * ⚠️ No existía, y el lado inverso se quedaba sin poblar hasta releer de la base. Con el
     * saldo calculado al vuelo sobre `$this->pagos` —{@see self::getTotalesPorMoneda()}— eso
     * significa que cualquier cosa que mire la orden **dentro de la misma petición** en que se
     * registró un abono lo ve sin él: la validación de moneda, el marcador de saldada y
     * cualquier regla futura. El panel lo tapaba recargando la orden después de guardar, que es
     * una vuelta de red para arreglar algo que pasaba en memoria.
     *
     * Mismo patrón que {@see self::addItem()}.
     */
    public function addPago(OperacionPago $pago): self
    {
        if (!$this->pagos->contains($pago)) {
            $this->pagos->add($pago);
            $pago->setOrdenServicio($this);
        }

        return $this;
    }

    public function removePago(OperacionPago $pago): self
    {
        $this->pagos->removeElement($pago);

        return $this;
    }

    /**
     * Las monedas en que esta orden está denominada, según sus SERVICIOS.
     *
     * Es la lista contra la que se valida un pago: {@see OperacionPago::validarMonedaDeLaOrden()}.
     *
     * ⚠️ **No sale de `getTotalesPorMoneda()`**, aunque parezca lo mismo. Aquél incluye las
     * monedas de los pagos ya registrados —para que un abono no se esconda—, así que validar
     * contra él haría que el primer pago en una moneda equivocada legitimara al segundo. Esto
     * mira sólo lo cotizado y lo negociado, que es lo que de verdad dice en qué se debe.
     *
     * Se añade `monedaOs` si está puesta: es el apunte manual de conciliación de la cabecera y,
     * cuando existe, también dice en qué se maneja la orden.
     *
     * @return list<string> Códigos ISO-3, ordenados y sin repetir.
     */
    public function monedasDeLosServicios(): array
    {
        $monedas = [];

        foreach ($this->operacionServicios as $servicio) {
            foreach ([$servicio->getMonedaCotizada(), $servicio->getMonedaNegociada()] as $moneda) {
                if ($moneda !== null) {
                    $monedas[$moneda->getId()] = true;
                }
            }
        }

        if ($this->monedaOs !== null) {
            $monedas[$this->monedaOs->getId()] = true;
        }

        $lista = array_keys($monedas);
        sort($lista);

        return $lista;
    }

    /**
     * ¿Está saldada? Es decir: ¿no le debemos nada al proveedor en ninguna moneda?
     *
     * ── Por qué es DERIVADO y no un estado de `estadoOs` ────────────────────
     * Porque `EstadoOrdenServicioEnum` responde a otra pregunta: **en qué punto está el papeleo
     * de la orden de compra** —borrador, emitida, confirmada, completada, cancelada—. Meter
     * «pagada» ahí obligaría a que una orden CONFIRMADA dejara de estarlo al pagarla, y perder
     * esa información para ganar un dato que ya se puede calcular es un mal cambio: son dos
     * ejes independientes, y una orden puede estar completada y sin pagar, o pagada por
     * adelantado y aún sin confirmar.
     *
     * Ver el aviso de `EstadoReservaProveedorEnum`, que ya documenta que en este módulo los
     * estados son tres y contestan cosas distintas.
     *
     * ⚠️ Una orden **sin importes** no está pagada: está sin cargar. Con todo a cero el saldo
     * también da cero, y decir «pagada» ahí es afirmar algo sobre un dinero que nadie ha
     * escrito todavía.
     */
    #[Groups(['operacion:read'])]
    public function isSaldada(): bool
    {
        $hayImporte = false;

        foreach ($this->getTotalesPorMoneda() as $total) {
            if ((float) $total['real'] > 0.0) {
                $hayImporte = true;
            }

            if ((float) $total['saldo'] > 0.0) {
                return false;
            }
        }

        return $hayImporte;
    }

    public function getOperacionServicios(): Collection { return $this->operacionServicios; }

    /** @return Collection<int, OperacionOrdenServicioItem> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(OperacionOrdenServicioItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrden($this);
        }

        return $this;
    }

    public function getReemplazaA(): ?self { return $this->reemplazaA; }
    public function setReemplazaA(?self $v): self { $this->reemplazaA = $v; return $this; }

    /** ¿Ya es un documento? Un borrador todavía se compone; una emitida sólo se anula. */
    public function estaEmitida(): bool
    {
        return $this->estadoOs !== EstadoOrdenServicioEnum::BORRADOR;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DIVERGENCIA — la Orden dice una cosa y La Biblia ya dice otra
    //
    // Después de emitir, La Biblia sigue moviéndose: la cotización sincroniza —el cambio de
    // fecha se hace **allí**, para que el programa que ve el cliente quede al día— y el
    // operador ajusta sus overrides. Entonces el documento y la realidad se separan.
    //
    // Puede ser correcto —se pidieron 3 pax y ahora son 4: hay que pedir uno más— o un
    // descuido. La Orden **no se corrige sola**: un documento ya mandado no cambia solo. Lo que
    // hace es **marcarse sucia** para que una persona decida y reemita.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lo que separa al documento de La Biblia **y obliga a reemitir**.
     *
     * Aquí sólo entra lo que cambia el trato: si el proveedor tuviera esta orden delante,
     * estaría leyendo algo que ya no es cierto. Lo que se resuelve actualizando y avisando va
     * en {@see self::getCambiosMenores()}.
     *
     * @return list<string>
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getDivergencias(): array
    {
        if (!$this->vigilable()) {
            return [];
        }

        $avisos = [];

        // El COMPRADOR es el peor de todos: la orden ya se mandó a la empresa equivocada.
        // Va antes que nada porque no depende de ninguna línea concreta.
        foreach ($this->operacionServicios as $servicio) {
            $efectivo = $servicio->getCompradorEfectivoMaestroId();

            if ($efectivo !== null && $efectivo !== $this->compradorMaestroId) {
                $avisos[] = sprintf(
                    'se pidió a %s y ahora se le compra a %s',
                    $this->compradorNombre ?? '—',
                    $servicio->getCompradorEfectivoNombre() ?? '—'
                );
                break;
            }
        }

        foreach ($this->items as $item) {
            $servicio = $this->vivoDe($item);
            $que = $this->etiqueta($item);

            // Se soltó de esta Orden: lo movieron, lo cancelaron o se anuló la fila.
            if ($servicio === null) {
                $avisos[] = sprintf('«%s» ya no está en esta orden', $que);
                continue;
            }

            $fechaItem = $item->getFechaServicio()?->format('d/m/Y');
            $fechaViva = $servicio->getFechaServicio()?->format('d/m/Y');
            if ($fechaItem !== $fechaViva) {
                $avisos[] = sprintf('«%s»: se pidió para el %s y ahora es el %s',
                    $que, $fechaItem ?? '—', $fechaViva ?? '—');
            }

            if ($item->getCantidadPax() !== $servicio->getCantidadPax()) {
                $avisos[] = sprintf('«%s»: se pidieron %s pax y ahora son %s',
                    $que, $item->getCantidadPax() ?? '—', $servicio->getCantidadPax());
            }

            if (!self::mismoImporte($item->getImporte(), $servicio->getCostoNegociado(), $servicio->getCostoCotizado())) {
                $avisos[] = sprintf('«%s»: el importe cambió', $que);
            }

            // Hora que YA estaba confirmada y ahora es otra: eso es una modificación, y hay
            // que avisar al cliente y al proveedor. Que aparezca por primera vez, no.
            $confirmada = $item->getHoraRecojoConfirmada();
            if ($confirmada !== null && $confirmada !== $servicio->getHoraRecojo()) {
                $avisos[] = sprintf('«%s»: el recojo era a las %s y ahora es a las %s',
                    $que, $confirmada, $servicio->getHoraRecojo() ?? '—');
            }

            // ── El SITIO de recojo y entrega ────────────────────────────────
            //
            // ⚠️ Sólo se compara con el override del operador, no con el catálogo. Volver a
            // derivar el punto necesita el expediente, la cadena de alojamiento y varias
            // consultas, y esto es un método de entidad que se pinta por fila. Así que caza el
            // caso real —«lo corregí después de emitir y el proveedor sigue con el sitio viejo»—
            // y NO caza que alguien cambie el segmento en el maestro.
            //
            // Ese hueco está anotado en docs/Operacion.md §12; taparlo a medias sería peor, porque
            // una vigilancia que a veces mira y a veces no se lee como que siempre mira.
            foreach ([
                ['recojo', $item->getPuntoRecojoConfirmado(), $servicio->getPuntoRecojo()],
                ['entrega', $item->getPuntoEntregaConfirmado(), $servicio->getPuntoEntrega()],
            ] as [$lado, $congelado, $override]) {
                $override = trim((string) $override);
                $congelado = trim((string) $congelado);

                if ($override !== '' && $override !== $congelado) {
                    $avisos[] = sprintf(
                        '«%s»: el %s era %s y ahora es %s',
                        $que,
                        $lado,
                        $congelado !== '' ? $congelado : '—',
                        $override
                    );
                }
            }
        }

        return $avisos;
    }

    /**
     * Lo que se resuelve **actualizando la orden**, sin reemitir.
     *
     * De momento sólo la hora de recojo que aparece por primera vez, y es el caso más común
     * de todos: cuando le pides un servicio a un proveedor, **la hora te la dice él al
     * confirmar**. Que aparezca es el final normal del flujo, no un descuido — tratarlo como
     * cambio sucio obligaría a reemitir cada orden que sale bien.
     *
     * Lo que sí hay que hacer es **confirmarla a las dos partes**: al cliente, para su
     * programa, y al proveedor, como acuse. Pero es una confirmación, no una modificación, y
     * el aviso que se manda no es el mismo.
     *
     * @return list<string>
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getCambiosMenores(): array
    {
        if (!$this->vigilable()) {
            return [];
        }

        $avisos = [];

        foreach ($this->items as $item) {
            $servicio = $this->vivoDe($item);

            if ($servicio === null || $item->getHoraRecojoConfirmada() !== null) {
                continue;
            }

            if (($hora = $servicio->getHoraRecojo()) !== null) {
                $avisos[] = sprintf('«%s»: el proveedor confirmó el recojo a las %s', $this->etiqueta($item), $hora);
            }
        }

        return $avisos;
    }

    /** ¿Hay que reemitir? Es lo que pinta el aviso rojo. */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function isSucia(): bool
    {
        return $this->getDivergencias() !== [];
    }

    /** ¿Hay algo que aplicar sin reemitir? Pinta el aviso azul, con su botón. */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function hasCambiosMenores(): bool
    {
        return $this->getCambiosMenores() !== [];
    }

    /**
     * Copia los cambios menores al documento. **No reemite: la orden sigue siendo válida.**
     *
     * Devuelve lo aplicado para que quien llame sepa qué avisar — es de aquí de donde cuelgan
     * las acciones: confirmar la hora al cliente y acusarla al proveedor.
     *
     * @return list<string>
     */
    public function aplicarCambiosMenores(): array
    {
        $aplicados = $this->getCambiosMenores();

        foreach ($this->items as $item) {
            $servicio = $this->vivoDe($item);

            if ($servicio === null || $item->getHoraRecojoConfirmada() !== null) {
                continue;
            }

            if (($hora = $servicio->getHoraRecojo()) !== null) {
                $item->setHoraRecojoConfirmada($hora);
                $item->setHora($hora);
            }
        }

        return $aplicados;
    }

    /** Un borrador es una vista viva; una anulada ya no se persigue. */
    /**
     * Qué ítems enseñan el «recoge en… → deja en…», y cuáles se lo callan.
     *
     * ⚠️ **El sitio de recojo se dice UNA VEZ AL DÍA, no en cada línea.** Una orden va a un solo
     * proveedor, así que repetirle en las seis líneas del martes que recoge en el Hotel Terra no
     * le informa: le enseña a no leer ese renglón, y entonces no lo lee el día que sí cambia. Es
     * la misma razón por la que la hora de recojo sólo sale cuando difiere de la del servicio.
     *
     * **Al día siguiente vuelve a salir**, aunque sea el mismo sitio: es información que se
     * consulta por jornada, y el que opera el miércoles puede no haber leído el martes.
     *
     * Y **sale igualmente en mitad del día si el sitio CAMBIA**: ahí es justo cuando hace falta.
     *
     * Se decide aquí y no en cada superficie porque lo pintan dos —el mensaje al proveedor y la
     * página pública con su PDF— y una regla de «cuándo callarse» escrita dos veces se convierte
     * en dos documentos distintos para el mismo servicio.
     *
     * @return array<string, string> id de ítem → la línea a pintar. Los ausentes no la pintan.
     */
    public function rutasVisibles(): array
    {
        /** @var list<OperacionOrdenServicioItem> $items */
        $items = $this->items->toArray();

        usort($items, static function (OperacionOrdenServicioItem $a, OperacionOrdenServicioItem $b): int {
            return [$a->getFechaServicio()?->format('Y-m-d') ?? '', (string) $a->getHora()]
                <=> [$b->getFechaServicio()?->format('Y-m-d') ?? '', (string) $b->getHora()];
        });

        $visibles = [];
        $ultimaPorDia = [];

        foreach ($items as $item) {
            $ruta = $item->rutaParaLaOrden();
            $id = $item->getId()?->toRfc4122();

            if ($ruta === null || $id === null) {
                continue;
            }

            // Sin fecha no hay día en el que agrupar: se enseña siempre, que es lo prudente.
            $dia = $item->getFechaServicio()?->format('Y-m-d') ?? $id;

            if (($ultimaPorDia[$dia] ?? null) === $ruta) {
                continue;
            }

            $ultimaPorDia[$dia] = $ruta;
            $visibles[$id] = $ruta;
        }

        return $visibles;
    }

    private function vigilable(): bool
    {
        return $this->estaEmitida() && $this->estadoOs !== EstadoOrdenServicioEnum::CANCELADA;
    }

    /** La fila viva de una línea congelada, buscada por el soft-link. Sin consultas. */
    private function vivoDe(OperacionOrdenServicioItem $item): ?OperacionServicio
    {
        $id = $item->getOperacionServicioId();

        if ($id === null) {
            return null;
        }

        foreach ($this->operacionServicios as $servicio) {
            if ((string) $servicio->getId() === $id) {
                return $servicio;
            }
        }

        return null;
    }

    private function etiqueta(OperacionOrdenServicioItem $item): string
    {
        return $item->getDescripcion() !== '' ? mb_substr($item->getDescripcion(), 0, 34) : 'un servicio';
    }

    private static function mismoImporte(string $congelado, string $negociado, string $cotizado): bool
    {
        $vivo = (float) $negociado > 0.0 ? $negociado : $cotizado;

        return abs((float) $congelado - (float) $vivo) < 0.005;
    }

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
