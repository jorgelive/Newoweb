<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use App\Travel\Enum\TarifaRolEnum;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\ComponenteEstadoEnum;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Operacion\Enum\EstadoOperacionEnum;
use App\Operacion\Enum\EstadoReservaProveedorEnum;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
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
            securityPostDenormalizeMessage: 'No tienes permiso para crear servicios operativos.'
        ),
        new Put(
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para editar servicios operativos.'
        ),
        new Patch(
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para actualizar servicios operativos.'
        ),
        new Delete(
            security: "is_granted('" . Roles::OPERACIONES_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar servicios operativos.'
        ),
    ],
    routePrefix: '/ops',
    normalizationContext: ['groups' => ['operacion:item:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['operacion:write']],
    // La Biblia se lee siempre en orden de despacho: primero el día, luego la hora.
    // Sin esto la colección llega en orden arbitrario de la BD y el cuadro es inservible.
    order: ['fechaServicio' => 'ASC', 'horaRecojo' => 'ASC'],
    paginationClientItemsPerPage: true,
    paginationItemsPerPage: 100
)]
#[ApiFilter(SearchFilter::class, properties: [
    'ordenServicio'                 => 'exact',
    'file'                          => 'exact',
    // Filtro por cotización: navega la asociación cotservicio → cotizacion.
    'cotizacionServicio.cotizacion' => 'exact',
    'estadoReservaProveedor'                 => 'exact',
    'estadoOperacion'               => 'exact',
    'compradorMaestroId'            => 'exact',
    'tipoComponente'                => 'exact',
    'modoComponente'                => 'exact',
    'estadoComponente'              => 'exact',
])]
// fechaServicio necesita DateFilter, no SearchFilter: 'exact' sólo permite un día suelto
// y el tráfico se planifica por rango (fechaServicio[after] / fechaServicio[before]).
#[ApiFilter(DateFilter::class, properties: ['fechaServicio'])]
#[ApiFilter(OrderFilter::class, properties: ['fechaServicio', 'horaRecojo', 'tipoComponente', 'descripcionServicio'])]
#[ORM\Entity]
#[ORM\Table(name: 'operacion_servicio')]
#[ORM\Index(columns: ['fecha_servicio'], name: 'idx_ops_servicio_fecha')]
#[ORM\Index(columns: ['tipo_componente'], name: 'idx_ops_servicio_tipo')]
// Un componente, una fila. La idempotencia del snapshot lo garantizaba con un
// findOneBy(), que es una comprobación y no una restricción: dos `aplicar` a la vez
// —doble clic con latencia, dos operadores sobre el mismo plan— pasaban los dos y
// creaban la fila los dos. Con filas duplicadas en el cuadro se compra dos veces.
#[ORM\UniqueConstraint(name: 'uniq_ops_servicio_componente', columns: ['cotizacion_componente_id'])]
#[ORM\HasLifecycleCallbacks]
class OperacionServicio
{
    use IdTrait;
    use TimestampTrait;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: OperacionOrdenServicio::class, inversedBy: 'operacionServicios')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OperacionOrdenServicio $ordenServicio = null;

    // ⚠️ Las tres referencias al árbol de la cotización eran RESTRICT, y eso hacía
    // IMPOSIBLE el flujo que el módulo promete. El editor guarda el árbol entero con un
    // PUT: lo que ya no está en el payload se orfaniza y Doctrine lo borra. Así que
    // quitar un día, borrar un componente o —lo más común de todo— reemplazar una
    // tarifa en una cotización ya confirmada terminaba en violación de FK: un 500 en
    // mitad del guardado, sin mensaje que explicara nada.
    //
    // CASCADE en las tres: si el componente, el día o el expediente desaparecen de la
    // cotización, la fila de tráfico se queda sin origen y no significa nada. Se pierde
    // lo que el operador hubiera escrito en ella, sí — pero es la consecuencia de que
    // una persona decidiera que ese servicio ya no va, no un accidente del sistema.
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    #[Groups(['operacion:item:read'])]
    #[ORM\ManyToOne(targetEntity: CotizacionCotservicio::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotservicio $cotizacionServicio = null;

    #[Groups(['operacion:item:read'])]
    #[ORM\ManyToOne(targetEntity: CotizacionCotcomponente::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotcomponente $cotizacionComponente = null;

    /**
     * Tarifa de la que salió el costo. **Nullable**: un componente sin tarifa también
     * genera fila.
     *
     * Un hotel que el pasajero reserva por su cuenta no se le compra a nadie y no lleva
     * tarifa, pero el transportista y el guía necesitan saber dónde recogerlo. Excluirlo
     * dejaba el cuadro de tráfico sin la referencia más básica del día. Ver
     * isSoloReferencia() y docs/Operacion.md §3.3.
     */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    // SET NULL y no CASCADE: sustituir una tarifa por otra es rutina de negociación, y
    // la fila tiene que sobrevivir para que la reconciliación le ponga la nueva. Con
    // CASCADE, cambiar de proveedor borraría el servicio del cuadro de tráfico.
    #[ORM\ManyToOne(targetEntity: CotizacionCottarifa::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CotizacionCottarifa $cotizacionTarifa = null;

    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $fechaServicio = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $horaRecojo = null;

    /**
     * La hora del componente TAL COMO SE VENDIÓ al cliente. No editable.
     *
     * `horaRecojo` es la que el operador fija para el recojo y puede diferir; ésta es la
     * referencia inmutable de la cotización. La fila muestra la de recojo (o ésta como
     * fallback si no hay), y debajo ésta siempre, como «vendida». Antes las dos eran el mismo
     * campo, así que fijar el recojo pisaba la hora vendida y se perdía la referencia.
     */
    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $horaComponente = null;

    /**
     * Dónde se recoge y dónde se deja, **si el operador lo fija a mano**.
     *
     * Vacío es lo normal y significa «usa lo que dice el catálogo»: el punto derivado se calcula
     * en caliente desde `cotizacionComponente` con {@see \App\Operacion\Service\OperacionPuntosDelServicio}.
     *
     * ⚠️ **No se guarda aquí una copia del derivado**, por la misma razón que
     * {@see self::isSoloReferencia()} es un cálculo y no una columna: duplicarlo abre la puerta a
     * que las dos se contradigan, y la copia muerta gana siempre porque es la que se lee. Corregir
     * el segmento en el catálogo tiene que arreglar todos los viajes a la vez.
     *
     * ⚠️ **Es un campo APARTE del derivado, igual que `horaRecojo` lo es de `horaComponente`.**
     * Esa separación está pagada: cuando las dos horas eran el mismo campo, fijar el recojo pisaba
     * la hora vendida y se perdía la referencia. Aquí pasaría lo mismo — escribir «puerta de
     * atrás» borraría para siempre que el catálogo decía «el hotel del pasajero».
     */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $puntoRecojo = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $puntoEntrega = null;

    /**
     * Enseñar el recojo y la entrega de ESTA línea aunque esté en medio de una cadena.
     *
     * Por defecto, una cadena de servicios del mismo prestador dice sólo dónde empieza y dónde
     * acaba —lo de en medio es logística suya, ver {@see OperacionOrdenServicio::rutasVisibles()}—.
     * Esto es la salida para cuando esa suposición no vale: un tramo que lo hace un subcontratado,
     * o un punto intermedio que el proveedor sí necesita por escrito.
     *
     * ⚠️ Sólo puede AÑADIR líneas, nunca quitarlas. Un interruptor que también sirviera para
     * ocultar el principio o el final de una cadena dejaría al proveedor sin saber dónde recoge —
     * y eso no es una preferencia de formato, es una orden que no se puede cumplir.
     */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(name: 'puntos_siempre_visibles', type: 'boolean', options: ['default' => false])]
    private bool $puntosSiempreVisibles = false;

    // ─────────────────────────────────────────────────────────────────────────
    // PRESTADOR — quién opera, frente a comprador* = a quién se le manda el encargo
    //
    // Los dos conviven porque en Operaciones hay dos consumidores con necesidades
    // opuestas: la Orden de Servicio necesita al COMPRADOR (a quién le mando la
    // solicitud) y el cuadro de tráfico necesita al PRESTADOR (dónde recojo, quién
    // opera). Un solo campo haciendo los dos trabajos era lo que obligaba a escribir
    // el hotel a mano como si fuera una observación.
    //
    // Viene resuelto de CotizacionCotcomponente::resolverPrestador() — componente →
    // día → proveedor del componente — así que en el caso normal coincide con el
    // comprador y nadie tiene que llenar nada.
    // ─────────────────────────────────────────────────────────────────────────

    #[Groups(['operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorMaestroId = null;

    #[Groups(['operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $prestadorNombre = null;

    /**
     * QUÉ le provee el prestador, con su nombre de servicio: «habitación suite superior», no
     * «Hotel 4 estrellas». Es lo primero que va en el voucher.
     *
     * Sale del `ProveedorServicio` del componente maestro. Si el componente no lo especifica,
     * queda nulo y `descripcionServicio` cae a los otros nombres (nombreParaProveedor, tarifa,
     * componente). Ver `resolverDescripcion()`.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $prestadorServicioNombre = null;

    /** El servicio del catálogo del que salió el nombre de arriba. Lo escribe el snapshot. */
    #[Groups(['operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorServicioMaestroId = null;

    // El TELÉFONO y la DIRECCIÓN no se guardan aquí, y es deliberado desde el 20/08/2026.
    //
    // Estuvieron como copia congelada por fila y acabaron en **0 de 42**: el snapshot los
    // ponía a `null` y la pantalla no tenía dónde escribirlos, así que sólo eran una segunda
    // fuente de verdad esperando a discrepar. Y eran asimétricos: el comprador —a quien de
    // verdad se le manda la Orden— nunca los tuvo, porque su contacto siempre se resolvió
    // vivo desde el catálogo por su id.
    //
    // Ahora los dos papeles hacen lo mismo: el contacto se resuelve contra
    // `TravelOrganizacion` por el id EFECTIVO. Corregir un teléfono en el catálogo lo corrige
    // en todas las filas, que es justo lo que una copia por fila impedía.

    // ─────────────────────────────────────────────────────────────────────────
    // COMPRADOR — a quién se le MANDA el encargo de comprar
    //
    // Tercer rol, distinto de los dos de arriba: el proveedor pone el precio, el
    // prestador presta el servicio y el comprador ejecuta la compra. Suele coincidir con
    // el proveedor —le compras directo— pero no cuando la tarifa es de un consorcio al
    // que nadie escribe: ahí el encargo va a una persona de casa o a un tercero.
    //
    // Siempre es un `Proveedor`, también los internos: «Openperu tickets» es una parte de
    // la empresa modelada como proveedor. Un solo catálogo, una sola pregunta.
    // ─────────────────────────────────────────────────────────────────────────

    #[Groups(['operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $compradorMaestroId = null;

    #[Groups(['operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $compradorNombre = null;

    // ─────────────────────────────────────────────────────────────────────────
    // LO QUE DECIDE OPERACIONES — el mismo par «cotizado / real» de la hora y el costo
    //
    // Arriba está lo que dijo la COTIZACIÓN; aquí lo que operaciones acaba haciendo. Es la
    // convención que esta entidad ya usa dos veces: `horaComponente` ↔ `horaRecojo`,
    // `costoCotizado` ↔ `costoNegociado`. Un tercer nombre para la misma idea sólo
    // haría que hubiera que aprenderla otra vez.
    //
    // 🔑 **Y es lo que disuelve el problema de la sincronización, no lo que lo resuelve.**
    // Antes había UNA columna para las dos cosas: el snapshot la escribía desde la
    // cotización y el operador la sobrescribía a mano, así que cada reconciliación tenía
    // que ADIVINAR quién había sido —comparando contra `snapshotOrigen`— y sacar un
    // conflicto para que decidiera una persona. Con dos columnas no hay nada que adivinar:
    //
    //     la cotización escribe SÓLO la de arriba   →  nunca pisa al operador
    //     el operador escribe SÓLO la de abajo      →  nunca lo pisa la cotización
    //     lo que vale = real ?? cotizado
    //
    // Por eso los campos de arriba perdieron `operacion:write`: si la API siguiera
    // aceptándolos, volvería a haber dos autores sobre la misma celda y el conflicto
    // regresaría por la puerta de atrás.
    //
    // ⚠️ Se guarda **id + nombre**, no texto suelto. La Orden de Servicio agrupa por
    // comprador, y un nombre tecleado no agrupa con la ficha del catálogo aunque se lea
    // igual: «Futurismo» y «Futurismo Jonathan» son dos órdenes distintas.
    // ─────────────────────────────────────────────────────────────────────────

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorOverrideMaestroId = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $prestadorOverrideNombre = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorServicioOverrideMaestroId = null;

    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $prestadorServicioOverrideNombre = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $compradorOverrideMaestroId = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $compradorOverrideNombre = null;

    /**
     * Cómo conoce el PROVEEDOR este servicio. Es lo que va en la Orden.
     *
     * Lo resuelve `BibliaSnapshotService::resolverDescripcion()` con respaldo:
     * `nombreParaProveedor` de la tarifa → nombre interno de la tarifa → nombre del
     * componente. La orden es el documento que se le manda a él, así que pedirle un código
     * interno que no reconoce es pedirle que adivine.
     */
    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 255)]
    private string $descripcionServicio;

    /**
     * El nombre INTERNO de la tarifa, siempre, sin resolver nada.
     *
     * Existe porque `descripcionServicio` **colapsa los dos nombres en uno**: en cuanto la
     * tarifa tiene `nombreParaProveedor`, el nombre interno desaparece de la pantalla. Y los
     * dos hacen falta a la vez — «Del Origen Al Presente de Lima» es lo que él entiende, y
     * «Pool City Lima CT002 (Base 1-4)» es lo que TÚ buscas en el tarifario cuando te
     * pregunta por qué le pagas eso.
     *
     * Nulo si el componente no tiene tarifa (fila de referencia) o si la tarifa no tiene
     * nombre interno; en ese caso `descripcionServicio` ya cae al nombre del componente y
     * repetirlo aquí sería ruido.
     */
    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $tarifaNombre = null;

    /**
     * Nombre del CotizacionCotservicio padre (el "día" del itinerario) en español.
     *
     * Se denormaliza en vez de serializar el cotservicio embebido porque su nombre vive en
     * un array i18n; el tráfico sólo necesita la etiqueta en español para saber a qué bloque
     * del itinerario pertenece la fila.
     */
    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $contextoServicio = null;

    /**
     * Snapshot de CotizacionCotcomponente::$tipo (valores de ComponenteTipoEnum).
     *
     * Se guarda como string suelto y no como enumType porque el origen también lo es
     * (?string): tipos legacy o vacíos no deben reventar la generación de La Biblia.
     * Es lo que permite distinguir un traslado de un desayuno sin volver a la cotización.
     */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $tipoComponente = null;

    /** Snapshot de ComponenteModoEnum: incluido, no_incluido, cortesia, reemplazado. */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $modoComponente = null;

    /** Snapshot de ComponenteEstadoEnum en el momento de confirmar la cotización. */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $estadoComponente = null;

    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'integer')]
    private int $cantidadPax = 1;

    /**
     * Cuántas UNIDADES del componente: noches de hotel, días de alquiler, tramos. Snapshot de
     * `CotizacionCotcomponente::$cantidad`.
     *
     * Se guarda aparte de `cantidadPax` porque son cosas distintas: 4 noches para 2 personas.
     * Sin esto, la orden decía «Hotel 4 estrellas» sin decir de cuántas noches, que es la
     * mitad de lo que se le encarga al hotel.
     */
    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $cantidadComponente = 1;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $montoVenta = '0.00';

    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $costoCotizado = '0.00';

    /** Nullable por la misma razón que $cotizacionTarifa: sin tarifa no hay moneda. */
    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_cotizada', referencedColumnName: 'id', nullable: true)]
    private ?MaestroMoneda $monedaCotizada = null;

    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $costoNegociado = '0.00';

    #[Groups(['operacion:read', 'operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_negociada', referencedColumnName: 'id', nullable: true)]
    private ?MaestroMoneda $monedaNegociada = null;

    /**
     * ¿El proveedor ya me confirmó este servicio? Es el estado que dice si **la plaza existe**.
     *
     * ⚠️ Uno de TRES estados que suenan parecido y no lo son. Al pasajero se le contesta con
     * éste; los otros dos no significan nada para quien viaja:
     *   - `$estadoOperacion` → ¿el servicio ya ocurrió?
     *   - `OperacionOrdenServicio::$estadoOs` → ¿en qué punto está el papeleo de la compra?
     *
     * Se llamaba `estadoReserva` a secas y era una trampa: en este sistema «reserva» es la del
     * huésped ({@see \App\Pms\Entity\PmsReserva}), y ésta es la que le hago YO al proveedor.
     */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(name: 'estado_reserva_proveedor', type: 'string', length: 30, enumType: EstadoReservaProveedorEnum::class, options: ['default' => 'sin-solicitar'])]
    private EstadoReservaProveedorEnum $estadoReservaProveedor = EstadoReservaProveedorEnum::SIN_SOLICITAR;

    /**
     * Desde cuándo el servicio está en el estado de reserva ACTUAL.
     *
     * Lo actualiza el listener de bitácora en cada cambio. Es un campo directo y no una
     * consulta a la bitácora porque «desde hace 3h» se pinta en cada fila del cuadro: mirarlo
     * en una tabla aparte por fila serían N joins para el caso más común. La historia completa
     * sí vive en la bitácora, a un clic.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $estadoReservaProveedorDesde = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: EstadoOperacionEnum::class, options: ['default' => 'pendiente'])]
    private EstadoOperacionEnum $estadoOperacion = EstadoOperacionEnum::PENDIENTE;

    /**
     * Valores EXACTOS que escribió el snapshot la última vez, en forma escalar.
     *
     * Es lo que hace posible reconciliar en vez de borrar y recrear. Sin esta foto no
     * se puede responder la única pregunta que importa al comparar una fila con la
     * cotización: si difieren, **¿quién de los dos se movió?** Con ella:
     *
     *   actual == origen  →  el operador no lo tocó  → la cotización manda, se actualiza
     *   actual != origen  →  el operador lo editó    → CONFLICTO, decide una persona
     *
     * No se serializa a la API: es memoria interna del reconciliador, no un dato
     * operativo. Ver BibliaReconciliacionService y docs/Operacion.md §3.5.
     *
     * @var array<string, string|int|null>
     */
    #[ORM\Column(type: 'json')]
    private array $snapshotOrigen = [];

    public function __construct()
    {
        $this->initializeId();
    }

    #[Groups(['operacion:item:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['operacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    /**
     * La fila está en el cuadro de tráfico como REFERENCIA, no como compra.
     *
     * Dos casos, y los dos comparten la misma consecuencia: no se le pide nada a ningún
     * proveedor, así que no puede entrar en una Orden de Servicio.
     *
     *  - **Sin tarifa.** Nadie lo cotizó porque no se compra.
     *  - **`no_incluido`.** El pasajero lo paga por su cuenta (el hotel que reservó él).
     *
     * Y sin embargo tienen que verse: el transportista necesita el hotel para el recojo y
     * el guía necesita el vuelo para saber a qué hora deja de tener al grupo. Excluirlos
     * dejaba el cuadro sin la referencia más básica del día. Ver docs/Operacion.md §3.3.
     *
     * Es un cálculo y no una columna a propósito: se deriva de datos que ya están en la
     * fila, y duplicarlo en una columna abriría la puerta a que las dos se contradigan.
     * El frontend lo consume desde la API — la regla no se reimplementa en TypeScript.
     */
    #[Groups(['operacion:item:read'])]
    public function isSoloReferencia(): bool
    {
        return $this->cotizacionTarifa === null
            || $this->modoComponente === ComponenteModoEnum::NO_INCLUIDO->value;
    }

    /**
     * ¿Se le puede pedir formalmente a un proveedor?
     *
     * Es más estrecho que `!isSoloReferencia()`: además de lo que no se compra, quedan
     * fuera los servicios que **ya no se operan**. Un componente cancelado por el
     * cliente o sustituido por otro conserva su tarifa, así que pasaba todas las
     * comprobaciones anteriores y podía colarse en una Orden de Servicio junto al resto
     * del día — la vista los atenúa, pero atenuar no impide marcar la casilla. El
     * resultado era pedirle y pagarle a un proveedor un servicio que nadie va a usar.
     */
    public function esComprable(): bool
    {
        return !$this->isSoloReferencia()
            && $this->estadoComponente !== ComponenteEstadoEnum::CANCELADO->value
            && $this->modoComponente !== ComponenteModoEnum::REEMPLAZADO->value;
    }

    /** Motivo por el que no se puede comprar, o null si sí se puede. Se muestra al operador. */
    public function motivoNoComprable(): ?string
    {
        if ($this->isSoloReferencia()) {
            return 'está en La Biblia sólo como referencia (no incluido o sin tarifa): no se le compra a ningún proveedor';
        }
        if ($this->estadoComponente === ComponenteEstadoEnum::CANCELADO->value) {
            return 'está cancelado en la cotización: pedirlo sería comprar algo que el cliente no quiere';
        }
        if ($this->modoComponente === ComponenteModoEnum::REEMPLAZADO->value) {
            return 'fue reemplazado por otro servicio en la cotización';
        }

        return null;
    }

    public function getOrdenServicio(): ?OperacionOrdenServicio { return $this->ordenServicio; }

    public function setOrdenServicio(?OperacionOrdenServicio $ordenServicio): self
    {
        // La regla vive aquí y no sólo en la vista: una OS es una solicitud formal de
        // compra a un proveedor, y meterle un servicio que nadie compra produce un
        // documento con un importe que no se debe. API Platform mapea DomainException
        // a 422 (config/packages/api_platform.yaml), así que el PATCH devuelve el motivo.
        if ($ordenServicio !== null && ($motivo = $this->motivoNoComprable()) !== null) {
            throw new \DomainException(sprintf(
                '«%s» %s, y no puede entrar en una Orden de Servicio.',
                $this->descripcionServicio ?? 'El servicio',
                $motivo
            ));
        }

        // Coherencia con la cabecera. Vivía SÓLO en `conflictoSeleccion` del navegador,
        // así que dos pestañas abiertas o cualquier otro consumidor de la API podían
        // armar una OS con filas de dos expedientes o de dos monedas — un documento que
        // el proveedor no puede firmar y un importe que no cuadra con sus líneas.
        if ($ordenServicio !== null) {
            $fileOs = $ordenServicio->getFile();
            if ($fileOs !== null && $this->file !== null && $fileOs->getId() != $this->file->getId()) {
                throw new \DomainException(sprintf(
                    'La Orden de Servicio %s es del expediente «%s» y «%s» pertenece a otro. Una OS es una solicitud sobre un solo expediente.',
                    $ordenServicio->getNumeroOs() ?? '(sin número)',
                    $fileOs->getNombreGrupo() ?? '—',
                    $this->descripcionServicio ?? 'este servicio'
                ));
            }

            // 🔓 Las MONEDAS DISTINTAS ya no bloquean, y el motivo desapareció con la causa.
            //
            // Esto impedía meter en una orden un servicio en soles y otro en dólares, porque
            // «deja un total que no suma». El problema no era mezclar: era que el total vivía
            // en la cabecera. Un proveedor cobra unos servicios en soles y otros en dólares, y
            // partir eso en dos órdenes parte una gestión que en la vida real es una sola.
            //
            // El importe vive ahora en cada ítem con su propia moneda —cotizado y negociado— y
            // la pantalla suma por moneda. Ver `OperacionOrdenServicio::$totalOs`.
        }

        $this->ordenServicio = $ordenServicio;

        return $this;
    }

    /**
     * Guarda simétrica de la anterior: una fila que YA está en una Orden de Servicio no
     * puede dejar de ser comprable.
     *
     * `setOrdenServicio()` sólo vigila la entrada, y con eso no basta: la reconciliación
     * puede aprobar un `modoComponente → no_incluido`, o dejar la tarifa en null al
     * desaparecer del árbol, y la fila se convertía en referencia **dentro** de una OS ya
     * emitida. La OS quedaba conteniendo un importe que no se debe, y `totalOs` seguía
     * sumándolo.
     *
     * Se llama desde prePersist/preUpdate: cubre por igual la reconciliación, un PATCH
     * suelto y el comando de consola, que es justo lo que la guarda de entrada no cubría.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function verificarCoherenciaConOrdenServicio(): void
    {
        if ($this->ordenServicio === null) {
            return;
        }

        if (($motivo = $this->motivoNoComprable()) !== null) {
            throw new \DomainException(sprintf(
                '«%s» pertenece a la Orden de Servicio %s y ahora %s. Sácalo de la orden antes de aplicar este cambio.',
                $this->descripcionServicio ?? 'El servicio',
                $this->ordenServicio->getNumeroOs() ?? '(sin número)',
                $motivo
            ));
        }
    }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    public function getCotizacionServicio(): ?CotizacionCotservicio { return $this->cotizacionServicio; }
    public function setCotizacionServicio(?CotizacionCotservicio $cotizacionServicio): self { $this->cotizacionServicio = $cotizacionServicio; return $this; }

    /**
     * El maestro del ÚNICO segmento, y sólo cuando el servicio es mono-segmento SIN plantilla.
     *
     * En ese caso el nombre del servicio es genérico («Alojamiento») y el que dice qué cosa es
     * lo lleva el segmento. La Biblia usa este id para resolver EN VIVO el nombre interno del
     * segmento contra el maestro —igual que los contactos de proveedor—, sin snapshot ni
     * reconciliación. `null` cuando hay plantilla, o 0 / varios segmentos: ahí manda el nombre
     * del servicio, que es una cabecera y no un tramo.
     */
    #[Groups(['operacion:item:read'])]
    public function getSegmentoUnicoMaestroId(): ?string
    {
        $servicio = $this->cotizacionServicio;
        if ($servicio === null || $servicio->getItinerarioMaestroId() !== null) {
            return null;
        }
        $segmentos = $servicio->getCotsegmentos();
        if ($segmentos->count() !== 1) {
            return null;
        }
        $unico = $segmentos->first();
        return $unico === false ? null : $unico->getSegmentoMaestroId();
    }

    public function getCotizacionComponente(): ?CotizacionCotcomponente { return $this->cotizacionComponente; }
    public function setCotizacionComponente(?CotizacionCotcomponente $cotizacionComponente): self { $this->cotizacionComponente = $cotizacionComponente; return $this; }

    public function getCotizacionTarifa(): ?CotizacionCottarifa { return $this->cotizacionTarifa; }
    public function setCotizacionTarifa(?CotizacionCottarifa $cotizacionTarifa): self { $this->cotizacionTarifa = $cotizacionTarifa; return $this; }

    public function getFechaServicio(): ?\DateTimeImmutable { return $this->fechaServicio; }
    public function setFechaServicio(\DateTimeImmutable $fechaServicio): self { $this->fechaServicio = $fechaServicio; return $this; }

    public function getEstadoReservaProveedorDesde(): ?\DateTimeImmutable { return $this->estadoReservaProveedorDesde; }
    public function setEstadoReservaProveedorDesde(?\DateTimeImmutable $d): self { $this->estadoReservaProveedorDesde = $d; return $this; }

    public function getHoraRecojo(): ?string { return $this->horaRecojo; }

    public function getPuntoRecojo(): ?string { return $this->puntoRecojo; }
    public function setPuntoRecojo(?string $v): self { $this->puntoRecojo = $this->limpiar($v); return $this; }

    public function getPuntoEntrega(): ?string { return $this->puntoEntrega; }
    public function setPuntoEntrega(?string $v): self { $this->puntoEntrega = $this->limpiar($v); return $this; }

    public function isPuntosSiempreVisibles(): bool { return $this->puntosSiempreVisibles; }
    public function setPuntosSiempreVisibles(bool $v): self { $this->puntosSiempreVisibles = $v; return $this; }

    /**
     * Una cadena en blanco vuelve a ser `null`, y no es cosmética.
     *
     * Vacío significa «usa el catálogo», así que un campo que el operador limpió en el formulario
     * tiene que volver a delegar. Guardado como `''` seguiría contando como override y taparía el
     * derivado para siempre, con un valor que no dice nada.
     */
    private function limpiar(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v !== '' ? $v : null;
    }
    public function setHoraRecojo(?string $horaRecojo): self { $this->horaRecojo = $horaRecojo; return $this; }

    public function getHoraComponente(): ?string { return $this->horaComponente; }
    public function setHoraComponente(?string $h): self { $this->horaComponente = $h; return $this; }

    public function getPrestadorServicioNombre(): ?string { return $this->prestadorServicioNombre; }
    public function setPrestadorServicioNombre(?string $n): self { $this->prestadorServicioNombre = $n; return $this; }

    public function getCompradorMaestroId(): ?string { return $this->compradorMaestroId; }
    public function setCompradorMaestroId(?string $v): self { $this->compradorMaestroId = $v; return $this; }

    public function getCompradorNombre(): ?string { return $this->compradorNombre; }
    public function setCompradorNombre(?string $v): self { $this->compradorNombre = $v; return $this; }

    public function getPrestadorServicioMaestroId(): ?string { return $this->prestadorServicioMaestroId; }
    public function setPrestadorServicioMaestroId(?string $v): self { $this->prestadorServicioMaestroId = $v; return $this; }

    public function getPrestadorOverrideMaestroId(): ?string { return $this->prestadorOverrideMaestroId; }
    public function setPrestadorOverrideMaestroId(?string $v): self { $this->prestadorOverrideMaestroId = $v; return $this; }

    public function getPrestadorOverrideNombre(): ?string { return $this->prestadorOverrideNombre; }
    public function setPrestadorOverrideNombre(?string $v): self { $this->prestadorOverrideNombre = $v; return $this; }

    public function getPrestadorServicioOverrideMaestroId(): ?string { return $this->prestadorServicioOverrideMaestroId; }
    public function setPrestadorServicioOverrideMaestroId(?string $v): self { $this->prestadorServicioOverrideMaestroId = $v; return $this; }

    public function getPrestadorServicioOverrideNombre(): ?string { return $this->prestadorServicioOverrideNombre; }
    public function setPrestadorServicioOverrideNombre(?string $v): self { $this->prestadorServicioOverrideNombre = $v; return $this; }

    public function getCompradorOverrideMaestroId(): ?string { return $this->compradorOverrideMaestroId; }
    public function setCompradorOverrideMaestroId(?string $v): self { $this->compradorOverrideMaestroId = $v; return $this; }

    public function getCompradorOverrideNombre(): ?string { return $this->compradorOverrideNombre; }
    public function setCompradorOverrideNombre(?string $v): self { $this->compradorOverrideNombre = $v; return $this; }

    // ─────────────────────────────────────────────────────────────────────────
    // LO QUE VALE — real si operaciones decidió otra cosa, cotizado si no
    //
    // ⚠️ **Todo lo que actúe sobre estos tres papeles pasa por aquí**: la Orden de Servicio,
    // el voucher, el cuadro de tráfico y los reportes. Leer `getCompradorNombre()` a secas es
    // leer lo que dijo la cotización e ignorar lo que hizo el equipo — que es exactamente el
    // fallo que estos campos vienen a impedir.
    //
    // Se exponen a la API porque el front los pinta, y calcularlos allí sería el mismo espejo
    // en dos idiomas.
    // ─────────────────────────────────────────────────────────────────────────

    /** El id del prestador que vale. */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getPrestadorEfectivoMaestroId(): ?string
    {
        return $this->prestadorOverrideMaestroId ?? $this->prestadorMaestroId;
    }

    /** El nombre del prestador que vale. */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getPrestadorEfectivoNombre(): ?string
    {
        return self::primeroConTexto($this->prestadorOverrideNombre, $this->prestadorNombre);
    }

    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getPrestadorServicioEfectivoNombre(): ?string
    {
        return self::primeroConTexto($this->prestadorServicioOverrideNombre, $this->prestadorServicioNombre);
    }

    /**
     * El id del comprador que vale. **Es por el que agrupa la Orden de Servicio.**
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getCompradorEfectivoMaestroId(): ?string
    {
        return $this->compradorOverrideMaestroId ?? $this->compradorMaestroId;
    }

    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getCompradorEfectivoNombre(): ?string
    {
        return self::primeroConTexto($this->compradorOverrideNombre, $this->compradorNombre);
    }

    /** ¿Operaciones cambió alguno de los tres? Para pintar el «cotizado: …» al lado. */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function hasPapelIntervenido(): bool
    {
        return $this->prestadorOverrideMaestroId !== null
            || $this->prestadorServicioOverrideMaestroId !== null
            || $this->compradorOverrideMaestroId !== null;
    }

    /**
     * Cadena vacía cuenta como ausente.
     *
     * Un `''` guardado por un formulario que se dejó en blanco significa «no lo puse», no
     * «quiero que no haya nada»: sin esto, borrar el campo taparía el valor cotizado con un
     * hueco en vez de devolver el original.
     */
    private static function primeroConTexto(?string $real, ?string $cotizado): ?string
    {
        return trim($real ?? '') !== '' ? $real : $cotizado;
    }

    public function getPrestadorMaestroId(): ?string { return $this->prestadorMaestroId; }
    public function setPrestadorMaestroId(?string $v): self { $this->prestadorMaestroId = $v; return $this; }

    public function getPrestadorNombre(): ?string { return $this->prestadorNombre; }
    public function setPrestadorNombre(?string $v): self { $this->prestadorNombre = $v; return $this; }

    /**
     * El id como texto, EMBEBIDO en la orden.
     *
     * Los servicios de una orden se serializan con `operacion:read`, que —al contrario que
     * `operacion:item:read`, el de La Biblia— NO lleva el `id` del `IdTrait`. Sin él, el
     * detalle de la orden no podía distinguir una fila de otra: todos los editores de importe
     * compartían la misma clave vacía y escribir en uno los pintaba todos iguales, y el PATCH
     * de guardado se quedaba sin a quién apuntar.
     *
     * Se expone aparte y no se mete el `id` entero en `operacion:read` para no cambiar la
     * forma del identificador del recurso —el `@id` de JSON-LD— sólo por esto. El front lo lee
     * con el mismo helper que el id de La Biblia.
     */
    #[Groups(['operacion:read'])]
    public function getServicioId(): ?string
    {
        return $this->getId()?->toRfc4122();
    }

    /**
     * El id de la COTIZACIÓN de la que cuelga el servicio, para saltar a su editor.
     *
     * En `operacion:read` (embebido en la orden) y en `item:read` (La Biblia): desde las dos
     * pantallas se abre el expediente y desde ahí, la cotización. Sale navegando
     * servicio → cotservicio → cotización; se expone resuelto para no arrastrar el árbol.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getCotizacionId(): ?string
    {
        return $this->cotizacionServicio?->getCotizacion()?->getId()?->toRfc4122();
    }

    /**
     * La versión de la COTIZACIÓN de la que cuelga el servicio. Con el localizador del file
     * forma el identificador que el operador copia y comparte —«HJDLDB-v1»— y el enlace a la
     * vista del cliente (`/file/{localizador}/v/{version}` en pax).
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getCotizacionVersion(): ?int
    {
        return $this->cotizacionServicio?->getCotizacion()?->getVersion();
    }

    /**
     * De dónde sale el `costoCotizado`: una línea por tarifa, con su unitario y sus factores.
     *
     * Es la MISMA fórmula que congela el snapshot y que usa el cotizador —
     * `montoCosto × cantidad_tarifa × unidades_componente`, sobre las tarifas que no son
     * ALTERNATIVA— pero desglosada, para que un tooltip explique el número en vez de que el
     * operador tenga que confiar en él. `cantidad` es el factor por pax/unidad de la tarifa;
     * `unidades`, las noches/días del componente. Se resuelve en vivo desde la cotización.
     *
     * @return list<array{concepto: string, unitario: string, cantidad: int, unidades: int, subtotal: string, moneda: ?string}>
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    #[ApiProperty(openapiContext: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['concepto', 'unitario', 'cantidad', 'unidades', 'subtotal'],
            'properties' => [
                'concepto'  => ['type' => 'string'],
                'unitario'  => ['type' => 'string'],
                'cantidad'  => ['type' => 'integer'],
                'unidades'  => ['type' => 'integer'],
                'subtotal'  => ['type' => 'string'],
                'moneda'    => ['type' => 'string', 'nullable' => true],
            ],
        ],
    ])]
    public function getDesgloseCotizado(): array
    {
        $componente = $this->cotizacionComponente;
        if ($componente === null) {
            return [];
        }

        $unidades = max(1, $componente->getCantidad());
        $lineas = [];

        foreach ($componente->getCottarifas() as $tarifa) {
            if (TarifaRolEnum::tryFrom($tarifa->getRolSnapshot() ?? '') === TarifaRolEnum::ALTERNATIVA) {
                continue;
            }
            $cantidad = max(1, $tarifa->getCantidad());
            $subtotal = (float) $tarifa->getMontoCosto() * $cantidad * $unidades;

            $lineas[] = [
                'concepto' => $tarifa->getNombreInternoSnapshot() ?? ($tarifa->getCategoriaSnapshot() ?? 'Tarifa'),
                'unitario' => $tarifa->getMontoCosto(),
                'cantidad' => $cantidad,
                'unidades' => $unidades,
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'moneda'   => $tarifa->getMoneda()?->getId(),
            ];
        }

        return $lineas;
    }

    public function getDescripcionServicio(): string { return $this->descripcionServicio; }
    public function setDescripcionServicio(string $descripcionServicio): self { $this->descripcionServicio = $descripcionServicio; return $this; }

    public function getTarifaNombre(): ?string { return $this->tarifaNombre; }
    public function setTarifaNombre(?string $tarifaNombre): self { $this->tarifaNombre = $tarifaNombre; return $this; }

    public function getContextoServicio(): ?string { return $this->contextoServicio; }
    public function setContextoServicio(?string $contextoServicio): self { $this->contextoServicio = $contextoServicio; return $this; }

    public function getTipoComponente(): ?string { return $this->tipoComponente; }
    public function setTipoComponente(?string $tipoComponente): self { $this->tipoComponente = $tipoComponente; return $this; }

    public function getModoComponente(): ?string { return $this->modoComponente; }
    public function setModoComponente(?string $modoComponente): self { $this->modoComponente = $modoComponente; return $this; }

    public function getEstadoComponente(): ?string { return $this->estadoComponente; }
    public function setEstadoComponente(?string $estadoComponente): self { $this->estadoComponente = $estadoComponente; return $this; }


    /**
     * La posición de este servicio DENTRO de su itinerario, para leer el cuadro en orden natural.
     *
     * `día × 1000 + orden del segmento`. Los componentes que cuelgan del mismo segmento comparten
     * número y se desempatan por hora, que es exactamente como se leen: «el ascenso en bus y su
     * ingreso van juntos, y dentro de eso manda el reloj».
     *
     * ⚠️ **Es la vista que pide el operador, y no coincide con la del reloj.** Un cuadro ordenado
     * sólo por hora parte el relato: el alojamiento y la comida, que no tienen hora, caen al final
     * del día lejos del servicio al que pertenecen. El itinerario los deja donde el cliente los va
     * a vivir. La ordenación por hora sigue estando, como interruptor.
     *
     * ⚠️ **Se calcula, no se guarda.** Duplicarlo en una columna abriría la puerta a que el cuadro
     * y la cotización se contradigan al reordenar un segmento — el mismo motivo por el que
     * `isSoloReferencia()` tampoco es columna.
     *
     * 🚧 Coste: serializar una página carga el cotcomponente y su cotsegmento de cada fila. Con
     * 200 por página son 400 lecturas perezosas. Se acepta de momento; si pesa, se resuelve con un
     * `fetch join` en la extensión de la colección, no guardando una copia.
     */
    #[Groups(['operacion:read', 'operacion:item:read'])]
    public function getOrdenItinerario(): ?int
    {
        $segmento = $this->cotizacionComponente?->getCotsegmento();

        if ($segmento === null) {
            return null;
        }

        return $segmento->getDia() * 1000 + $segmento->getOrden();
    }
    /**
     * Prioridad de despacho heredada de ComponenteTipoEnum::prioridad().
     *
     * Se expone calculada (no se persiste) para que la vista ordene las filas sin horario
     * por relevancia operativa — guiado/transporte antes que tickets — sin duplicar la
     * tabla de prioridades en TypeScript. Los tipos desconocidos van al final.
     */
    #[Groups(['operacion:item:read'])]
    public function getPrioridadOperativa(): int
    {
        return ComponenteTipoEnum::tryFrom($this->tipoComponente ?? '')?->prioridad() ?? 9;
    }

    public function getCantidadPax(): int { return $this->cantidadPax; }
    public function setCantidadPax(int $cantidadPax): self { $this->cantidadPax = $cantidadPax; return $this; }

    public function getCantidadComponente(): int { return $this->cantidadComponente; }
    public function setCantidadComponente(int $cantidadComponente): self { $this->cantidadComponente = $cantidadComponente; return $this; }

    public function getMontoVenta(): string { return $this->montoVenta; }
    public function setMontoVenta(string $montoVenta): self { $this->montoVenta = $montoVenta; return $this; }

    public function getCostoCotizado(): string { return $this->costoCotizado; }
    public function setCostoCotizado(string $costoCotizado): self { $this->costoCotizado = $costoCotizado; return $this; }

    public function getMonedaCotizada(): ?MaestroMoneda { return $this->monedaCotizada; }
    public function setMonedaCotizada(?MaestroMoneda $monedaCotizada): self { $this->monedaCotizada = $monedaCotizada; return $this; }

    public function getCostoNegociado(): string { return $this->costoNegociado; }
    public function setCostoNegociado(string $costoNegociado): self { $this->costoNegociado = $costoNegociado; return $this; }

    public function getMonedaNegociada(): ?MaestroMoneda { return $this->monedaNegociada; }
    public function setMonedaNegociada(?MaestroMoneda $monedaNegociada): self { $this->monedaNegociada = $monedaNegociada; return $this; }

    public function getEstadoReservaProveedor(): EstadoReservaProveedorEnum { return $this->estadoReservaProveedor; }
    public function setEstadoReservaProveedor(EstadoReservaProveedorEnum $estadoReservaProveedor): self { $this->estadoReservaProveedor = $estadoReservaProveedor; return $this; }

    public function getEstadoOperacion(): EstadoOperacionEnum { return $this->estadoOperacion; }
    public function setEstadoOperacion(EstadoOperacionEnum $estadoOperacion): self { $this->estadoOperacion = $estadoOperacion; return $this; }

    /** @return array<string, string|int|null> */
    public function getSnapshotOrigen(): array { return $this->snapshotOrigen; }

    /** @param array<string, string|int|null> $v */
    public function setSnapshotOrigen(array $v): self { $this->snapshotOrigen = $v; return $this; }
}
