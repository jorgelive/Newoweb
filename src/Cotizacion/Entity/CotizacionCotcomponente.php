<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\AutoTranslate;
use App\Cotizacion\Dto\CompradorResuelto;
use App\Cotizacion\Enum\ComponenteEstadoEnum;
use App\Cotizacion\Enum\AudienciaDetalleEnum;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Travel\Enum\ComponenteModoEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Logística inmutable. Congela los ítems bilingües, su estado y horarios precisos.
 */
#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_cotcomponente')]
// El soft-link al catálogo no tiene FK a propósito, pero sí necesita índice: es la columna
// contra la que el filtro de lugares del cuadro de tráfico lanza su `IN (...)`.
// Ver App\Operacion\Filter\OperacionServicioLugarExtension.
#[ORM\Index(columns: ['componente_maestro_id'], name: 'idx_cotcomponente_maestro')]
#[ORM\HasLifecycleCallbacks]
class CotizacionCotcomponente
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionCotservicio::class, inversedBy: 'cotcomponentes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotservicio $cotservicio = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\ManyToOne(targetEntity: CotizacionSegmento::class, cascade: ['persist'], inversedBy: 'cotcomponentes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CotizacionSegmento $cotsegmento = null;

    /**
     * A QUIÉN aplica este componente. **Vacío = a todos.**
     *
     * ── El caso que lo trajo ────────────────────────────────────────────────
     * Se cotiza un grupo entero con un vuelo. Después la realidad se parte: unos van en el
     * nacional y otros en el internacional. El servicio «Vuelo» pasa a tener **dos componentes**, y
     * las cantidades por componente **dejan de sumar el total del grupo** — que es correcto, y es
     * justo lo que antes no se podía representar.
     *
     * ── 🔥 Por qué VARIOS y no uno ─────────────────────────────────────────
     * Empezó siendo `?CotizacionFileGrupo`, uno solo, y se rompió contra los datos reales. El
     * vuelo JA7018 del 17 de setiembre lleva **32 personas repartidas en 7 PNRs**: con un solo
     * subgrupo harían falta **siete copias del componente** —mismo vuelo, misma hora, misma orden
     * al proveedor— sólo porque el modelo no sabía decir «estos siete».
     *
     * ```
     * H2 5002 · 06:50    2 PNRs    88 personas   → 1 componente
     * JA7018  · 07:15    7 PNRs    32 personas   → 1 componente
     * JA7030  · 20:05    1 PNR      2 personas   → 1 componente
     * ```
     *
     * ⚠️ **Y no se ata al VUELO**, que fue la otra idea. Apuntar a `CotizacionVuelo` resolvía el
     * caso aéreo y **sólo** ése. Con una lista de subgrupos el mismo campo sirve para «los de la
     * habitación 101 y 102», para «los que sí van a Coco Bongo» y para cualquier corte que alguien
     * invente mañana con el eje `GRUPO`, que es texto libre. El acotador general es la gente, no
     * el medio de transporte.
     *
     * ── ⚠️ Vacío significa TODOS, y es deliberado ──────────────────────────
     * Misma decisión que en skills, guía y conocimiento: **lista vacía = sin acotar**. Un olvido al
     * clasificar deja el componente **de más** —alguien lo ve y lo corrige— en vez de invisible,
     * que no se descubre nunca. Y hace barato el caso normal: partir un vuelo no obliga a etiquetar
     * los otros veinte componentes.
     *
     * ⚠️ **Los subgrupos son del EXPEDIENTE, no de la cotización**, así que sobreviven a abrir la
     * operativa: los mismos «#Vuelo Nacional» siguen valiendo.
     *
     * ⚠️ **Contar gente es una UNIÓN, no una suma.** Dos subgrupos pueden compartir personas, así
     * que quien mida cobertura tiene que contar pasajeros DISTINTOS. Sumar `totalMiembros` daría de
     * más justo donde el solape importa.
     *
     * @var Collection<int, CotizacionFileGrupo>
     */
    // 🔥 **SIN `pax_cotizacion:read`.** Con el singular sólo viajaba el subgrupo propio; con el
    // plural, un componente acotado a los 7 PNRs del vuelo JA7018 servía **los siete** a cualquiera
    // de ellos —y `CotizacionFileGrupo` expone `clave`, que es el localizador—. Con un apellido,
    // ése es el dato con el que se entra a gestionar la reserva de otro en la web de la aerolínea.
    //
    // Es exactamente lo que `CotizacionFile::$miIdentidad` se construyó para impedir, entrando por
    // la puerta de al lado. El cliente no necesita los subgrupos: ya recibe **filtrado** lo que le
    // toca, y lo suyo se lo cuenta «Lo tuyo».
    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\ManyToMany(targetEntity: CotizacionFileGrupo::class)]
    #[ORM\JoinTable(name: 'cotizacion_cotcomponente_grupo')]
    #[ORM\JoinColumn(name: 'cotcomponente_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'grupo_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $grupos;

    /**
     * El nombre PÚBLICO de la línea que se compra: el que ve el cliente.
     *
     * ⚠️ **No confundir con `$nombreInternoSnapshot`**, que es el OPERATIVO. La diferencia no es
     * de dónde vienen sino para quién son, y decide quién gana en
     * `BibliaSnapshotService::resolverNombreComponente()`:
     *
     * ```
     * nombreInternoSnapshot   OPERATIVO   lo escribe SIEMPRE una persona   → manda
     * tituloSnapshot (éste)   PÚBLICO     copiado del maestro (197) o a mano (2)
     * ```
     *
     * Por eso éste va el ÚLTIMO: al venir casi siempre copiado del catálogo, es una foto que
     * envejece. Si mandara, un componente cuyo maestro se renombró enseñaría el nombre viejo para
     * siempre — el caso del vuelo, cuya copia dice «Ticket aereo» y el maestro «Vuelo Lima Cusco».
     *
     * ⚠️ El mapa de los cuatro nombres del árbol está en `docs/Cotizaciones.md` §2.b.
     *
     * @var list<array{language?: string, content?: string|null}>
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $tituloSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $cantidad = 1;

    /**
     * ⚠️ El default de la COLUMNA tiene que coincidir con un `case` del enum. Estuvo
     * en `'Pendiente'` con mayúscula, un valor que `ComponenteEstadoEnum::from()` no
     * acepta: cualquier fila insertada sin este campo —un INSERT crudo, una carga de
     * datos— reventaba la hidratación con un ValueError. No pasaba porque Doctrine
     * siempre escribe la propiedad, pero la mina estaba puesta.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ComponenteEstadoEnum::class, options: ['default' => 'activo'])]
    private ComponenteEstadoEnum $estado = ComponenteEstadoEnum::ACTIVO;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ComponenteModoEnum::class, options: ['default' => 'incluido'])]
    private ComponenteModoEnum $modo = ComponenteModoEnum::INCLUIDO;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaHoraInicio = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaHoraFin = null;

    /** @var list<array<string, mixed>> */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['tituloSnapshot'], format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $snapshotItems = [];

    /** @var Collection<int, CotizacionCottarifa> */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\OneToMany(mappedBy: 'cotcomponente', targetEntity: CotizacionCottarifa::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cottarifas;

    // `operacion:item:read` está aquí para que el cuadro de tráfico pueda pintar las
    // etiquetas de lugar de cada fila resolviéndolas EN LOTE: con el id del maestro a mano,
    // la vista junta los distintos y hace una sola llamada a /travel/componentes?id[]=…
    // Sin esto haría falta una petición por fila para llegar al catálogo.
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $componenteMaestroId = null;

    /**
     * Este componente **no viene del catálogo y no va a venir**.
     *
     * ⚠️ No es lo mismo que `componenteMaestroId === null`, y por eso es un campo y no un
     * cálculo: un componente recién creado tampoco tiene maestro, pero está *esperando* que le
     * elijan uno. Esto dice que **ya se decidió que no lo tendrá** — un traslado a un fundo
     * concreto, una parada irrepetible—, y de esa decisión cuelga que el editor esconda el
     * buscador de insumos y pida los nombres a mano.
     *
     * Inferirlo de que el nombre interno esté relleno funcionaría hasta el primer manual sin
     * nombre, y entonces volvería a pedir un maestro sin que nadie entendiera por qué.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(name: 'es_manual', type: 'boolean', options: ['default' => false])]
    private bool $esManual = false;

    /**
     * De qué componente es COPIA. `null` = es un original.
     *
     * ── Para qué existe ─────────────────────────────────────────────────────
     * Partir un vuelo en nacional e internacional es **dos componentes en el MISMO segmento**: así
     * comparten relato —«vuelas de Cusco a Lima» es idéntico— y se distinguen por `grupo`. La
     * copia se crea con «Duplicar», no con «+ Añadir Extra», que la dejaría sin segmento y por
     * tanto invisible para el cliente.
     *
     * ── ⚠️ Por qué el ID y no un booleano ──────────────────────────────────
     * Un `esDuplicado` sí/no basta para pintar la marca y para desbloquear el borrado, y **se
     * queda corto en cuanto hay dos copias en el mismo servicio**: dice que las dos son copias y
     * no de cuál. Con el id, el reparto de un servicio es un conjunto identificable —el original y
     * sus copias— y eso se puede **sumar**: «los componentes que salieron de este vuelo tienen que
     * cubrir a los 40 del grupo» es una comprobación posible; con un booleano no lo es.
     *
     * Cuesta lo mismo —una columna anulable— y dice estrictamente más.
     *
     * ── ⚠️ Siempre apunta a la RAÍZ, nunca a otra copia ─────────────────────
     * Duplicar una copia apunta al original de ésta, no a ella. Si no, saldría una cadena y
     * agrupar exigiría recorrerla; con la raíz, «las copias de X» es una comparación directa y el
     * conjunto es plano pase lo que pase.
     *
     * ── ⚠️ Es un id suelto, no una relación ─────────────────────────────────
     * Mismo criterio que `componenteMaestroId`: una FK obligaría a decidir qué pasa al borrar el
     * original, y la respuesta correcta —que las copias sobrevivan huérfanas— es justo lo que una
     * FK no deja expresar sin `SET NULL`, que además borraría la marca y volvería la copia
     * imborrable. Aquí un id colgado significa «era copia de algo que ya no está», que es cierto.
     *
     * ── ⚠️ Y al clonar la COTIZACIÓN hay que reapuntarlo ────────────────────
     * `CotizacionCotservicio::duplicar()` crea componentes con ids nuevos. Sin remapear, las
     * copias del clon apuntarían a los componentes de la cotización **original**: un vínculo que
     * cruza cotizaciones y que no da ningún error — sólo agrupa mal, en silencio, para siempre.
     * Se remapea en el mismo sitio y por el mismo motivo que `cotsegmento`.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(name: 'duplicado_de', type: 'string', length: 36, nullable: true)]
    private ?string $duplicadoDe = null;

    /**
     * Las ubicaciones de un componente que **no tiene maestro**: Lima, Ica, Cusco.
     *
     * Uuids de `TravelLugar` en RFC 4122 minúsculas, el mismo formato que `componenteMaestroId`.
     * Es una columna JSON y no una relación a propósito: Operaciones no tiene ni una relación
     * Doctrine hacia el catálogo —borrar un lugar no debe arrastrar historial— y meter la
     * primera aquí abriría esa puerta por la puerta de atrás. Ver
     * {@see \App\Operacion\Filter\OperacionServicioLugarExtension}.
     *
     * ── UNA sola fuente de la verdad ─────────────────────────────────────────
     * Con maestro puesto, las ubicaciones son las SUYAS y se resuelven en vivo contra el
     * catálogo: re-etiquetar allí se refleja aquí sin tocar nada. Este campo es lo que hace un
     * componente manual, que no tiene a quién preguntarle.
     *
     * Los dos no conviven: {@see self::setComponenteMaestroId()} lo vacía al vincular un maestro
     * y {@see self::setLugaresManuales()} no deja escribirlo mientras haya uno. Da igual en qué
     * orden llegue el payload —los dos caminos acaban en el mismo sitio—, y por eso la regla
     * vive en la entidad y no en el formulario: un componente con maestro Y ubicaciones propias
     * es un componente que dice dos cosas distintas según quién lo lea.
     *
     * `operacion:item:read` está por lo mismo que `componenteMaestroId`: el cuadro de tráfico
     * pinta con esto las etiquetas de las filas manuales, que hasta ahora salían en blanco.
     *
     * @var list<string>
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'operacion:item:read'])]
    // Sin `options: ['default' => ...]`: MySQL 5.7 no admite DEFAULT en una columna JSON, y
    // ponerlo aquí hace que cada `diff` genere un ALTER que la base rechaza. El valor inicial
    // lo pone la propiedad.
    #[ORM\Column(name: 'lugares_manuales', type: 'json')]
    private array $lugaresManuales = [];

    /**
     * Cómo se llama esto **para nosotros y para el proveedor**.
     *
     * El componente sólo tenía `tituloSnapshot`, que es el título **público**; el nombre interno
     * salía siempre del maestro (`componenteMaestroId` → catálogo) y por eso «siempre existía».
     * Un componente manual no tiene maestro, así que sin este campo se quedaba sin nombre
     * interno y La Biblia lo rotulaba con el título del cliente — o con «Servicio sin nombre».
     *
     * Sin `#[AutoTranslate]` a propósito: esto no se le enseña a ningún pasajero, así que
     * traducirlo a siete idiomas es coste puro. Lo mismo que la nota al prestador.
     *
     * `operacion:item:read` porque en Operaciones **el componente es lo que identifica la fila**
     * —si es un ticket o un guiado— y el nombre de los de catálogo se resuelve en vivo contra el
     * maestro. Un manual no tiene maestro al que preguntarle: sin este campo se queda rotulado
     * con la tarifa, que es demasiado genérica («Adulto Extranjero») para saber qué se compró.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'operacion:item:read'])]
    #[ORM\Column(name: 'nombre_interno_snapshot', type: 'string', length: 255, nullable: true)]
    private ?string $nombreInternoSnapshot = null;

    /** @var list<array<string, mixed>> */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['detalle'], format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $detallesOperativos = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $tipo = null;

    /**
     * Este componente NO tiene hora propia: la UI no debe ofrecer selector de hora.
     *
     * Sale de `ComponenteTipoEnum::sinHorario()` —un ticket de horario variable o un alojamiento
     * no tienen hora; un tren o un traslado sí— y se congela por componente porque el tipo del
     * componente también está congelado.
     *
     * `operacion:item:read` para que el cuadro de tráfico consulte **el flag y no una copia de
     * la regla**: sin él, la Biblia ofrecía escribir una hora de recojo para un ingreso al
     * Koricancha, que es un dato que no significa nada y que alguien acaba mandándole al
     * proveedor.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $sinHorario = false;

    /**
     * La hora de este componente representa el horario global de toda la
     * excursión (servicio/itinerario), no la del segmento donde está anclado.
     * Propagado desde TravelSegmentoComponente::$horaServicioCompleto. La guía
     * del cliente lo muestra como horario de la experiencia completa en vez de
     * estirar el bloque del segmento al que pertenece.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $horaServicioCompleto = false;

    // ─────────────────────────────────────────────────────────────────────────
    // PRESTADOR — la empresa de este componente, en CAMPOS PLANOS
    //
    // `TravelOrganizacion` es la entidad maestra: el contacto-empresa, el otro lado de `Cliente`.
    // Aquí no se copia esa entidad, se guarda **el enlace y su nombre**, nada más.
    //
    // ── Todo se resuelve en vivo ─────────────────────────────────────────────
    // Título, url, imágenes, correo, teléfono y dirección salen del maestro cuando hacen
    // falta —al servir la propuesta y al mandar la orden—. Llegaron a estar copiados aquí
    // en nueve columnas y eso traía tres problemas a la vez:
    //
    //   · **filtrar era ambiguo**: la misma empresa estaba en trece columnas y, si alguien
    //     la renombraba en el catálogo, ninguna coincidía con las otras;
    //   · había que mantener sincronizados unos overrides que casi nadie usaba;
    //   · un correo de confirmación salía con el dato del día en que se cotizó.
    //
    // ── Degradación: qué pasa si borran la empresa ───────────────────────────
    // El soft-link no tiene integridad referencial a propósito. Si el maestro desaparece,
    // esto degrada solo a lo que queda escrito: **el uuid histórico y el nombre
    // histórico**, del prestador y de su servicio. Con eso la propuesta antigua sigue
    // contando quién prestó el servicio, que es lo único que hace falta de un proveedor
    // que ya no trabaja contigo.
    //
    // Por eso el nombre se guarda aunque se pueda resolver: no es una copia por si acaso,
    // es el último dato que sobrevive.
    //
    // ⚠️ Se acabó el prestador «a mano». Todos se dan de alta —el editor tiene el alta
    // inline— así que `prestadorMaestroId` siempre está lleno mientras la empresa exista.
    // Ver docs/Cotizaciones.md §6.c.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ¿Se nombra al prestador en ESTA propuesta?
     *
     * Se decide una vez y se guarda. Antes la respuesta se re-derivaba de `$modo` en cada
     * lectura, y eso hacía que reclasificar un componente cambiara la propuesta del cliente
     * en silencio. Arranca en `false`: el olvido caro es nombrar a quien no tocaba.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $prestadorVisible = false;

    /**
     * SOFT-LINK al catálogo maestro. Viaja a pax porque es lo que la vista del cliente
     * hidrata EN LOTE: se manda el id, no la ficha repetida en cada componente.
     */
    // ⚠️ **Sin `pax_cotizacion:read` a propósito.** Es un id opaco que el cliente no usa —`pax/`
    // no lo lee en ninguna parte— y exponerlo filtraba el prestador de componentes marcados como
    // ocultos: el normalizer que los tapa sólo decora el normalizer de JSON-LD, así que pidiendo
    // el mismo enlace público con `Accept: application/json` salían todos. No publicar el campo
    // cierra el agujero en cualquier formato, que es más barato que un segundo decorador.
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorMaestroId = null;

    /** Nombre comercial. Operativo: identifica al prestador en La Biblia. */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $prestadorNombreSnapshot = null;

    /**
     * El servicio concreto que presta (ej. el tipo de habitación).
     *
     * @see $prestadorServicioTituloSnapshot para su cara pública.
     */
    /** El servicio contratado (ej. el tipo de habitación). Enlace. */
    // ⚠️ **Sin `pax_cotizacion:read` a propósito.** Es un id opaco que el cliente no usa —`pax/`
    // no lo lee en ninguna parte— y exponerlo filtraba el prestador de componentes marcados como
    // ocultos: el normalizer que los tapa sólo decora el normalizer de JSON-LD, así que pidiendo
    // el mismo enlace público con `Accept: application/json` salían todos. No publicar el campo
    // cierra el agujero en cualquier formato, que es más barato que un segundo decorador.
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorServicioMaestroId = null;

    /** Nombre histórico del servicio: lo que sobrevive si lo borran del catálogo. */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $prestadorServicioNombreSnapshot = null;

    // ─────────────────────────────────────────────────────────────────────────
    // COMPRADOR — a quién se le encarga EJECUTAR la compra
    //
    // El tercer rol. El proveedor dice de quién es el precio; el prestador, quién presta
    // el servicio; el comprador, **a quién le mando el encargo**. Suele coincidir con el
    // proveedor —le compras directo— y por eso el campo se queda vacío casi siempre.
    //
    // El caso que lo justifica: le encargas a Futurismo que compre las entradas a San
    // Francisco o a Paracas, o que contrate el Hotel Estelar porque consigue mejor precio.
    // Ahí **prestador = Hotel Estelar** y **comprador = Futurismo**. Y la excursión del
    // propio Futurismo no lleva comprador, porque ésa se la compras tú directamente.
    //
    // ⚠️ **Siempre apunta a un `TravelOrganizacion`, nunca a una persona.** También los internos:
    // «Openperu tickets» es una parte de la empresa modelada como proveedor. Es
    // deliberado y simplifica todo — el chófer Gabriel presta servicio como empresa de
    // transportes, no como persona natural, así que modelarlo como `User` obligaría a
    // mantener dos catálogos para el mismo hecho y a preguntar «¿de qué clase es?» antes
    // de poder elegir.
    //
    // ⚠️ **No tiene cara pública, y no es un olvido.** A quién le encargaste la compra no
    // es asunto del cliente. Por eso ninguno de estos campos lleva `pax_cotizacion:read`
    // y no hay bandera de visibilidad: los otros dos roles la necesitan porque PUEDEN
    // mostrarse; éste no puede, así que una bandera sería una decisión que nadie debe
    // poder tomar.
    // ─────────────────────────────────────────────────────────────────────────

    /** SOFT-LINK al catálogo maestro (App\Travel\Entity\TravelOrganizacion). */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $compradorMaestroId = null;

    /** Nombre congelado. Es lo que lee quien despacha, el día que despacha. */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $compradorNombreSnapshot = null;

    public function __construct()
    {
        $this->initializeId();
        $this->cottarifas = new ArrayCollection();
        $this->grupos = new ArrayCollection();
    }

    /**
     * Clona el componente y clona profundamente sus tarifas.
     */
    public function duplicar(): self
    {
        $copia = clone $this;   // clone superficial por defecto (sin __clone)
        $copia->resetId();

        // ⚠️ **Un clon NO vuelve a traducirse.** Ver la explicación entera en
        // {@see \App\Cotizacion\Entity\Cotizacion::duplicar()}: el texto es idéntico al del
        // original, que ya está traducido, y `ejecutarTraduccion` es virtual — sólo apaga el
        // listener para ESTE guardado.
        $copia->setEjecutarTraduccion(false);


        // 🔥 **Colección PROPIA, con los mismos subgrupos dentro.**
        //
        // `clone` es superficial: sin esta línea, la copia y el original compartirían la misma
        // `PersistentCollection`. Añadirle un subgrupo a la copia se lo añadiría también al
        // original, y el `flush` guardaría las dos filas con lo mismo. No daría ningún error:
        // simplemente el reparto dejaría de repartir.
        //
        // ⚠️ Se copian los MIEMBROS, no se vacía: los subgrupos cuelgan del expediente, así que
        // una cotización clonada sigue acotando igual. Quien quiere una copia en blanco es el
        // editor al partir un servicio, y eso lo decide él —no aquí—, porque aquí el clon es de la
        // cotización entera y vaciarlo perdería el reparto al guardar una foto.
        $copia->grupos = new ArrayCollection($this->grupos->toArray());

        $copia->cottarifas = new ArrayCollection();
        foreach ($this->cottarifas as $tarifa) {
            $copiaTarifa = $tarifa->duplicar();
            $copiaTarifa->setCotcomponente($copia);
            $copia->cottarifas->add($copiaTarifa);
        }

        return $copia;
    }

    #[ORM\PrePersist]
    public function normalizarHorarioAlCrear(): void
    {
        if ($this->sinHorario) {
            $this->fechaHoraInicio = $this->fechaHoraInicio?->setTime(0, 0, 0);
            $this->fechaHoraFin = $this->fechaHoraFin?->setTime(0, 0, 0);
        }
    }

    /**
     * ⚠️ **`pax_cotizacion:read` hace falta y no estaba.** La guía del huésped une cada línea de
     * inclusión con su componente vivo para pintar la tarjeta del hotel, y el puente es este id
     * (`proveedorPorComponente` en `PaxCotizacionGuiaView`). Sin él, el mapa se construía entero
     * bajo la clave `undefined` y la búsqueda no acertaba nunca: **la tarjeta del prestador no se
     * ha mostrado jamás**, por mucho que el backend la inyectara correctamente.
     *
     * No fallaba nada — ni un error, ni un hueco raro: simplemente no salía el bloque, que es
     * indistinguible de «este componente no tiene proveedor que enseñar».
     *
     * `CotizacionSegmento` y `CotizacionCotservicio` sí lo declaraban; éste era el que faltaba.
     * Y no expone nada nuevo: el `@id` de JSON-LD ya lleva el mismo uuid.
     */
    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'pax_cotizacion:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['cotizacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    // --- MÉTODOS SOBRESCRITOS PARA EXPONER EL FLAG A API PLATFORM ---
    #[Groups(['cotizacion:write', 'cotizacion:read'])]
    public function getSobreescribirTraduccion(): bool
    {
        return $this->sobreescribirTraduccion;
    }

    #[Groups(['cotizacion:write'])]
    public function setSobreescribirTraduccion(bool $sobreescribirTraduccion): self
    {
        $this->sobreescribirTraduccion = $sobreescribirTraduccion;
        return $this;
    }

    /**
     * Obtiene el servicio de cotización padre.
     *
     * @return CotizacionCotservicio|null
     */
    public function getCotservicio(): ?CotizacionCotservicio { return $this->cotservicio; }

    /**
     * Establece el servicio de cotización padre.
     *
     * @param CotizacionCotservicio|null $cotservicio
     * @return self
     */
    public function setCotservicio(?CotizacionCotservicio $cotservicio): self { $this->cotservicio = $cotservicio; return $this; }

    /**
     * Obtiene el segmento de la cotización vinculado.
     *
     * @return CotizacionSegmento|null
     */
    public function getCotsegmento(): ?CotizacionSegmento { return $this->cotsegmento; }

    /**
     * Establece el segmento de la cotización vinculado.
     *
     * @param CotizacionSegmento|null $cotsegmento
     * @return self
     */
    public function setCotsegmento(?CotizacionSegmento $cotsegmento): self { $this->cotsegmento = $cotsegmento; return $this; }

    /**
     * Obtiene el snapshot del nombre del componente.
     *
     * @return array
     *
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getTituloSnapshot(): array { return $this->tituloSnapshot; }

    /**
     * Establece el snapshot del nombre del componente.
     *
     * @param array $tituloSnapshot
     * @return self
     *
     * @param list<array{language?: string, content?: string|null}> $tituloSnapshot
     */
    public function setTituloSnapshot(array $tituloSnapshot): self { $this->tituloSnapshot = $tituloSnapshot; return $this; }

    public function getDuplicadoDe(): ?string { return $this->duplicadoDe; }

    public function setDuplicadoDe(?string $duplicadoDe): self { $this->duplicadoDe = $duplicadoDe; return $this; }

    /** Azúcar para lo que se pregunta más: pintar la marca y desbloquear el borrado. */
    public function esCopia(): bool { return $this->duplicadoDe !== null; }

    /**
     * @return Collection<int, CotizacionFileGrupo>
     */
    public function getGrupos(): Collection { return $this->grupos; }

    public function addGrupo(CotizacionFileGrupo $grupo): self
    {
        if (!$this->grupos->contains($grupo)) {
            $this->grupos->add($grupo);
        }

        return $this;
    }

    public function removeGrupo(CotizacionFileGrupo $grupo): self
    {
        $this->grupos->removeElement($grupo);

        return $this;
    }

    /** ¿Aplica a todo el mundo? Es el caso normal, y por eso se pregunta así. */
    public function esParaTodos(): bool { return $this->grupos->isEmpty(); }

    /**
     * Obtiene la cantidad de componentes instanciados.
     *
     * ⚠️ En un grupo partido, las cantidades de los componentes de un servicio **NO suman el total
     * del grupo**, y es correcto: 22 en el vuelo nacional y 18 en el internacional. Ver
     * {@see self::$grupo}.
     */
    public function getCantidad(): int { return $this->cantidad; }

    /**
     * Establece la cantidad de componentes instanciados.
     *
     * @param int $cantidad
     * @return self
     */
    public function setCantidad(int $cantidad): self { $this->cantidad = $cantidad; return $this; }

    /**
     * Obtiene el estado del componente.
     *
     * @return ComponenteEstadoEnum
     */

    /**
     * ¿Este componente sigue vivo en el viaje, o es historia?
     *
     * Aquí no se borra nada: un servicio que el cliente canceló o que se sustituyó por otro
     * **conserva su fila** —y sus fechas, y su prestador— para que se pueda reconstruir lo que
     * pasó. La consecuencia es que cualquier cosa que recorra los componentes tiene que
     * preguntarse esto, o se lleva por delante los muertos junto con los vivos.
     *
     * ⚠️ **`no_incluido` SÍ está vivo**, y es la distinción que se puede perder al filtrar de
     * memoria. Es lo que el pasajero paga por su cuenta —el hotel que reservó él—: no se le compra
     * a nadie, pero existe, y el transportista necesita ese hotel para recogerlo. Confundirlo con
     * «muerto» deja al conductor sin dirección. Ver {@see \App\Operacion\Entity\OperacionServicio::isSoloReferencia()}.
     *
     * Los mismos dos casos que usa `OperacionServicio::esComprable()`, y a propósito: son la
     * definición de «ya no se opera» de este dominio, y tenerla escrita dos veces con criterios
     * distintos es cómo se acaba comprando lo cancelado o recogiendo en el hotel viejo.
     */
    public function estaVivo(): bool
    {
        return $this->estado !== ComponenteEstadoEnum::CANCELADO
            && $this->modo !== ComponenteModoEnum::REEMPLAZADO;
    }

    public function getEstado(): ComponenteEstadoEnum { return $this->estado; }

    /**
     * Establece el estado del componente.
     *
     * @param ComponenteEstadoEnum $estado
     * @return self
     */
    public function setEstado(ComponenteEstadoEnum $estado): self { $this->estado = $estado; return $this; }

    /**
     * Obtiene la modalidad del componente en la cotización.
     *
     * @return ComponenteModoEnum
     */
    public function getModo(): ComponenteModoEnum { return $this->modo; }

    /**
     * Establece la modalidad del componente en la cotización.
     *
     * @param ComponenteModoEnum $modo
     * @return self
     */
    public function setModo(ComponenteModoEnum $modo): self { $this->modo = $modo; return $this; }

    /**
     * Obtiene la fecha y hora de inicio de la operativa.
     *
     * @return DateTimeImmutable|null
     */
    public function getFechaHoraInicio(): ?DateTimeImmutable { return $this->fechaHoraInicio; }

    /**
     * Establece la fecha y hora de inicio de la operativa.
     *
     * @param DateTimeImmutable|null $fechaHoraInicio
     * @return self
     */
    public function setFechaHoraInicio(?DateTimeImmutable $fechaHoraInicio): self { $this->fechaHoraInicio = $fechaHoraInicio; return $this; }

    /**
     * Obtiene la fecha y hora de fin de la operativa.
     *
     * @return DateTimeImmutable|null
     */
    public function getFechaHoraFin(): ?DateTimeImmutable { return $this->fechaHoraFin; }

    /**
     * Establece la fecha y hora de fin de la operativa.
     *
     * @param DateTimeImmutable|null $fechaHoraFin
     * @return self
     */
    public function setFechaHoraFin(?DateTimeImmutable $fechaHoraFin): self { $this->fechaHoraFin = $fechaHoraFin; return $this; }

    /**
     * Obtiene los items guardados en el snapshot.
     *
     * @return array
     *
     * @return list<array<string, mixed>>
     */
    public function getSnapshotItems(): array { return $this->snapshotItems; }

    /**
     * Establece los items guardados en el snapshot.
     *
     * @param array $snapshotItems
     * @return self
     *
     * @param list<array<string, mixed>> $snapshotItems
     */
    public function setSnapshotItems(array $snapshotItems): self { $this->snapshotItems = $snapshotItems; return $this; }

    /**
     * Obtiene las tarifas vinculadas al componente.
     *
     * @return Collection
     *
     * @return Collection<int, CotizacionCottarifa>
     */
    public function getCottarifas(): Collection { return $this->cottarifas; }

    /**
     * Añade una tarifa a la colección de tarifas del componente.
     *
     * @param CotizacionCottarifa $cottarifa
     * @return self
     */
    public function addCottarifa(CotizacionCottarifa $cottarifa): self
    {
        if (!$this->cottarifas->contains($cottarifa)) {
            $this->cottarifas->add($cottarifa);
            $cottarifa->setCotcomponente($this);
        }
        return $this;
    }

    /**
     * Remueve una tarifa de la colección de tarifas del componente.
     *
     * @param CotizacionCottarifa $cottarifa
     * @return self
     */
    public function removeCottarifa(CotizacionCottarifa $cottarifa): self
    {
        if ($this->cottarifas->removeElement($cottarifa)) {
            if ($cottarifa->getCotcomponente() === $this) { $cottarifa->setCotcomponente(null); }
        }
        return $this;
    }

    /**
     * Los detalles del componente, siempre en la forma con audiencias.
     *
     * Normaliza al leer, así que una fila que todavía tenga el `tipo` viejo sale ya convertida
     * y el editor no necesita entender las dos formas.
     *
     * @return list<array<string, mixed>>
     */
    public function getDetallesOperativos(): array
    {
        return array_map(self::normalizarBloque(...), $this->detallesOperativos);
    }

    /**
     * @param list<array<string, mixed>> $detallesOperativos
     *
     * @throws \InvalidArgumentException si un bloque no marca ninguna audiencia válida
     */
    public function setDetallesOperativos(array $detallesOperativos): self
    {
        $this->detallesOperativos = array_map(self::normalizarBloque(...), $detallesOperativos);

        return $this;
    }

    /**
     * Deja un bloque en la forma con audiencias, venga como venga.
     *
     * ⚠️ Tolera el `tipo` viejo **a propósito**: entre que se despliega el código y corre el
     * comando de conversión hay filas con la forma antigua, y durante ese rato el editor tiene
     * que poder leerlas y guardarlas sin reventar.
     *
     * ⚠️ Y `operativa` se convierte en `interno`, **nunca en `prestador`**. Es el único error de
     * esta conversión que no se puede deshacer: mandarle a un proveedor externo un texto que
     * nadie decidió enseñarle ya no se recoge. Que falte una marca se ve y se pone; que sobre,
     * se ve cuando el proveedor ya lo leyó.
     *
     * @param array<string, mixed> $bloque
     *
     * @return array<string, mixed>
     */
    private static function normalizarBloque(array $bloque): array
    {
        $audiencias = $bloque['audiencias'] ?? null;

        if ($audiencias === null && isset($bloque['tipo'])) {
            $audiencias = $bloque['tipo'] === AudienciaDetalleEnum::CLIENTE->value
                ? [AudienciaDetalleEnum::CLIENTE->value]
                : [AudienciaDetalleEnum::INTERNO->value];
        }

        if (!is_array($audiencias) || $audiencias === []) {
            throw new \InvalidArgumentException('Un detalle sin audiencia no lo lee nadie: marca al menos una.');
        }

        $marcadas = [];
        foreach ($audiencias as $audiencia) {
            if (!is_string($audiencia) || AudienciaDetalleEnum::tryFrom($audiencia) === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Audiencia de detalle inválida: «%s».',
                    is_string($audiencia) ? $audiencia : get_debug_type($audiencia),
                ));
            }
            $marcadas[$audiencia] = true;
        }

        unset($bloque['tipo']);

        // En el orden del enum, no en el que llegaron: así dos guardados iguales dan el mismo
        // JSON y un diff sólo sale cuando de verdad cambió algo.
        $bloque['audiencias'] = array_values(array_filter(
            AudienciaDetalleEnum::valores(),
            static fn (string $valor): bool => isset($marcadas[$valor]),
        ));

        return $bloque;
    }

    /**
     * Los bloques que ve una audiencia.
     *
     * @return list<array<string, mixed>>
     */
    public function detallesPara(AudienciaDetalleEnum $audiencia): array
    {
        return array_values(array_filter(
            $this->getDetallesOperativos(),
            static fn (array $bloque): bool => in_array($audiencia->value, $bloque['audiencias'], true),
        ));
    }

    /**
     * Superficie segura para el cliente final.
     *
     * Se mantiene el nombre viejo porque lo consumen la cotización y la app del pasajero; lo que
     * cambia por dentro es que filtra por audiencia en vez de por tipo.
     *
     * @return list<array<string, mixed>>
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    public function getDetallesParaCliente(): array
    {
        return $this->detallesPara(AudienciaDetalleEnum::CLIENTE);
    }

    /**
     * Los textos de una audiencia en un idioma, listos para pegar en un documento.
     *
     * Operación y prestador leen **siempre español**, y el bloque se traduce igualmente a los
     * siete idiomas: enseñarle a `AutoTranslationService` a saltarse entradas es tocar el
     * servicio que traduce todo el sistema, y sale más caro que unas traducciones de más.
     *
     * Si falta el idioma pedido cae al primero que haya — un texto en otro idioma sigue siendo
     * más útil que un hueco en una orden de servicio.
     *
     * @return list<string>
     */
    public function textosPara(AudienciaDetalleEnum $audiencia, string $idioma = 'es'): array
    {
        $textos = [];

        foreach ($this->detallesPara($audiencia) as $bloque) {
            $traducciones = is_array($bloque['detalle'] ?? null) ? $bloque['detalle'] : [];
            $elegido = null;

            foreach ($traducciones as $traduccion) {
                if (!is_array($traduccion) || !is_string($traduccion['content'] ?? null)) {
                    continue;
                }
                $elegido ??= $traduccion['content'];
                if (($traduccion['language'] ?? null) === $idioma) {
                    $elegido = $traduccion['content'];
                    break;
                }
            }

            $elegido = trim((string) $elegido);
            if ($elegido !== '') {
                $textos[] = $elegido;
            }
        }

        return $textos;
    }

    /**
     * Obtiene el ID del componente maestro si lo hubiera.
     *
     * @return string|null
     */
    public function getComponenteMaestroId(): ?string { return $this->componenteMaestroId; }

    /**
     * Establece el ID del componente maestro.
     *
     * ⚠️ Vincular un maestro **borra las ubicaciones propias** ({@see self::$lugaresManuales}):
     * a partir de aquí las pone el catálogo, y guardar las dos dejaría al componente diciendo
     * una cosa en el cuadro de tráfico y otra en el filtro. Desvincular no las devuelve —no
     * eran suyas—, y por eso el editor las vuelve a pedir a mano.
     */
    public function setComponenteMaestroId(?string $componenteMaestroId): self
    {
        $this->componenteMaestroId = $componenteMaestroId;

        if ($componenteMaestroId !== null) {
            $this->lugaresManuales = [];
        }

        return $this;
    }

    public function getTipo(): ?string { return $this->tipo; }
    public function setTipo(?string $tipo): self { $this->tipo = $tipo; return $this; }

    /**
     * Dónde va este componente dentro de su jornada al CONTAR el viaje.
     *
     * ⚠️ **La regla vive aquí y no en el front**, y ése es todo el motivo de que exista este
     * getter. Las vistas son dos aplicaciones Vue distintas —`util/` y `pax/`— que no comparten
     * código: escribir el orden allí sería escribirlo dos veces, y dos copias de una regla
     * terminan discrepando el día que alguien toca una. Aquí se decide el número; el front sólo
     * ordena por él, que no es una regla, es un `sort`.
     *
     * Un tipo desconocido o vacío cae en 30 —el cuerpo del día— y no al final: lo que no sabemos
     * qué es no debería ganarle el sitio a la cama, que sí sabemos que cierra.
     *
     * El detalle de por qué el orden cronológico no sirve está en `ComponenteTipoEnum`.
     */
    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'pax_cotizacion:read'])]
    public function getOrdenNarrativo(): int
    {
        return (ComponenteTipoEnum::tryFrom((string) $this->tipo)?->ordenNarrativo()) ?? 30;
    }

    public function isSinHorario(): bool { return $this->sinHorario; }
    public function setSinHorario(bool $sinHorario): self { $this->sinHorario = $sinHorario; return $this; }

    public function isHoraServicioCompleto(): bool { return $this->horaServicioCompleto; }
    public function setHoraServicioCompleto(bool $horaServicioCompleto): self { $this->horaServicioCompleto = $horaServicioCompleto; return $this; }

    // ─────────────────────────────────────────────────────────────────────────
    // PRESTADOR
    // ─────────────────────────────────────────────────────────────────────────

    /** ¿Se nombra al prestador en esta propuesta? Valor guardado, no regla viva. */
    public function isPrestadorVisible(): bool { return $this->prestadorVisible; }
    public function setPrestadorVisible(bool $v): self { $this->prestadorVisible = $v; return $this; }

    public function getPrestadorMaestroId(): ?string { return $this->prestadorMaestroId; }
    public function setPrestadorMaestroId(?string $v): self { $this->prestadorMaestroId = $v; return $this; }

    public function getPrestadorNombreSnapshot(): ?string { return $this->prestadorNombreSnapshot; }
    public function setPrestadorNombreSnapshot(?string $v): self { $this->prestadorNombreSnapshot = $v; return $this; }














    public function getPrestadorServicioMaestroId(): ?string { return $this->prestadorServicioMaestroId; }
    public function setPrestadorServicioMaestroId(?string $v): self { $this->prestadorServicioMaestroId = $v; return $this; }

    public function getPrestadorServicioNombreSnapshot(): ?string { return $this->prestadorServicioNombreSnapshot; }
    public function setPrestadorServicioNombreSnapshot(?string $v): self { $this->prestadorServicioNombreSnapshot = $v; return $this; }

    /** ¿Este componente tiene prestador, del catálogo o escrito a mano? */
    public function tienePrestador(): bool
    {
        return $this->prestadorMaestroId !== null
            || trim($this->prestadorNombreSnapshot ?? '') !== '';
    }

    public function getCompradorMaestroId(): ?string { return $this->compradorMaestroId; }
    public function setCompradorMaestroId(?string $v): self { $this->compradorMaestroId = $v; return $this; }

    public function getCompradorNombreSnapshot(): ?string { return $this->compradorNombreSnapshot; }
    public function setCompradorNombreSnapshot(?string $v): self { $this->compradorNombreSnapshot = $v; return $this; }

    /** ¿Este componente encarga la compra a alguien distinto del prestador? */
    public function tieneCompradorPropio(): bool
    {
        return $this->compradorMaestroId !== null
            || trim($this->compradorNombreSnapshot ?? '') !== '';
    }

    /**
     * Resuelve A QUIÉN se le encarga la compra.
     *
     * Cascada corta y deliberada: `componente → proveedor`. Si nadie encargó la compra, se
     * le pide a quien vende, que es el caso normal — por eso el campo puede quedarse vacío
     * en casi todos los componentes y las cotizaciones anteriores se comportan igual que
     * antes de que existiera.
     *
     * No hereda del día como el prestador: encargar una compra es una decisión por ítem
     * —una entrada la saca una persona y un tren lo compra otra—, así que un default por
     * día invitaría a arrastrar el encargo equivocado sin que se note.
     *
     * ⚠️ Espejo en TypeScript: `resolverComprador()` en
     * `util/src/stores/cotizacion/cotizacionEditorStore.ts`.
     */
    public function resolverComprador(): ?CompradorResuelto
    {
        if ($this->tieneCompradorPropio()) {
            return new CompradorResuelto(
                origen: 'componente',
                maestroId: $this->compradorMaestroId,
                nombre: $this->compradorNombreSnapshot,
            );
        }

        // Sin encargo explícito se le pide al propio prestador: es el caso normal, y es lo
        // que hace que la Orden de Servicio salga bien sin llenar nada.
        if ($this->tienePrestador()) {
            return new CompradorResuelto(
                origen: 'prestador',
                maestroId: $this->prestadorMaestroId,
                nombre: $this->prestadorNombreSnapshot,
            );
        }

        return null;
    }

    /**
     * El prestador tal y como está guardado: enlace + nombres históricos.
     *
     * No resuelve nada contra el catálogo — una entidad no consulta la base, y hacerlo por
     * su cuenta sería una consulta por componente. Quien necesite el dato vivo pasa por
     * `PrestadorVivoResolver`, que trae los maestros en lote.
     *
     * Si el maestro ya no existe, esto **es** el prestador: el uuid y el nombre que
     * quedaron escritos. Con eso una propuesta antigua sigue contando quién prestó el
     * servicio, que es lo único que hace falta de un proveedor que ya no trabaja contigo.
     *
     * @return array{maestroId: string|null, nombre: string|null,
     *               servicioMaestroId: string|null, servicioNombre: string|null}|null
     */
    public function resolverPrestador(): ?array
    {
        if (!$this->tienePrestador()) {
            return null;
        }

        return [
            'maestroId' => $this->prestadorMaestroId,
            'nombre' => $this->prestadorNombreSnapshot,
            'servicioMaestroId' => $this->prestadorServicioMaestroId,
            'servicioNombre' => $this->prestadorServicioNombreSnapshot,
        ];
    }

    public function isEsManual(): bool { return $this->esManual; }
    public function setEsManual(bool $v): self { $this->esManual = $v; return $this; }

    public function getNombreInternoSnapshot(): ?string { return $this->nombreInternoSnapshot; }

    public function setNombreInternoSnapshot(?string $v): self
    {
        $v = $v !== null ? trim($v) : null;
        $this->nombreInternoSnapshot = ($v === '' ? null : $v);

        return $this;
    }

    /** @return list<string> */
    public function getLugaresManuales(): array { return $this->lugaresManuales; }

    /**
     * Escribe las ubicaciones propias de un componente SIN maestro.
     *
     * Con maestro puesto se ignora en silencio en lugar de reventar: quien manda el payload no
     * está haciendo nada malo —el editor limpia el campo al vincular—, y un 400 aquí rompería
     * un guardado entero por un dato que sobra. El resultado es el mismo se escriba en el orden
     * que se escriba, que es justo lo que se busca.
     *
     * Normaliza a RFC 4122 en minúsculas y quita duplicados: es el formato con el que compara
     * el filtro del cuadro de tráfico, y un uuid en mayúsculas no casaría con nada — en
     * silencio, que es la peor forma de no casar.
     *
     * @param list<string>|array<int|string, mixed> $lugares
     */
    public function setLugaresManuales(array $lugares): self
    {
        if ($this->componenteMaestroId !== null) {
            $this->lugaresManuales = [];

            return $this;
        }

        $limpios = [];

        foreach ($lugares as $lugar) {
            if (!is_string($lugar) || !Uuid::isValid($lugar)) {
                continue;
            }

            $limpios[] = strtolower($lugar);
        }

        $this->lugaresManuales = array_values(array_unique($limpios));

        return $this;
    }
}
