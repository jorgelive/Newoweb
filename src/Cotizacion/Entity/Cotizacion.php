<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use App\Attribute\AutoTranslate;
use App\Cotizacion\ApiPlatform\Dto\InformeCoherencia;
use App\Cotizacion\ApiPlatform\State\CloneCotizacionProcessor;
use App\Cotizacion\ApiPlatform\State\RevisarCoherenciaProcessor;
use App\Cotizacion\ApiPlatform\State\AbrirOperativaProcessor;
use App\Cotizacion\ApiPlatform\State\GuardarHistoricoProcessor;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Operacion\ApiPlatform\Dto\AplicarPlanInput;
use App\Operacion\ApiPlatform\Dto\PlanReconciliacion;
use App\Operacion\ApiPlatform\Dto\ResultadoAplicacion;
use App\Operacion\ApiPlatform\State\AplicarPlanOperacionProcessor;
use App\Operacion\ApiPlatform\State\PlanificarOperacionProcessor;
use App\Security\Roles;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    shortName: 'Cotizacion',
    operations: [
        new GetCollection(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        ),
        new Get(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        ),
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear cotizaciones.'
        ),
        new Post(
            uriTemplate: '/client/cotizacion/{id}/clonar',
            normalizationContext: ['groups' => ['file:item:read']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para clonar cotizaciones.',
            read: true,
            deserialize: false,
            validate: false,
            processor: CloneCotizacionProcessor::class
        ),
        // Clonar hacia ATRÁS: la copia es el pasado y ésta sigue viva con sus órdenes.
        // El porqué de que sean dos acciones distintas está en GuardarHistoricoProcessor.
        new Post(
            uriTemplate: '/client/cotizacion/{id}/historico',
            normalizationContext: ['groups' => ['file:item:read']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para guardar históricos.',
            read: true,
            deserialize: false,
            validate: false,
            processor: GuardarHistoricoProcessor::class
        ),
        // Clonar hacia ADELANTE, después de vender: la operativa es lo que de verdad se va a
        // operar. Misma propuesta, otro estado, y el traspaso de las filas de operación en una
        // sola transacción. Ver AbrirOperativaProcessor.
        new Post(
            uriTemplate: '/client/cotizacion/{id}/operativa',
            normalizationContext: ['groups' => ['file:item:read']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para abrir la operativa.',
            read: true,
            deserialize: false,
            validate: false,
            processor: AbrirOperativaProcessor::class
        ),
        // Reconciliación en dos pasos: plan → revisión humana → aplicar sólo lo aprobado.
        //
        // Aquí hubo un `/resincronizar-operacion` que borraba y regeneraba de un golpe.
        // Se retiró a propósito: en cuanto las filas de La Biblia pasaron a guardar cosas
        // que no están en la cotización —hora pactada por teléfono, prestador, teléfono
        // del recojo, costo real— un botón que las borra sin enseñar qué se pierde deja de
        // ser una comodidad. Dejarlo al lado del panel de revisión sólo invitaría a usarlo.
        // El primer volcado también pasa por aquí: sale como N cambios de tipo `crear`,
        // todos aprobados por defecto. Ver docs/Operacion.md §3.5.
        new Post(
            uriTemplate: '/cotizacions/{id}/operacion/plan',
            normalizationContext: ['groups' => ['operacion:plan:read']],
            output: PlanReconciliacion::class,
            security: "is_granted('" . Roles::RESERVAS_WRITE . "') or is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para revisar la operación de una cotización.',
            read: true,
            deserialize: false,
            validate: false,
            processor: PlanificarOperacionProcessor::class
        ),
        new Post(
            uriTemplate: '/cotizacions/{id}/operacion/aplicar',
            normalizationContext: ['groups' => ['operacion:plan:read']],
            denormalizationContext: ['groups' => ['operacion:plan:write']],
            input: AplicarPlanInput::class,
            output: ResultadoAplicacion::class,
            security: "is_granted('" . Roles::RESERVAS_WRITE . "') or is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para aplicar cambios a la operación.',
            read: false,
            processor: AplicarPlanOperacionProcessor::class
        ),
        // Coherencia: encuentra ids sin su nombre y demás configuraciones a medias de ESTA
        // cotización. Mirar y reparar son dos operaciones porque merecen permisos distintos:
        // la primera la puede lanzar quien sólo consulta.
        new Post(
            uriTemplate: '/cotizacions/{id}/coherencia',
            normalizationContext: ['groups' => ['coherencia:read']],
            output: InformeCoherencia::class,
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
            securityMessage: 'No tienes permiso para revisar esta cotización.',
            read: true,
            deserialize: false,
            validate: false,
            processor: RevisarCoherenciaProcessor::class
        ),
        new Post(
            uriTemplate: '/cotizacions/{id}/coherencia/reparar',
            normalizationContext: ['groups' => ['coherencia:read']],
            output: InformeCoherencia::class,
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para reparar datos de cotizaciones.',
            read: true,
            deserialize: false,
            validate: false,
            processor: 'coherencia.processor.reparar'
        ),
        new Put(
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar cotizaciones.'
        ),
        new Patch(
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para actualizar parcialmente cotizaciones.'
        ),
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar cotizaciones.'
        )
    ],
    routePrefix: '/sales',
    normalizationContext: ['groups' => ['cotizacion:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['cotizacion:write']]
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_cotizacion')]
#[ORM\Index(columns: ['file_id', 'propuesta'], name: 'idx_cotizacion_file_propuesta')]
#[ORM\Index(columns: ['derivada_de_id'], name: 'idx_cotizacion_derivada_de')]
#[ORM\Index(columns: ['catalogo_id', 'propuesta'], name: 'idx_cotizacion_catalogo_propuesta')]
#[ORM\HasLifecycleCallbacks]
class Cotizacion
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    /** Padre expediente. Excluyente con $catalogo: una cotización cuelga de uno u otro. */
    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'cotizaciones')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    /** Padre catálogo de tours. Excluyente con $file. */
    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionCatalogo::class, inversedBy: 'cotizaciones')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CotizacionCatalogo $catalogo = null;

    /**
     * ¿Puede verla el cliente ahora mismo?
     *
     * ⚠️ **Eje propio, INDEPENDIENTE del estado.** Hasta el 02/09/2026 la visibilidad la decidía
     * `estado->esPublico()`, y eso obligaba a mentir sobre un acto comercial para conseguir una
     * visibilidad: *«para ver la cotización antes de mandarla tengo que ponerle enviada»*. Son dos
     * preguntas distintas —dónde está comercialmente y si el cliente puede verla— y mezclarlas
     * hacía imposible previsualizar, y también reorganizar sin que el cliente lo viera a medias.
     *
     * ⚠️ **Como máximo una publicada por propuesta.** Esa invariante es la que hace que no haya
     * que «desempatar» qué fila sirve el provider cuando conviven la aprobada y la operativa: cada
     * eje decide lo suyo y la pregunta no existe. Ver `docs/PlanPropuestaOperativa.md` §2.
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $publicado = false;

    /**
     * Cuál de las propuestas del expediente es ésta.
     *
     * ⚠️ **No es una versión**: las propuestas NO se sustituyen entre sí. Un expediente puede
     * tener varias vivas a la vez y el cliente puede aprobar más de una, porque a veces son
     * complementarias —una la parte de Lima del itinerario y otra la de Bolivia—. Se llamó
     * `version` hasta el 02/09/2026 y ese nombre hacía razonar mal a todo el que lo leía.
     *
     * Dentro de una misma propuesta conviven sus históricos, la aprobada y —si hace falta— la
     * operativa: se distinguen por `estado`, no por este número. Ver `docs/Cotizaciones.md` §6.j.0.
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer')]
    private int $propuesta = 1;

    /**
     * De qué cotización VIVA salió este histórico.
     *
     * Sólo lo llevan los `HISTORICO`, y apunta siempre a la que sigue trabajando — la que
     * conserva sus componentes, sus filas de La Biblia y sus órdenes. Es lo único que relaciona
     * dos filas del mismo expediente: hasta ahora v1 y v2 sólo compartían `file_id`, así que no
     * había forma de saber que una salió de la otra.
     *
     * ⚠️ `SET NULL` y no `CASCADE`: si alguien borra la cotización viva, **el histórico
     * sobrevive**. Es el rastro de lo que ya se le vendió a alguien, y borrarlo deja el
     * expediente contando una versión incompleta de lo que pasó.
     */
    // ⚠️ Sin `#[Groups]`: serializar la relación entera anida la cotización padre completa —con
    // sus servicios, componentes y tarifas— y encima es recursiva. Lo que el front necesita es el
    // id, y para eso está `getDerivadaDeId()`.
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'historicos')]
    #[ORM\JoinColumn(name: 'derivada_de_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $derivadaDe = null;

    /**
     * Las fotos congeladas de esta cotización, de la más reciente a la más antigua.
     *
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(mappedBy: 'derivadaDe', targetEntity: self::class, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $historicos;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    // El default de la columna estaba en 'Pendiente' con mayúscula, que CotizacionEstadoEnum
    // no acepta: una fila insertada sin este campo no se puede hidratar. Mismo caso que en
    // CotizacionCotcomponente::$estado.
    #[ORM\Column(type: 'string', length: 30, enumType: CotizacionEstadoEnum::class, options: ['default' => 'pendiente'])]
    private CotizacionEstadoEnum $estado = CotizacionEstadoEnum::PENDIENTE;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $numPax = 1;

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, options: ['default' => '20.00'])]
    private string $comision = '20.00';

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $adelanto = '0.00';

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $precioOculto = false;

    // 🔥 NUEVO FLAG DE PROVEEDOR OCULTO A NIVEL GLOBAL

    /**
     * Modo "catálogo unitario": cuando está activo, la guía del cliente deja de
     * mostrar cualquier referencia a cantidad de pasajeros o suma como grupo
     * (el "2X" del perfil, el "× N pax · total" y el "Precio total del viaje").
     * El catálogo funciona como un menú de precios por unidad — "peruanos tal
     * precio, extranjeros tal precio" — no como cotización de un grupo concreto.
     * Sólo tiene sentido en modo catálogo; el precio unitario sí se sigue viendo.
     *
     * El default false es el de un expediente de grupo. En catálogo el editor lo crea
     * ya activo (cotizacionEditorStore::crearCotizacionVacia) y sólo se apaga a mano
     * cuando el tour se vende como salida de grupo fijo.
     *
     * ⚠️ **También sirve en un EXPEDIENTE, y el interruptor estuvo escondido tras el modo
     * catálogo por descuido.** Cuando dentro de un grupo no todos llevan los mismos servicios, el
     * «precio total del viaje» no describe a nadie: en el padrón de Punta Cana 2026 hay **13
     * combinaciones de servicios entre 133 personas** y sólo 105 llevan el paquete completo. Ahí
     * lo que se le enseña a cada familia es su precio por persona, que es exactamente lo que este
     * flag deja ver. Ver `docs/Cotizaciones.md` §6.o.
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $totalesOcultos = false;


    /**
     * Título comercial opcional de la propuesta/tour (i18n), ej. "Cusco:
     * Experiencia Mística". Diferencia paquetes tanto en el expediente del
     * cliente como en el escaparate del catálogo.
     *
     * @var list<array{language?: string, content?: string|null}>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $resumen = [];

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fechaExpiracion = null;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'USD'])]
    private string $monedaGlobal = 'USD';

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 5, options: ['default' => 'es'])]
    private string $idiomaCliente = 'es';

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $totalCosto = '0.00';

    // ⚠️ SIN `pax_cotizacion:read`: al cliente se lo sirve getTotalVentaParaCliente(), que en una
    // OPERATIVA lo toma de la confirmada. Ver origenFinancieroParaCliente().
    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $totalVenta = '0.00';

    /**
     * Rangos de precio de exhibición "Desde X" para tours de catálogo.
     * Valores comerciales arbitrarios por perfil de cliente; el cálculo
     * financiero real (totalVenta/totalCosto) se conserva para producto.
     *
     * Estructura: [{ titulo: I18n[], moneda: 'PEN'|'USD', valor: '99.00' }, ...]
     * El título es traducible (AutoTranslate busca la clave anidada).
     *
     * @var list<array{valor?: string, moneda?: string, titulo?: list<array{language?: string, content?: string|null}>}>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['titulo'])]
    #[ORM\Column(type: 'json')]
    private array $preciosDesde = [];

    /** Orden de exhibición del tour dentro del catálogo. */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orden = 0;

    /**
     * Override editorial de la imagen de portada del tour (snapshot de la
     * imagen elegida). Null = se deriva automáticamente: primera imagen con
     * isPortada recorriendo el itinerario, o la primera disponible.
     */
    /** @var array<string, mixed>|null El override editorial de la portada. */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $imagenPortada = null;

    /**
     * Portada efectiva del tour para pintar su tarjeta: el override editorial
     * si existe, y si no la derivada del itinerario. NO se persiste — la llena
     * CotizacionCatalogoAdminProvider en el Get del catálogo, que es el único
     * contexto donde se pintan tarjetas de tour en el panel interno.
     *
     * @var array<string, mixed>|null
     */
    #[Groups(['catalogo:item:read'])]
    private ?array $imagenTarjeta = null;

    /**
     * Duración del tour (span de fechas nominales). Virtual, mismo origen que
     * $imagenTarjeta; equivale al `numDias` que ve el cliente en la portada.
     */
    #[Groups(['catalogo:item:read'])]
    private ?int $numDias = null;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read'])]
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, options: ['default' => '1.0000'])]
    private string $tipoCambio = '1.0000';

    /** @var array<string, mixed>|null */
    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $clasificacionFinanciera = null;

    // 🔥 NUEVA PROPIEDAD: CLASIFICACION FINANCIERA SIN COSTOS NI MÁRGENES
    /** @var array<string, mixed>|null */
    // ⚠️ SIN `pax_cotizacion:read`: ver getClasificacionFinancieraParaCliente().
    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $clasificacionFinancieraCliente = null;

    /**
     * @var Collection<int, CotizacionCotservicio>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'pax_cotizacion:read'])]
    #[ORM\OneToMany(mappedBy: 'cotizacion', targetEntity: CotizacionCotservicio::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechaInicioAbsoluta' => 'ASC'])]
    private Collection $cotservicios;


    public function __construct()
    {
        $this->initializeId();
        $this->cotservicios = new ArrayCollection();
        $this->historicos = new ArrayCollection();
    }

    public function __toString(): string
    {
        $padre = $this->file?->getNombreGrupo() ?? $this->catalogo?->getNombre() ?? 'Sin File';
        return sprintf('P%d - %s', $this->propuesta, $padre);
    }

    public function duplicar(): self
    {
        $copia = clone $this;               // clone superficial por defecto (sin __clone)
        $copia->resetId();

        // ⚠️ **Un clon NO vuelve a traducirse, y esto es lo que hace la copia viable.**
        //
        // Medido el 02/09/2026 sobre una cotización real de 17 servicios (162 entidades en el
        // árbol): `persist()` tardaba **245.532 ms** —cuatro minutos— y el `flush` posterior sólo
        // 663. El tiempo no era de la base: la conexión estaba `Sleep`. Era `prePersist`
        // llamando a Google por cada entidad nueva, a siete idiomas.
        //
        // Con el interruptor apagado: **73 ms de `persist` y 243 de `flush`**. 777 veces menos, y
        // cientos de llamadas al traductor que no se pagan.
        //
        // ── Por qué el `origenHash` no lo evitaba ──────────────────────────────
        // El clon SÍ arrastra las traducciones (`clone` es superficial), pero estas filas son
        // anteriores al hash y no lo llevan. `AutoTranslationService` retraduce a propósito
        // cualquier fila sin hash —«no sabemos si corresponde a su español, y la única forma
        // honesta de averiguarlo es rehacerla»—, y esa decisión está bien: quien declara que una
        // traducción vieja es correcta es `app:traduccion:sellar-hash`, no un clon.
        //
        // Sellar el histórico ayudaría, pero **no es lo que arregla esto**: aunque cada fila
        // llevara su hash, seguiríamos recorriendo 162 entidades para concluir que no hay nada
        // que hacer. Un clon es texto byte a byte idéntico a un árbol ya traducido; preguntarlo
        // es la parte que sobra.
        //
        // ⚠️ El flag es **virtual, no se guarda**: apaga el listener para este guardado y nada
        // más. Editar la copia mañana la traduce con normalidad.
        //
        // ⚠️ Consecuencia aceptada: si el original tenía un idioma sin rellenar, la copia nace
        // igual de vacía en vez de aprovechar el viaje. Rellenar huecos es trabajo del comando de
        // traducción o del siguiente guardado — no de copiar.
        $copia->setEjecutarTraduccion(false);

        $copia->cotservicios = new ArrayCollection();

        // Ni el vínculo ni la colección se copian: la copia empieza suelta y quien la crea decide
        // qué es. Arrastrarlos colgaría los históricos de la copia y no del original.
        $copia->derivadaDe = null;
        $copia->historicos = new ArrayCollection();

        foreach ($this->cotservicios as $servicio) {
            $copiaServicio = $servicio->duplicar();
            $copiaServicio->setCotizacion($copia);
            $copia->cotservicios->add($copiaServicio);
        }

        return $copia;
    }

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'file:item:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['cotizacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

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
     * Ganancia bruta calculada (venta - costo). Expuesta como valor derivado
     * para no forzar al frontend a hacer aritmética sobre strings decimales.
     */
    #[Groups(['cotizacion:read', 'file:item:read'])]
    public function getGanancia(): string
    {
        // ⚠️ `bcsub()` lanza `ValueError` en PHP 8 si el texto no es numérico. Las columnas son
        // `decimal` y siempre traen dígitos, pero el tipo sólo dice `string`.
        //
        // Se lanza con el nombre del campo en vez de caer a '0': una ganancia calculada sobre un
        // cero inventado es una cifra PLAUSIBLE y falsa, y ésas son las que nadie revisa. Es el
        // mismo criterio que en `Parametro::texto()` y en el secreto de Mercure.
        // ⚠️ Se comprueba la PROPIEDAD directamente, no una copia en una variable de bucle: el
        // analizador sólo estrecha lo que ve comprobar, y con `foreach` el tipo de
        // `$this->totalVenta` seguía siendo `string` en la línea del `bcsub`.
        if (!is_numeric($this->totalVenta) || !is_numeric($this->totalCosto)) {
            throw new \LogicException(sprintf(
                'La cotización %s tiene importes que no son números (venta «%s», costo «%s»): '
                .'no se puede calcular la ganancia.',
                (string) $this->getId(),
                $this->totalVenta,
                $this->totalCosto,
            ));
        }

        return bcsub($this->totalVenta, $this->totalCosto, 2);
    }

    public function getFile(): ?CotizacionFile { return $this->file; }

    /**
     * ⚠️ Una cotización NO se desengancha de su expediente.
     *
     * El editor guarda con un PUT del árbol entero. Si por lo que sea el payload llega
     * sin `file` —o con `file: null`—, la cotización se quedaba huérfana en silencio:
     * la columna es nullable, así que Doctrine lo aceptaba sin rechistar y el expediente
     * perdía su versión sin que nada lo dijera.
     *
     * Sólo se notó porque al confirmar, `BibliaSnapshotService` copia este file a cada
     * OperacionServicio, donde la columna SÍ es NOT NULL: el guardado entero moría con
     * un «Column 'file_id' cannot be null» que no menciona ni la cotización ni el
     * expediente (14/08/2026, PUT /platform/sales/cotizacions/38e83a6c…). Sin esa
     * columna NOT NULL de por medio, el desenganche no habría dado ningún error.
     *
     * Se ignora el null en vez de lanzar: quitar el padre nunca es una intención real
     * del editor, y el guardado no debe caerse por un campo que el payload simplemente
     * no trajo. Asignar OTRO file sí se permite — eso sí es una operación deliberada.
     *
     * Doctrine hidrata por reflexión y no pasa por aquí, así que esto no interfiere con
     * la carga de entidades ni con las cotizaciones de catálogo (que nacen sin file).
     */
    public function setFile(?CotizacionFile $file): self
    {
        if ($file === null && $this->file !== null) {
            return $this;
        }

        $this->file = $file;

        return $this;
    }

    public function getCatalogo(): ?CotizacionCatalogo { return $this->catalogo; }
    public function setCatalogo(?CotizacionCatalogo $catalogo): self { $this->catalogo = $catalogo; return $this; }

    /**
     * @return list<array{valor?: string, moneda?: string, titulo?: list<array{language?: string, content?: string|null}>}>
     */
    public function getPreciosDesde(): array { return $this->preciosDesde; }
    /**
     * @param list<array{valor?: string, moneda?: string, titulo?: list<array{language?: string, content?: string|null}>}> $preciosDesde
     */
    public function setPreciosDesde(array $preciosDesde): self { $this->preciosDesde = $preciosDesde; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    /**
     * @return array<string, mixed>
     *
     * @return array<string, mixed>|null
     */
    public function getImagenPortada(): ?array { return $this->imagenPortada; }
    /**
     * @param array<string, mixed>|null $imagenPortada
     */
    public function setImagenPortada(?array $imagenPortada): self { $this->imagenPortada = $imagenPortada; return $this; }

    // Virtuales de tarjeta (ver CotizacionCatalogoAdminProvider)
    /**
     * @return array<string, mixed>
     */
    public function getImagenTarjeta(): ?array { return $this->imagenTarjeta; }
    /**
     * @param array<string, mixed>|null $imagenTarjeta
     */
    public function setImagenTarjeta(?array $imagenTarjeta): self { $this->imagenTarjeta = $imagenTarjeta; return $this; }
    public function getNumDias(): ?int { return $this->numDias; }
    public function setNumDias(?int $numDias): self { $this->numDias = $numDias; return $this; }

    public function getPropuesta(): int { return $this->propuesta; }
    public function setPropuesta(int $propuesta): self { $this->propuesta = $propuesta; return $this; }

    public function getFechaExpiracion(): ?\DateTimeImmutable { return $this->fechaExpiracion; }
    public function setFechaExpiracion(?\DateTimeImmutable $fechaExpiracion): self { $this->fechaExpiracion = $fechaExpiracion; return $this; }

    public function getMonedaGlobal(): string { return $this->monedaGlobal; }
    public function setMonedaGlobal(string $monedaGlobal): self { $this->monedaGlobal = $monedaGlobal; return $this; }

    public function getIdiomaCliente(): string { return $this->idiomaCliente; }
    public function setIdiomaCliente(string $idiomaCliente): self { $this->idiomaCliente = $idiomaCliente; return $this; }

    public function getTotalCosto(): string { return $this->totalCosto; }
    public function setTotalCosto(string $totalCosto): self { $this->totalCosto = $totalCosto; return $this; }

    public function getTotalVenta(): string { return $this->totalVenta; }
    public function setTotalVenta(string $totalVenta): self { $this->totalVenta = $totalVenta; return $this; }

    public function getTipoCambio(): string { return $this->tipoCambio; }
    public function setTipoCambio(string $tipoCambio): self { $this->tipoCambio = $tipoCambio; return $this; }

    /**
     * @return array<string, mixed>
     */
    public function getClasificacionFinanciera(): ?array { return $this->clasificacionFinanciera; }
    /**
     * @param array<string, mixed>|null $clasificacionFinanciera
     */
    public function setClasificacionFinanciera(?array $clasificacionFinanciera): self { $this->clasificacionFinanciera = $clasificacionFinanciera; return $this; }

    /**
     * De qué fila sale el DINERO que ve el cliente.
     *
     * ── La composición ──────────────────────────────────────────────────────
     * Una OPERATIVA se sirve al cliente **partida en dos**: el itinerario es suyo —es lo que de
     * verdad se va a operar— y el dinero es el de la confirmada de la que salió.
     *
     *     financiero  ←  SIEMPRE la confirmada
     *     itinerario  ←  la operativa
     *
     * Ya se vendió y ya se cobró: lo que pase en la operación es un tema proveedor–agencia.
     *
     * ── ⚠️ Por qué NO basta con heredarlo al abrir ──────────────────────────
     * `AbrirOperativaProcessor` copia el financiero al crearla, y aun así hace falta esto. El
     * editor **recalcula y envía `totalVenta` y `clasificacionFinancieraCliente` en cada
     * guardado** (`cotizacionEditorStore.ts`, «Inyección de la estructura financiera al payload»).
     * O sea que el valor heredado dura hasta el primer guardado — y guardar es exactamente lo que
     * se hace con una operativa: partir vuelos, mover cantidades.
     *
     * Sin esto, reorganizar la operación **le cambiaría los precios al cliente**. En silencio, y
     * en el sentido de que se los sube o se los baja según cómo saliera la operación.
     *
     * ── Por qué en lectura y no congelando el campo ─────────────────────────
     * Se pensó en un candado que devolviera los valores heredados al guardar. Se descartó porque
     * rompe el otro caso que sí se quiere: si alguien edita **la confirmada** —renegociar es
     * rutina, y F3 lo permite a propósito—, el cliente debe ver los precios nuevos. Leyendo de la
     * confirmada eso sale gratis; con una copia congelada habría que acordarse de propagarla.
     *
     * ⚠️ Sólo redirige la OPERATIVA. Un histórico también tiene `derivadaDe`, y el suyo apunta a
     * la viva: un histórico enseña **su propio** dinero, que es justo lo que fue a congelar.
     */
    public function origenFinancieroParaCliente(): self
    {
        return $this->estado === CotizacionEstadoEnum::OPERATIVA && $this->derivadaDe !== null
            ? $this->derivadaDe
            : $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Groups(['pax_cotizacion:read'])]
    #[SerializedName('clasificacionFinancieraCliente')]
    public function getClasificacionFinancieraParaCliente(): ?array
    {
        return $this->origenFinancieroParaCliente()->clasificacionFinancieraCliente;
    }

    /**
     * ⚠️ `SerializedName` a propósito: la API sigue diciendo `totalVenta`, así que ninguna vista de
     * `pax` cambia. Lo que cambia es de dónde sale el número.
     *
     * ⚠️ Pero **`api.d.ts` sí se mueve**, y conviene saberlo antes de repetir el patrón: un campo
     * servido por getter pasa de `totalVenta: string` a `readonly totalVenta?: string`. API
     * Platform no puede prometer que un método esté siempre ahí como promete una columna. El
     * typecheck de las dos apps salió limpio —nadie escribía este campo desde `pax`—, pero el día
     * que alguien lo lea sin comprobar `undefined`, el compilador se lo dirá.
     */
    #[Groups(['pax_cotizacion:read'])]
    #[SerializedName('totalVenta')]
    public function getTotalVentaParaCliente(): string
    {
        return $this->origenFinancieroParaCliente()->totalVenta;
    }

    /**
     * El resumen financiero apto para vistas de cliente: totales y desglose de precios de venta,
     * sin el costo neto ni la utilidad de la agencia.
     *
     * ⚠️ El financiero **propio** de esta fila. Para servir al cliente NO se usa éste:
     * {@see self::getClasificacionFinancieraParaCliente()}, que en una operativa lee la confirmada.
     *
     * @return array<string, mixed>|null
     */
    public function getClasificacionFinancieraCliente(): ?array
    {
        return $this->clasificacionFinancieraCliente;
    }

    /**
     * Establece el resumen financiero apto para vistas de cliente.
     *
     * @param array|null $clasificacionFinancieraCliente
     * @return self
     *
     * @param array<string, mixed>|null $clasificacionFinancieraCliente
     */
    public function setClasificacionFinancieraCliente(?array $clasificacionFinancieraCliente): self
    {
        $this->clasificacionFinancieraCliente = $clasificacionFinancieraCliente;
        return $this;
    }

    /**
     * @return Collection<int, CotizacionCotservicio>
     */
    public function getCotservicios(): Collection { return $this->cotservicios; }
    public function addCotservicio(CotizacionCotservicio $cotservicio): self
    {
        if (!$this->cotservicios->contains($cotservicio)) {
            $this->cotservicios->add($cotservicio);
            $cotservicio->setCotizacion($this);
        }
        return $this;
    }
    public function removeCotservicio(CotizacionCotservicio $cotservicio): self
    {
        if ($this->cotservicios->removeElement($cotservicio)) {
            if ($cotservicio->getCotizacion() === $this) { $cotservicio->setCotizacion(null); }
        }
        return $this;
    }

    /**
     * ¿Se le puede enseñar esta propuesta al cliente?
     *
     * Estado público **y** no expirada — las dos condiciones que `CotizacionFilePublicProvider`
     * evalúa en DQL para el listado.
     *
     * ⚠️ **Es una regla de SEGURIDAD, y por eso importa que tenga un solo sitio.** Un espejo aquí
     * no falla ruidosamente: al desalinearse **publica lo que el otro lado ya ocultaba**. El
     * provider la sigue aplicando en SQL —ahí no puede llamar a un método de PHP— así que ahora
     * los dos miran la misma columna, `publicado`, en vez de una lista de estados.
     *
     * ⚠️ **El estado ya NO decide esto.** Una confirmada puede estar despublicada mientras se
     * reorganiza, y una pendiente puede previsualizarse sin publicarse.
     *
     * Cualquier superficie pública nueva llama aquí y devuelve **404 uniforme**: distinguir «no
     * existe» de «no es pública» le diría a quien prueba localizadores cuáles existen.
     */
    public function esVisibleParaCliente(?\DateTimeImmutable $ahora = null): bool
    {
        if (!$this->publicado) {
            return false;
        }

        return $this->fechaExpiracion === null
            || $this->fechaExpiracion >= ($ahora ?? new \DateTimeImmutable());
    }

    public function isPublicado(): bool { return $this->publicado; }
    public function setPublicado(bool $publicado): self { $this->publicado = $publicado; return $this; }

    public function getEstado(): CotizacionEstadoEnum { return $this->estado; }
    public function setEstado(CotizacionEstadoEnum $estado): self { $this->estado = $estado; return $this; }

    public function getNumPax(): int { return $this->numPax; }
    public function setNumPax(int $numPax): void { $this->numPax = $numPax; }

    public function getComision(): string { return $this->comision; }
    public function setComision(string $comision): void { $this->comision = $comision; }

    public function getAdelanto(): string { return $this->adelanto; }
    public function setAdelanto(string $adelanto): void { $this->adelanto = $adelanto; }

    public function isPrecioOculto(): bool { return $this->precioOculto; }
    public function setPrecioOculto(bool $precioOculto): void { $this->precioOculto = $precioOculto; }

    public function isTotalesOcultos(): bool { return $this->totalesOcultos; }
    public function setTotalesOcultos(bool $totalesOcultos): void { $this->totalesOcultos = $totalesOcultos; }


    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getTitulo(): array { return $this->titulo; }
    /**
     * @param list<array{language?: string, content?: string|null}> $titulo
     */
    public function setTitulo(array $titulo): self { $this->titulo = $titulo; return $this; }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getResumen(): array { return $this->resumen; }
    /**
     * @param list<array{language?: string, content?: string|null}> $resumen
     */
    public function setResumen(array $resumen): void { $this->resumen = $resumen; }

    public function getDerivadaDe(): ?self { return $this->derivadaDe; }

    /** De qué cotización viva salió esta foto. Plano, para no arrastrar el árbol entero. */
    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'file:item:read'])]
    public function getDerivadaDeId(): ?string { return (string) $this->derivadaDe?->getId() ?: null; }
    public function setDerivadaDe(?self $v): self { $this->derivadaDe = $v; return $this; }

    /** @return Collection<int, self> */
    public function getHistoricos(): Collection { return $this->historicos; }

    /**
     * Cuántas fotos del pasado tiene. Es lo que pinta la cabecera del editor.
     *
     * ⚠️ **La colección es `EXTRA_LAZY` y tiene que seguir siéndolo.** Sin eso, este `count()`
     * hidrata las cotizaciones enteras y las ordena por fecha, y una fila de esta tabla lleva
     * varios JSON grandes —`clasificacionFinanciera`, `titulo`, `resumen`—: MySQL intenta
     * meterlos en el buffer de ordenación y responde **«Out of sort memory»**. Con `EXTRA_LAZY`
     * esto es un `SELECT COUNT(*)` y no toca ni una fila. Mismo motivo que en
     * `CotizacionFile::$cotizaciones`.
     */
    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'file:item:read'])]
    public function getTotalHistoricos(): int { return $this->historicos->count(); }

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'file:item:read'])]
    public function isHistorico(): bool { return $this->estado->esHistorico(); }

}
