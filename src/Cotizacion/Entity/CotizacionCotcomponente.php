<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\AutoTranslate;
use App\Cotizacion\Dto\PrestadorResuelto;
use App\Cotizacion\Dto\CompradorResuelto;
use App\Cotizacion\Enum\ComponenteEstadoEnum;
use App\Cotizacion\Enum\DetalleOperativoTipoEnum;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Travel\Enum\ComponenteModoEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $nombreSnapshot = [];

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
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['nombreSnapshot'], format: 'text')]
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

    /** @var list<array<string, mixed>> */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['detalle'], format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $detallesOperativos = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $tipo = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
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
    // PRESTADOR — quién presta el servicio, no a quién se le compra
    //
    // El PROVEEDOR (más abajo) responde «¿a quién le compro?»: es un hecho comercial
    // que sólo existe si hay compra. El PRESTADOR responde «¿quién lo presta / dónde
    // ocurre?», y existe siempre — el hotel que el pasajero reservó por su cuenta no
    // se le compra a nadie, pero es el punto de recojo del transportista y la
    // referencia que hace que la propuesta se lea completa.
    //
    // Es OPCIONAL y blando: si está vacío se hereda —componente → día → proveedor del
    // propio componente, ver resolverPrestador()—, así que las cotizaciones existentes
    // se comportan exactamente igual que antes.
    //
    // Dos caras, el mismo patrón que usan los tres roles:
    //   · pública   → titulo (i18n), url, imágenes  ... el cliente las ve
    //   · operativa → nombre comercial, teléfono, dirección ... nunca salen a pax
    // Los operativos NO llevan el grupo pax_cotizacion:read. Y de los públicos decide
    // `$prestadorVisible`, no el modo: ver ahí el porqué y
    // CotizacionCotcomponentePrestadorPublicNormalizer. Ver docs/Cotizaciones.md §6.c.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ¿Se nombra al prestador en ESTA propuesta?
     *
     * Antes no existía y la respuesta se re-derivaba en cada lectura de `$modo`, que es
     * una clasificación comercial y no una decisión editorial. Dos efectos que nadie
     * había decidido: cambiar un componente de `no_incluido` a `incluido` **borraba el
     * prestador de la vista del cliente en silencio**, y asignar un prestador sólo para
     * tener el teléfono del recojo lo publicaba de paso, porque el editor copia siempre
     * el título. No había forma de decir «esto es operativo».
     *
     * Ahora es un valor que se decide una vez y se guarda. La regla vieja no desaparece:
     * pasa a ser el DEFAULT al asignar (ver `onPrestadorComponenteChange()` en el store,
     * que lo siembra con `modo === no_incluido` Y la bandera del maestro
     * `Proveedor::$visibleParaCliente`). A partir de ahí manda lo guardado, así que el
     * modo puede cambiar sin reescribir lo que el cliente ve.
     *
     * Arranca en `false` por el mismo motivo que la del maestro: el olvido caro es
     * nombrar a quien no tocaba, no callar a quien sí.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $prestadorVisible = false;

    /** SOFT-LINK al catálogo maestro (App\Travel\Entity\Proveedor). */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorMaestroId = null;

    /** Nombre comercial. Operativo: identifica al prestador en La Biblia. */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $prestadorNombreSnapshot = null;

    /**
     * Título de cara al cliente (I18nContent[]), traducible.
     *
     * @var list<array{language?: string, content?: string|null}>
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $prestadorTituloSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $prestadorUrlSnapshot = null;

    /**
     * Galería del prestador (snapshot), para la tarjeta de referencia en pax.
     *
     * @var list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}>
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'json')]
    private array $prestadorImagenesSnapshot = [];

    /**
     * Teléfono y dirección: lo que el transportista necesita para el recojo.
     *
     * Se congelan aquí y no se leen del maestro al operar porque La Biblia es un
     * snapshot: el día del servicio tiene que decir el teléfono que valía cuando se
     * vendió, no el que alguien cambió después en el catálogo.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $prestadorTelefonoSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $prestadorDireccionSnapshot = null;

    // ─────────────────────────────────────────────────────────────────────────
    // PROVEEDOR — a quién se le compra este componente
    //
    // Vivía anidado en cada CotizacionCottarifa, heredado del sistema antiguo, que
    // lo puso ahí para admitir varios proveedores por componente. **Ese caso nunca
    // se dio**: 19 de 19 componentes con proveedor tienen exactamente uno, y en el
    // catálogo maestro el campo está abandonado (5 de 904) porque un componente
    // llega a tener 19 tarifas y nadie repite el mismo dato 19 veces.
    //
    // El coste no era sólo teclear: obligaba a RECONSTRUIR en la vista una identidad
    // que la estructura había partido, y esa deduplicación traía sus propios fallos
    // —el mapa de pax se indexaba por título de tarifa, así que dos tarifas homónimas
    // colisionaban—. Se guarda una vez, donde de verdad ocurre: el componente.
    //
    // Qué se queda en la tarifa: `proveedorMaestroId` + `proveedorNombreSnapshot`,
    // que responden «¿de quién es ESTE precio?» y sí son legítimamente por línea
    // (puedes comparar la tarifa de Cosituc contra la de un revendedor). Lo que se
    // mudó aquí es la PRESENTACIÓN, que es lo que se muestra una sola vez.
    //
    // Mismo patrón de dos caras que el prestador: pública (título, url, imágenes) y
    // operativa (nombre). Y misma disciplina que allí — quién lo ve lo dice una
    // bandera guardada, no la presencia de un dato.
    // ─────────────────────────────────────────────────────────────────────────

    /** SOFT-LINK al catálogo maestro (App\Travel\Entity\Proveedor). */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $proveedorMaestroId = null;

    /** Nombre comercial. Operativo: identifica al proveedor en el histórico. */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $proveedorNombreSnapshot = null;

    /**
     * ¿Se nombra al proveedor en ESTA propuesta?
     *
     * Sustituye al `proveedorOculto` por tarifa, y viene en positivo a propósito: la
     * condición negada obliga a leer dos veces cada vez que se combina con el flag
     * global. El global (`Cotizacion::$proveedorOculto`) sigue donde estaba y sigue
     * mandando — es el interruptor white-label de toda la propuesta, y esta bandera
     * sólo puede afinar hacia abajo, nunca forzar que se muestre.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $proveedorVisible = false;

    /**
     * Título de cara al cliente (I18nContent[]), traducible.
     *
     * @var list<array{language?: string, content?: string|null}>
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $proveedorTituloSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $proveedorUrlSnapshot = null;

    /** @var list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}> */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'json')]
    private array $proveedorImagenesSnapshot = [];

    /**
     * El servicio concreto que se le compra (ej. el tipo de habitación).
     *
     * Acompaña al proveedor y no a la tarifa por el mismo motivo: 0 componentes
     * tienen dos distintos. Si algún día una tarifa necesitara su propio tipo de
     * habitación, el sitio correcto sería volver a bajarlo a la tarifa — pero
     * entonces con datos que lo respalden, que hoy no los hay.
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $proveedorServicioMaestroId = null;

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $proveedorServicioTituloSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $proveedorServicioUrlSnapshot = null;

    /** @var list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}> */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'json')]
    private array $proveedorServicioImagenesSnapshot = [];

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
    // ⚠️ **Siempre apunta a un `Proveedor`, nunca a una persona.** También los internos:
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

    /** SOFT-LINK al catálogo maestro (App\Travel\Entity\Proveedor). */
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
    }

    /**
     * Clona el componente y clona profundamente sus tarifas.
     */
    public function duplicar(): self
    {
        $copia = clone $this;   // clone superficial por defecto (sin __clone)
        $copia->resetId();

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

    #[Groups(['cotizacion:read', 'cotizacion:item:read'])]
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
    public function getNombreSnapshot(): array { return $this->nombreSnapshot; }

    /**
     * Establece el snapshot del nombre del componente.
     *
     * @param array $nombreSnapshot
     * @return self
     *
     * @param list<array{language?: string, content?: string|null}> $nombreSnapshot
     */
    public function setNombreSnapshot(array $nombreSnapshot): self { $this->nombreSnapshot = $nombreSnapshot; return $this; }

    /**
     * Obtiene la cantidad de componentes instanciados.
     *
     * @return int
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
     * Obtiene los detalles operativos internos.
     *
     * @return array
     *
     * @return list<array<string, mixed>>
     */
    public function getDetallesOperativos(): array
    {
        return $this->detallesOperativos;
    }

    /**
     * Establece los detalles operativos internos, validando su tipo.
     *
     * @param array $detallesOperativos
     * @return self
     * @throws \InvalidArgumentException
     *
     * @param list<array<string, mixed>> $detallesOperativos
     */
    public function setDetallesOperativos(array $detallesOperativos): self
    {
        foreach ($detallesOperativos as $bloque) {
            if (!isset($bloque['tipo']) || DetalleOperativoTipoEnum::tryFrom($bloque['tipo']) === null) {
                throw new \InvalidArgumentException('Tipo de detalle operativo inválido.');
            }
        }
        $this->detallesOperativos = $detallesOperativos;
        return $this;
    }

    /**
     * Superficie segura para exponer al cliente final: filtra bloques OPERATIVA.
     * Retorna únicamente los detalles que el cliente está autorizado a ver.
     *
     * @return array
     *
     * @return list<array<string, mixed>>
     */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    public function getDetallesParaCliente(): array
    {
        return array_values(array_filter(
            $this->detallesOperativos,
            static fn (array $bloque): bool =>
                ($bloque['tipo'] ?? null) === DetalleOperativoTipoEnum::CLIENTE->value
        ));
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
     * @param string|null $componenteMaestroId
     * @return self
     */
    public function setComponenteMaestroId(?string $componenteMaestroId): self { $this->componenteMaestroId = $componenteMaestroId; return $this; }

    public function getTipo(): ?string { return $this->tipo; }
    public function setTipo(?string $tipo): self { $this->tipo = $tipo; return $this; }

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

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getPrestadorTituloSnapshot(): array { return $this->prestadorTituloSnapshot; }
    /**
     * @param list<array{language?: string, content?: string|null}> $v
     *
     * @param list<array{language?: string, content?: string|null}> $v
     */
    public function setPrestadorTituloSnapshot(array $v): self { $this->prestadorTituloSnapshot = $v; return $this; }

    public function getPrestadorUrlSnapshot(): ?string { return $this->prestadorUrlSnapshot; }
    public function setPrestadorUrlSnapshot(?string $v): self { $this->prestadorUrlSnapshot = $v; return $this; }

    /**
     * @return list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}>
     */
    public function getPrestadorImagenesSnapshot(): array { return $this->prestadorImagenesSnapshot; }
    /**
     * @param list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}> $v
     *
     * @param list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}> $v
     */
    public function setPrestadorImagenesSnapshot(array $v): self { $this->prestadorImagenesSnapshot = $v; return $this; }

    public function getPrestadorTelefonoSnapshot(): ?string { return $this->prestadorTelefonoSnapshot; }
    public function setPrestadorTelefonoSnapshot(?string $v): self { $this->prestadorTelefonoSnapshot = $v; return $this; }

    public function getPrestadorDireccionSnapshot(): ?string { return $this->prestadorDireccionSnapshot; }
    public function setPrestadorDireccionSnapshot(?string $v): self { $this->prestadorDireccionSnapshot = $v; return $this; }

    public function getProveedorMaestroId(): ?string { return $this->proveedorMaestroId; }
    public function setProveedorMaestroId(?string $v): self { $this->proveedorMaestroId = $v; return $this; }

    public function getProveedorNombreSnapshot(): ?string { return $this->proveedorNombreSnapshot; }
    public function setProveedorNombreSnapshot(?string $v): self { $this->proveedorNombreSnapshot = $v; return $this; }

    /** ¿Se nombra al proveedor en esta propuesta? El flag global manda por encima. */
    public function isProveedorVisible(): bool { return $this->proveedorVisible; }
    public function setProveedorVisible(bool $v): self { $this->proveedorVisible = $v; return $this; }

    /** @return list<array{language?: string, content?: string|null}> */
    public function getProveedorTituloSnapshot(): array { return $this->proveedorTituloSnapshot; }

    /** @param list<array{language?: string, content?: string|null}> $v */
    public function setProveedorTituloSnapshot(array $v): self { $this->proveedorTituloSnapshot = $v; return $this; }

    public function getProveedorUrlSnapshot(): ?string { return $this->proveedorUrlSnapshot; }
    public function setProveedorUrlSnapshot(?string $v): self { $this->proveedorUrlSnapshot = $v; return $this; }

    /** @return list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}> */
    public function getProveedorImagenesSnapshot(): array { return $this->proveedorImagenesSnapshot; }

    /** @param list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}> $v */
    public function setProveedorImagenesSnapshot(array $v): self { $this->proveedorImagenesSnapshot = $v; return $this; }

    public function getProveedorServicioMaestroId(): ?string { return $this->proveedorServicioMaestroId; }
    public function setProveedorServicioMaestroId(?string $v): self { $this->proveedorServicioMaestroId = $v; return $this; }

    /** @return list<array{language?: string, content?: string|null}> */
    public function getProveedorServicioTituloSnapshot(): array { return $this->proveedorServicioTituloSnapshot; }

    /** @param list<array{language?: string, content?: string|null}> $v */
    public function setProveedorServicioTituloSnapshot(array $v): self { $this->proveedorServicioTituloSnapshot = $v; return $this; }

    public function getProveedorServicioUrlSnapshot(): ?string { return $this->proveedorServicioUrlSnapshot; }
    public function setProveedorServicioUrlSnapshot(?string $v): self { $this->proveedorServicioUrlSnapshot = $v; return $this; }

    /** @return list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}> */
    public function getProveedorServicioImagenesSnapshot(): array { return $this->proveedorServicioImagenesSnapshot; }

    /** @param list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}> $v */
    public function setProveedorServicioImagenesSnapshot(array $v): self { $this->proveedorServicioImagenesSnapshot = $v; return $this; }

    public function getCompradorMaestroId(): ?string { return $this->compradorMaestroId; }
    public function setCompradorMaestroId(?string $v): self { $this->compradorMaestroId = $v; return $this; }

    public function getCompradorNombreSnapshot(): ?string { return $this->compradorNombreSnapshot; }
    public function setCompradorNombreSnapshot(?string $v): self { $this->compradorNombreSnapshot = $v; return $this; }

    /** ¿Este componente encarga la compra a alguien distinto del proveedor? */
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

        // Sin encargo explícito se le compra al propio proveedor: es el caso normal, y es
        // lo que hace que la Orden de Servicio salga bien sin llenar nada.
        if ($this->tieneProveedorPropio()) {
            return new CompradorResuelto(
                origen: 'proveedor',
                maestroId: $this->proveedorMaestroId,
                nombre: $this->proveedorNombreSnapshot,
            );
        }

        return null;
    }

    /** ¿Este componente define proveedor propio? */
    public function tieneProveedorPropio(): bool
    {
        return $this->proveedorMaestroId !== null
            || trim($this->proveedorNombreSnapshot ?? '') !== '';
    }

    /** ¿Este componente define prestador propio, o lo hereda? */
    public function tienePrestadorPropio(): bool
    {
        return $this->prestadorMaestroId !== null
            || trim($this->prestadorNombreSnapshot ?? '') !== '';
    }

    /**
     * Resuelve QUÉ prestador aplica, con la cascada completa.
     *
     * `componente → día → proveedor de la tarifa`, y se toma la primera fuente que
     * diga algo, **entera**. No se mezclan campos de fuentes distintas: ver el
     * porqué en PrestadorResuelto.
     *
     * ⚠️ El tercer peldaño **ya no entra en la tarifa**. Antes leía la presentación
     * del proveedor de `$tarifaPrimaria`, lo que obligaba a elegir cuál de varias
     * tarifas mandaba —`BibliaSnapshotService::resolverTarifaPrimaria()`— y hacía que
     * el prestador dependiera de un desempate por `grupoTarifa`: si el proveedor
     * estaba puesto en otra tarifa, la cascada devolvía `null` en silencio. Ahora esa
     * presentación vive en el propio componente y el peldaño es directo.
     *
     * ⚠️ Espejo en TypeScript: `resolverPrestador()` en
     * `util/src/stores/cotizacion/cotizacionEditorStore.ts`. Si cambias el orden de
     * la cascada, se tocan los dos.
     */
    public function resolverPrestador(): ?PrestadorResuelto
    {
        if ($this->tienePrestadorPropio()) {
            return new PrestadorResuelto(
                origen: 'componente',
                maestroId: $this->prestadorMaestroId,
                nombre: $this->prestadorNombreSnapshot,
                titulo: $this->prestadorTituloSnapshot,
                url: $this->prestadorUrlSnapshot,
                imagenes: $this->prestadorImagenesSnapshot,
                telefono: $this->prestadorTelefonoSnapshot,
                direccion: $this->prestadorDireccionSnapshot,
            );
        }

        // El día sólo guarda id + nombre: es un default para el filtro de tarifas,
        // no contenido que se muestre. Por eso no arrastra título ni imágenes.
        $servicio = $this->cotservicio;
        if ($servicio !== null && $servicio->tienePrestadorPropio()) {
            return new PrestadorResuelto(
                origen: 'servicio',
                maestroId: $servicio->getPrestadorMaestroId(),
                nombre: $servicio->getPrestadorNombreSnapshot(),
            );
        }

        // Último recurso: a quien se le compra también es quien lo presta. Es el
        // caso normal — por eso el campo puede quedarse vacío en el 90% de los
        // componentes sin que nadie note nada. Se lee del propio componente, que es
        // donde vive ya la identidad del proveedor: una sola fuente, entera.
        if ($this->tieneProveedorPropio()) {
            return new PrestadorResuelto(
                origen: 'proveedor',
                maestroId: $this->proveedorMaestroId,
                nombre: $this->proveedorNombreSnapshot,
                titulo: $this->proveedorTituloSnapshot,
                url: $this->proveedorUrlSnapshot,
                imagenes: $this->proveedorImagenesSnapshot,
            );
        }

        return null;
    }
}