<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Entity\Trait\MediaTrait;
use App\Security\Roles;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Entidad PmsUnidad.
 * Representa un apartamento o habitación específica.
 */
#[ApiResource(
    operations: [
        // Solo lectura: alimenta el selector de unidad del calendario SPA (Vue).
        new GetCollection(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
            normalizationContext: ['groups' => ['pms_unidad:read']],
        ),
    ],
    routePrefix: '/pms',
    order: ['nombre' => 'ASC'],
    paginationEnabled: false,
)]
#[ORM\Entity]
#[ORM\Table(name: 'pms_unidad')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class PmsUnidad
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait; // 👈 Agrega control de traducción y sobreescritura

    use MediaTrait;

    #[ORM\ManyToOne(targetEntity: PmsEstablecimiento::class, inversedBy: 'unidades')]
    #[ORM\JoinColumn(name: 'establecimiento_id', referencedColumnName: 'id', nullable: false, columnDefinition: 'BINARY(16)')]
    private ?PmsEstablecimiento $establecimiento = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombre = null;

    /**
     * Identificador legible para la URL pública del catálogo: `/casita/casita-1`,
     * donde el primer segmento es el slug del establecimiento.
     *
     * Es único a nivel global, no por establecimiento: la ruta lo resuelve con
     * un Link propio de API Platform, que busca la unidad por slug sin cruzarla
     * con el padre. Un UNIQUE simple hace que ese lookup no pueda devolver dos.
     *
     * No sustituye al UUID en la guía del huésped: esa va por localizador de
     * reserva. El slug es solo del escaparate.
     */
    #[ORM\Column(type: 'string', length: 150, unique: true, nullable: true)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'El slug solo admite minúsculas, números y guiones simples (ej. casita-1).'
    )]
    #[Assert\Length(max: 150)]
    private ?string $slug = null;

    #[Vich\UploadableField(mapping: 'unidad_images', fileNameProperty: 'imageName')]
    #[Assert\File(
        maxSize: "5M",
        mimeTypes: ["image/jpeg", "image/png", "image/webp", "application/pdf"],
        mimeTypesMessage: "Formato no válido. Use JPG, PNG, WEBP o PDF."
    )]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $imageUpdatedAt = null;

    /**
     * PROPIEDAD VIRTUAL (No es columna de DB).
     * El AssetListener inyectará aquí la URL pública completa.
     * Usado por LiipImageField en EasyAdmin.
     */
    private ?string $imageUrl = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $codigoInterno = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $capacidad = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    // ============================================================
    // 🔐 SEGURIDAD Y CONECTIVIDAD
    // ============================================================

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoPuerta = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoCaja = null;

    /**
     * Almacena múltiples redes WiFi.
     * Estructura JSON:
     * [
     * {
     * "ssid": "Wifi_A",
     * "password": "123",
     * "ubicacion": [
     * {"language": "es", "content": "Salón"},
     * {"language": "en", "content": "Living Room"}
     * ]
     * }
     * ]
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['ubicacion'])] // 👈 Traducción anidada configurada
    private ?array $wifiNetworks = [];

    // ============================================================
    // 💰 TARIFA BASE
    // ============================================================

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: false, options: ['default' => '0.00'])]
    private string $tarifaBasePrecio = '0.00';

    #[ORM\Column(type: 'smallint', nullable: false, options: ['default' => 2])]
    private int $tarifaBaseMinStay = 2;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'tarifa_base_moneda_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $tarifaBaseMoneda = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $tarifaBaseActiva = true;

    // ============================================================
    // 👥 SUPLEMENTO POR PERSONA ADICIONAL
    // ============================================================

    /**
     * Hasta cuántas personas entran en la tarifa sin recargo.
     *
     * La tarifa de una noche cubre a un grupo de este tamaño; a partir de ahí cada persona
     * suma {@see $precioPaxAdicional}. No es la capacidad: la Casita 1 admite 8 pero su tarifa
     * cubre 5, así que las tres últimas pagan aparte.
     *
     * En `0` significa **sin regla configurada**, y entonces no se cobra suplemento ninguno.
     * Es el valor seguro por defecto: una unidad recién creada no debe empezar a cobrar
     * recargos que nadie ha decidido.
     */
    #[ORM\Column(name: 'pax_incluidos', type: 'smallint', options: ['default' => 0])]
    #[Groups(['pms_unidad:read', 'pms_unidad:write'])]
    private int $paxIncluidos = 0;

    /**
     * Cuánto suma cada persona por encima de `$paxIncluidos`, **por noche**.
     *
     * Por noche y no por estancia: tres noches con dos personas de más cobran seis veces el
     * suplemento, igual que el alojamiento. En la moneda de la tarifa base de la unidad — no
     * se guarda otra, porque un suplemento en distinta moneda que la noche que acompaña sería
     * imposible de sumar sin un tipo de cambio que nadie ha pedido.
     *
     * En `0.00` no hay suplemento: es el caso de la Casita 5, cuya tarifa cubre a sus dos
     * plazas y no admite a nadie más.
     */
    #[ORM\Column(name: 'precio_pax_adicional', type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['pms_unidad:read', 'pms_unidad:write'])]
    private string $precioPaxAdicional = '0.00';

    /**
     * Cuánto se cobra por limpieza. **Por estancia y no por noche**: se limpia al salir, una
     * vez, y una semana no ensucia siete veces.
     *
     * Se lee de dos formas según {@see $limpiezaEsPorcentaje}: `15.00` es «15.00 USD» o «15%
     * del alojamiento». En `0.00` no se cobra —y entonces no aparece en la cotización ni como
     * línea de cero—, tenga el flag el valor que tenga.
     *
     * Se cobra en todos los canales, a diferencia de {@see $porcentajeServicio}. Antes vivía
     * como texto libre en `pms_cargo_financiero` —«suplemento de limpieza», siempre 15.00 USD,
     * escrito a mano y con tres grafías distintas—, así que ninguna cotización automática la
     * incluía: el precio que veía el operador salía siempre corto.
     *
     * En la moneda de la tarifa base de la unidad, por el mismo motivo que el suplemento de
     * pax: sumar dos monedas pide un tipo de cambio que aquí nadie ha pedido.
     *
     * En `0.00` no se cobra.
     */
    #[ORM\Column(name: 'precio_limpieza', type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['pms_unidad:read', 'pms_unidad:write'])]
    private string $precioLimpieza = '0.00';

    /**
     * Cuántas habitaciones tiene. **No es capacidad, es privacidad**: dos grupos de 8 caben
     * igual en la Casita 3 (2 habitaciones) y en la 6 (3), y no es lo mismo.
     *
     * Existe para poder contestar «quiero estar más cómodo», que es una COMPARACIÓN entre
     * casitas. El texto completo sigue en la guía —item «Descripción», visibilidad pública—,
     * pero leerlo obliga a una llamada por casita y a comparar dos prosas. Esto es el resumen
     * que viaja en cada cotización.
     *
     * `null` = no se ha rellenado; el agente simplemente no lo menciona.
     */
    #[ORM\Column(name: 'habitaciones', type: 'smallint', nullable: true)]
    #[Groups(['pms_unidad:read', 'pms_unidad:write'])]
    private ?int $habitaciones = null;

    /**
     * Resumen de camas, para decirlo en una línea: «Hab 1: 2 dobles · Hab 2: 2 dobles».
     *
     * Texto y no una estructura de camas por habitación: hoy sólo hace falta para nombrarlo al
     * vender, y modelar camas por tipo y habitación sería inventar un esquema para una
     * pregunta que nadie ha hecho todavía. Cuando haga falta filtrar por «cama doble», se
     * migra con el caso delante.
     *
     * ⚠️ **Es un espejo de la guía, no la fuente.** El original está en el ítem «Descripción»
     * y es lo que ve el cliente en su guía; esto es la versión corta para el catálogo de
     * ventas. Al cambiar uno hay que mirar el otro.
     */
    #[ORM\Column(name: 'camas', type: 'string', length: 255, nullable: true)]
    #[Groups(['pms_unidad:read', 'pms_unidad:write'])]
    private ?string $camas = null;

    /**
     * Cuántas habitaciones tienen baño propio. Es lo primero que pregunta un grupo que se
     * reparte entre desconocidos, y lo que separa «caben» de «están cómodos».
     */
    #[ORM\Column(name: 'banos_privados', type: 'smallint', nullable: true)]
    #[Groups(['pms_unidad:read', 'pms_unidad:write'])]
    private ?int $banosPrivados = null;

    /**
     * Porcentaje de servicio, sobre alojamiento + suplemento de pax. La limpieza NO entra en
     * la base.
     *
     * ⚠️ **Este importe no lo cobra el PMS**: lo aplica la OTA por su cuenta. Se guarda para
     * poder cuadrar lo que el huésped acaba pagando en Booking con lo que aquí se cotiza —sin
     * él, los dos números no coinciden nunca y no hay forma de saber si la diferencia es la
     * comisión o un error—.
     *
     * De ahí que no baste un booleano: a qué canales aplica lo dice
     * {@see $serviciosCanales}, y a los directos no se les aplica ninguno.
     */
    /**
     * ¿El importe de arriba es un PORCENTAJE en vez de dinero?
     *
     * Con el flag puesto, `precio_limpieza = 15.00` significa «15% del alojamiento»; sin él,
     * «15.00 USD». Un solo valor y un interruptor, en vez de dos columnas de importe: con dos,
     * la pregunta «¿cuál manda si las dos tienen valor?» no tiene respuesta escrita en el dato
     * —sólo en el código— y basta olvidarse de poner una a cero para cobrar lo que no era.
     *
     * ⚠️ La base es el ALOJAMIENTO SOLO: el suplemento por persona NO entra. Es distinta de la
     * base de {@see $porcentajeServicio}, que sí lo incluye, y la diferencia es deliberada —se
     * eligió que la limpieza se explique como «el 15% de la tarifa» y no como un número que
     * sube con cada acompañante—. Las dos reglas viven cada una en su método y no se derivan
     * una de otra.
     */
    #[ORM\Column(name: 'limpieza_es_porcentaje', type: 'boolean', options: ['default' => false])]
    #[Groups(['pms_unidad:read', 'pms_unidad:write'])]
    private bool $limpiezaEsPorcentaje = false;

    #[ORM\Column(name: 'porcentaje_servicio', type: 'decimal', precision: 5, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['pms_unidad:read', 'pms_unidad:write'])]
    private string $porcentajeServicio = '0.00';

    // ============================================================
    // 🔗 RELACIONES
    // ============================================================

    /**
     * En qué canales se aplica {@see $porcentajeServicio}.
     *
     * Una lista y no un booleano porque el porcentaje no es del PMS, es de cada OTA: Booking
     * y Airbnb aplican el suyo y un directo no aplica ninguno. Vacía = no se aplica en
     * ninguno, que es el valor seguro —cotizar de más espanta a un cliente igual que cotizar
     * de menos cuesta dinero—.
     *
     * @var Collection<int, PmsChannel>
     */
    #[ORM\ManyToMany(targetEntity: PmsChannel::class)]
    #[ORM\JoinTable(name: 'pms_unidad_servicio_canal')]
    #[ORM\JoinColumn(name: 'unidad_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'channel_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['pms_unidad:read'])]
    private Collection $serviciosCanales;

    /** @var Collection<int, PmsUnidadBeds24Map> */
    #[ORM\OneToMany(mappedBy: 'pmsUnidad', targetEntity: PmsUnidadBeds24Map::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $beds24Maps;

    /** @var Collection<int, PmsRatesPushQueue> */
    #[ORM\OneToMany(mappedBy: 'unidad', targetEntity: PmsRatesPushQueue::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tarifaQueues;

    /** @var Collection<int, PmsBookingsPullQueue> */
    #[ORM\ManyToMany(targetEntity: PmsBookingsPullQueue::class, mappedBy: 'unidades')]
    private Collection $bookingsPullQueues;

    #[ORM\OneToOne(mappedBy: 'unidad', targetEntity: PmsGuia::class)]
    private ?PmsGuia $guia = null;

    /** Escaparate público. Estructura propia, independiente de la guía (ver PmsCatalogo). */
    #[ORM\OneToOne(mappedBy: 'unidad', targetEntity: PmsCatalogo::class)]
    private ?PmsCatalogo $catalogo = null;


    public function __construct()
    {
        $this->beds24Maps = new ArrayCollection();
        $this->tarifaQueues = new ArrayCollection();
        $this->bookingsPullQueues = new ArrayCollection();
        $this->serviciosCanales = new ArrayCollection();
        $this->wifiNetworks = []; // Inicializamos array vacío
        $this->id = Uuid::v7();
    }

    // ============================================================
    // GETTERS Y SETTERS BÁSICOS
    // ============================================================

    #[Groups(['pax_catalogo:read'])]
    public function getEstablecimiento(): ?PmsEstablecimiento { return $this->establecimiento; }
    public function setEstablecimiento(?PmsEstablecimiento $val): self { $this->establecimiento = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_unidad:read', 'pms_evento:read', 'pax_guia:read', 'pax_catalogo:read'])]
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $val): self { $this->nombre = $val; return $this; }

    // `pax_reserva:read` porque el índice de estancias enlaza a la guía por
    // {localizador}/{unidadSlug}: sin el slug en ese payload no hay forma de
    // construir el enlace desde PmsReservaView.vue. Es un dato público.
    #[Groups(['pms_unidad:read', 'pax_reserva:read', 'pax_guia:read', 'pax_catalogo:read'])]
    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $val): self { $this->slug = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pax_guia:read', 'pax_catalogo:read'])]
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $url): self { $this->imageUrl = $url; return $this; }

    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            $this->imageUpdatedAt = new DateTimeImmutable();
        }
    }
    public function getImageFile(): ?File { return $this->imageFile; }

    public function getImageName(): ?string { return $this->imageName; }
    public function setImageName(?string $imageName): void { $this->imageName = $imageName; }

    public function getCodigoInterno(): ?string { return $this->codigoInterno; }
    public function setCodigoInterno(?string $val): self { $this->codigoInterno = $val; return $this; }

    #[Groups(['pax_catalogo:read'])]
    public function getCapacidad(): ?int { return $this->capacidad; }
    public function setCapacidad(?int $val): self { $this->capacidad = $val; return $this; }

    #[Groups(['pms_unidad:read'])]
    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $val): self { $this->activo = $val; return $this; }

    // ============================================================
    // 🔐 GETTERS Y SETTERS DE SEGURIDAD
    // ============================================================

    public function getCodigoPuerta(): ?string
    {
        return $this->codigoPuerta;
    }

    public function setCodigoPuerta(?string $codigoPuerta): self
    {
        $this->codigoPuerta = $codigoPuerta;
        return $this;
    }

    public function getCodigoCaja(): ?string
    {
        return $this->codigoCaja;
    }

    public function setCodigoCaja(?string $codigoCaja): self
    {
        $this->codigoCaja = $codigoCaja;
        return $this;
    }

    public function getWifiNetworks(): array
    {
        $networks = $this->wifiNetworks ?? [];

        // Recorremos cada red para ordenar su "ubicacion"
        foreach ($networks as &$network) {
            if (isset($network['ubicacion']) && is_array($network['ubicacion'])) {
                $network['ubicacion'] = MaestroIdioma::ordenarParaFormulario($network['ubicacion']);
            }
        }

        return $networks;
    }

    public function setWifiNetworks(?array $wifiNetworks): self
    {
        // Importante: array_values asegura que se guarde como lista JSON [{},{}]
        // y no como objeto {"0":{}, "2":{}} si se borraron elementos intermedios.
        $this->wifiNetworks = $wifiNetworks ? array_values($wifiNetworks) : [];
        return $this;
    }

    /**
     * Helper: Obtiene el SSID de la primera red (Principal).
     * Útil para fallback si solo se muestra una red.
     */
    public function getMainWifiSsid(): string
    {
        return $this->wifiNetworks[0]['ssid'] ?? '';
    }

    /**
     * Helper: Obtiene el Password de la primera red (Principal).
     */
    public function getMainWifiPass(): string
    {
        return $this->wifiNetworks[0]['password'] ?? '';
    }

    // ============================================================
    // 💰 TARIFA BASE
    // ============================================================

    // La tarifa base viaja en `pms_unidad:read` porque el calendario de Tarifas
    // la pinta bajo el nombre de cada casita y el drawer «Generar Masivo» calcula
    // en vivo el precio efectivo de cada unidad con el % de ajuste. Sin ella, el
    // operador generaba a ciegas: el masivo no pide importe, sale del precio base
    // de cada unidad (GeneradorTarifaMasivaService).
    #[Groups(['pms_unidad:read'])]
    public function getTarifaBasePrecio(): string { return $this->tarifaBasePrecio; }
    public function setTarifaBasePrecio(string $val): self { $this->tarifaBasePrecio = $val; return $this; }

    #[Groups(['pms_unidad:read'])]
    public function getTarifaBaseMinStay(): int { return $this->tarifaBaseMinStay; }
    public function setTarifaBaseMinStay(int $val): self { $this->tarifaBaseMinStay = $val; return $this; }

    /**
     * Stub para la columna «Pax extra» del listado, que resume `paxIncluidos` y
     * `precioPaxAdicional` en una celda.
     *
     * Existe por una limitación de EasyAdmin: `TextField` valida el valor CRUDO antes de
     * pasarlo por `formatValue()`, así que anclarlo a `paxIncluidos` —un `int`— revienta con
     * «can't be converted into a string». El contenido real lo pinta el `formatValue` del
     * CRUD. Mismo truco que `PmsCatalogo::$virtualContenidos`.
     */
    public function getVirtualPaxExtra(): string
    {
        return '';
    }

    public function getPaxIncluidos(): int { return $this->paxIncluidos; }
    public function setPaxIncluidos(int $val): self { $this->paxIncluidos = max(0, $val); return $this; }

    public function getPrecioPaxAdicional(): string { return $this->precioPaxAdicional; }
    public function setPrecioPaxAdicional(string $val): self { $this->precioPaxAdicional = $val; return $this; }

    /**
     * ¿Esta casita cobra por persona adicional?
     *
     * Hacen falta las DOS cosas: un tope de personas incluidas y un precio. Con cualquiera de
     * las dos a cero no hay regla que aplicar, y se dice con un método para no repetir esa
     * condición en cada sitio que la mire.
     */
    public function cobraPaxAdicional(): bool
    {
        return $this->paxIncluidos > 0 && (float) $this->precioPaxAdicional > 0.0;
    }

    /**
     * Lo que suman las personas por encima de las incluidas, en toda la estancia.
     *
     * **Fuente única de la regla.** La usan el cálculo de tarifas, la previsualización del
     * agente y el cargo de alojamiento; escrita en cada sitio sería garantizar que un día
     * dejen de coincidir.
     *
     * Los niños cuentan igual que los adultos: `$pax` es el total de la estancia. Y se
     * multiplica por las noches porque el suplemento es por noche, como el alojamiento.
     *
     * Devuelve `0.0` —y no un error— cuando no hay regla configurada o el grupo cabe en la
     * tarifa: no cobrar de más es el fallo seguro.
     */
    public function suplementoPorPax(int $pax, int $noches): float
    {
        if (!$this->cobraPaxAdicional() || $noches < 1) {
            return 0.0;
        }

        $adicionales = max(0, $pax - $this->paxIncluidos);

        return $adicionales * (float) $this->precioPaxAdicional * $noches;
    }

    /**
     * La limpieza de toda la estancia. **No se multiplica por noches**: se limpia al salir.
     *
     * Dos formas de cobrarla y un orden claro: si hay PORCENTAJE puesto, manda; si no, el
     * importe fijo; y si los dos están en 0, no se cobra —y entonces la cotización no la
     * menciona siquiera, ni como línea de cero—.
     *
     * El porcentaje va sobre el ALOJAMIENTO SOLO, sin el suplemento por persona: se eligió
     * poder explicarlo como «el 15% de la tarifa» en vez de como un número que sube con cada
     * acompañante. Ojo: NO es la misma base que `servicioSobre()`, y no se derivan una de otra
     * a propósito.
     *
     * Recibe las noches sólo para no cobrarla en un tramo vacío, que es un error de llamada,
     * no una estancia de cero noches.
     */
    public function costoLimpieza(int $noches, float $alojamiento = 0.0): float
    {
        if ($noches < 1) {
            return 0.0;
        }

        $valor = (float) $this->precioLimpieza;

        if ($valor <= 0.0) {
            return 0.0;
        }

        return $this->limpiezaEsPorcentaje ? $alojamiento * $valor / 100.0 : $valor;
    }

    /** ¿La limpieza de esta casita se cobra a porcentaje? */
    public function limpiezaEsPorcentaje(): bool
    {
        return $this->limpiezaEsPorcentaje && (float) $this->precioLimpieza > 0.0;
    }

    /** ¿Aplica el porcentaje de servicio en este canal? */
    public function aplicaServicioEn(?string $canalId): bool
    {
        if ($canalId === null || (float) $this->porcentajeServicio <= 0.0) {
            return false;
        }

        foreach ($this->serviciosCanales as $canal) {
            if ($canal->getId() === $canalId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lo que la OTA añade sobre `$base`, o `0.0` si en ese canal no aplica.
     *
     * **Fuente única de la regla**, igual que `suplementoPorPax()`: la base es alojamiento +
     * suplemento de pax, y la limpieza queda FUERA. Quien llame pasa la base ya sumada; que
     * la limpieza no entre se decide aquí una vez y no en cada sitio que cotice.
     */
    public function servicioSobre(float $base, ?string $canalId): float
    {
        if (!$this->aplicaServicioEn($canalId) || $base <= 0.0) {
            return 0.0;
        }

        return $base * (float) $this->porcentajeServicio / 100.0;
    }

    /** @return list<string> Los ids de canal donde aplica el servicio. */
    public function idsCanalesServicio(): array
    {
        $ids = [];

        foreach ($this->serviciosCanales as $canal) {
            $id = $canal->getId();

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function getHabitaciones(): ?int { return $this->habitaciones; }
    public function setHabitaciones(?int $val): self { $this->habitaciones = $val; return $this; }

    public function getCamas(): ?string { return $this->camas; }
    public function setCamas(?string $val): self { $this->camas = $val; return $this; }

    public function getBanosPrivados(): ?int { return $this->banosPrivados; }
    public function setBanosPrivados(?int $val): self { $this->banosPrivados = $val; return $this; }

    public function getPrecioLimpieza(): string { return $this->precioLimpieza; }
    public function setPrecioLimpieza(string $val): self { $this->precioLimpieza = $val; return $this; }

    public function isLimpiezaEsPorcentaje(): bool { return $this->limpiezaEsPorcentaje; }
    public function setLimpiezaEsPorcentaje(bool $val): self { $this->limpiezaEsPorcentaje = $val; return $this; }

    public function getPorcentajeServicio(): string { return $this->porcentajeServicio; }
    public function setPorcentajeServicio(string $val): self { $this->porcentajeServicio = $val; return $this; }

    /** @return Collection<int, PmsChannel> */
    public function getServiciosCanales(): Collection { return $this->serviciosCanales; }

    public function addServicioCanal(PmsChannel $canal): self
    {
        if (!$this->serviciosCanales->contains($canal)) {
            $this->serviciosCanales->add($canal);
        }

        return $this;
    }

    public function removeServicioCanal(PmsChannel $canal): self
    {
        $this->serviciosCanales->removeElement($canal);

        return $this;
    }

    public function getTarifaBaseMoneda(): ?MaestroMoneda { return $this->tarifaBaseMoneda; }

    public function getTarifaBaseMonedaOrFail(): MaestroMoneda
    {
        if ($this->tarifaBaseMoneda === null) {
            throw new \LogicException('Tarifa base activa sin moneda definida en la unidad #' . ($this->id ?? 'new'));
        }
        return $this->tarifaBaseMoneda;
    }
    public function setTarifaBaseMoneda(?MaestroMoneda $val): self { $this->tarifaBaseMoneda = $val; return $this; }

    /**
     * Moneda de la tarifa base APLANADA (id y símbolo sueltos).
     *
     * No se serializa la relación: `/platform/pms/pms_unidads` normaliza sólo con
     * `pms_unidad:read`, y `MaestroMoneda` no publica nada en ese grupo — saldría
     * como IRI, inútil para pintar «S/ 250» en la casita.
     */
    #[Groups(['pms_unidad:read'])]
    public function getTarifaBaseMonedaId(): ?string { return $this->tarifaBaseMoneda?->getId(); }

    #[Groups(['pms_unidad:read'])]
    public function getTarifaBaseMonedaSimbolo(): ?string { return $this->tarifaBaseMoneda?->getSimbolo(); }

    #[Groups(['pms_unidad:read'])]
    public function isTarifaBaseActiva(): bool { return $this->tarifaBaseActiva; }
    public function setTarifaBaseActiva(bool $val): self { $this->tarifaBaseActiva = $val; return $this; }

    // ============================================================
    // GESTIÓN DE RELACIONES (Maps, Queues, Guia)
    // ============================================================

    public function getBeds24Maps(): Collection { return $this->beds24Maps; }

    public function addBeds24Map(PmsUnidadBeds24Map $map): self
    {
        if (!$this->beds24Maps->contains($map)) {
            $this->beds24Maps->add($map);
            $map->setPmsUnidad($this);
        }
        return $this;
    }

    public function removeBeds24Map(PmsUnidadBeds24Map $map): self
    {
        if ($this->beds24Maps->removeElement($map)) {
            if ($map->getPmsUnidad() === $this) {
                $map->setPmsUnidad(null);
            }
        }
        return $this;
    }

    public function getTarifaQueues(): Collection { return $this->tarifaQueues; }

    public function addTarifaQueue(PmsRatesPushQueue $queue): self
    {
        if (!$this->tarifaQueues->contains($queue)) {
            $this->tarifaQueues->add($queue);
            $queue->setUnidad($this);
        }
        return $this;
    }

    public function removeTarifaQueue(PmsRatesPushQueue $queue): self
    {
        if ($this->tarifaQueues->removeElement($queue)) {
            if ($queue->getUnidad() === $this) {
                $queue->setUnidad(null);
            }
        }
        return $this;
    }

    public function getBookingsPullQueues(): Collection { return $this->bookingsPullQueues; }

    public function addBookingsPullQueue(PmsBookingsPullQueue $job): self
    {
        if (!$this->bookingsPullQueues->contains($job)) {
            $this->bookingsPullQueues->add($job);
            $job->addUnidad($this);
        }
        return $this;
    }

    public function removeBookingsPullQueue(PmsBookingsPullQueue $job): self
    {
        if ($this->bookingsPullQueues->removeElement($job)) {
            $job->removeUnidad($this);
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->nombre ?? ('Unidad UUID ' . ($this->getId() ? $this->getId()->toBase32() : 'Nueva'));
    }

    public function getBeds24MapPrincipal(): ?PmsUnidadBeds24Map
    {
        foreach ($this->beds24Maps as $map) {
            if ($map->isEsPrincipal()) {
                return $map;
            }
        }
        return $this->beds24Maps->first() ?: null;
    }

    public function getCatalogo(): ?PmsCatalogo { return $this->catalogo; }
    public function setCatalogo(?PmsCatalogo $catalogo): self { $this->catalogo = $catalogo; return $this; }

    public function getGuia(): ?PmsGuia
    {
        return $this->guia;
    }

    public function setGuia(?PmsGuia $guia): self
    {
        $this->guia = $guia;

        if ($guia && $guia->getUnidad() !== $this) {
            $guia->setUnidad($this);
        }

        return $this;
    }

    #[Groups(['pax_reserva:read', 'pms_unidad:read', 'pms_evento:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }
}