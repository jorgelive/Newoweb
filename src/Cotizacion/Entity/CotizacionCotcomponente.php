<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\AutoTranslate;
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
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
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
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
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
}
