<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\AutoTranslate;
use App\Cotizacion\Dto\PrestadorResuelto;
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
    // El proveedor vive en la tarifa y responde «¿a quién le compro y a cuánto?»:
    // es un hecho comercial que sólo existe si hay compra. El prestador responde
    // «¿quién lo presta / dónde ocurre?», y existe siempre — el hotel que el
    // pasajero reservó por su cuenta no se le compra a nadie, pero es el punto de
    // recojo del transportista y la referencia que luce en la propuesta.
    //
    // Es OPCIONAL y blando: si está vacío se hereda (ver resolverPrestador*()), así
    // que las cotizaciones existentes se comportan exactamente igual que antes.
    //
    // Dos caras, mismo patrón que CotizacionCottarifa:
    //   · pública   → titulo (i18n), url, imágenes  ... el cliente las ve
    //   · operativa → nombre comercial, teléfono, dirección ... nunca salen a pax
    // Por eso los campos operativos NO llevan el grupo pax_cotizacion:read, y los
    // públicos los filtra CotizacionCotcomponentePrestadorPublicNormalizer para
    // que sólo se muestren en componentes `no_incluido`. Ver docs/Cotizaciones.md.
    // ─────────────────────────────────────────────────────────────────────────

    /** SOFT-LINK al catálogo maestro (App\Travel\Entity\Proveedor). */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorMaestroId = null;

    /** Nombre comercial. Operativo: identifica al prestador en La Biblia. */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $prestadorNombreSnapshot = null;

    /** Título de cara al cliente (I18nContent[]), traducible. */
    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $prestadorTituloSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write', 'cotizacion:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $prestadorUrlSnapshot = null;

    /** Galería del prestador (snapshot), para la tarjeta de referencia en pax. */
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
     */
    public function getNombreSnapshot(): array { return $this->nombreSnapshot; }

    /**
     * Establece el snapshot del nombre del componente.
     *
     * @param array $nombreSnapshot
     * @return self
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
     */
    public function getSnapshotItems(): array { return $this->snapshotItems; }

    /**
     * Establece los items guardados en el snapshot.
     *
     * @param array $snapshotItems
     * @return self
     */
    public function setSnapshotItems(array $snapshotItems): self { $this->snapshotItems = $snapshotItems; return $this; }

    /**
     * Obtiene las tarifas vinculadas al componente.
     *
     * @return Collection
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

    public function getPrestadorMaestroId(): ?string { return $this->prestadorMaestroId; }
    public function setPrestadorMaestroId(?string $v): self { $this->prestadorMaestroId = $v; return $this; }

    public function getPrestadorNombreSnapshot(): ?string { return $this->prestadorNombreSnapshot; }
    public function setPrestadorNombreSnapshot(?string $v): self { $this->prestadorNombreSnapshot = $v; return $this; }

    public function getPrestadorTituloSnapshot(): array { return $this->prestadorTituloSnapshot; }
    public function setPrestadorTituloSnapshot(array $v): self { $this->prestadorTituloSnapshot = $v; return $this; }

    public function getPrestadorUrlSnapshot(): ?string { return $this->prestadorUrlSnapshot; }
    public function setPrestadorUrlSnapshot(?string $v): self { $this->prestadorUrlSnapshot = $v; return $this; }

    public function getPrestadorImagenesSnapshot(): array { return $this->prestadorImagenesSnapshot; }
    public function setPrestadorImagenesSnapshot(array $v): self { $this->prestadorImagenesSnapshot = $v; return $this; }

    public function getPrestadorTelefonoSnapshot(): ?string { return $this->prestadorTelefonoSnapshot; }
    public function setPrestadorTelefonoSnapshot(?string $v): self { $this->prestadorTelefonoSnapshot = $v; return $this; }

    public function getPrestadorDireccionSnapshot(): ?string { return $this->prestadorDireccionSnapshot; }
    public function setPrestadorDireccionSnapshot(?string $v): self { $this->prestadorDireccionSnapshot = $v; return $this; }

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
     * La tarifa llega por parámetro en vez de resolverse aquí porque elegir cuál de
     * varias tarifas manda es una regla de operaciones que ya vive en
     * BibliaSnapshotService::resolverTarifaPrimaria(); duplicarla aquí garantizaba
     * que las dos copias se separaran. Quien no tenga una, pasa null y la cascada
     * simplemente se queda en el día.
     *
     * ⚠️ Espejo en TypeScript: `resolverPrestador()` en
     * `util/src/stores/cotizacion/cotizacionEditorStore.ts`. Si cambias el orden de
     * la cascada, se tocan los dos.
     */
    public function resolverPrestador(?CotizacionCottarifa $tarifaPrimaria = null): ?PrestadorResuelto
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
        // componentes sin que nadie note nada.
        if ($tarifaPrimaria !== null && $tarifaPrimaria->getProveedorNombreSnapshot() !== null) {
            return new PrestadorResuelto(
                origen: 'tarifa',
                maestroId: $tarifaPrimaria->getProveedorMaestroId(),
                nombre: $tarifaPrimaria->getProveedorNombreSnapshot(),
                titulo: $tarifaPrimaria->getProveedorTituloSnapshot(),
                url: $tarifaPrimaria->getProveedorUrlSnapshot(),
                imagenes: $tarifaPrimaria->getProveedorImagenesSnapshot(),
            );
        }

        return null;
    }
}