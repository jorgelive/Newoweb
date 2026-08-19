<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;

/**
 * Entidad base para la logística pura (El insumo financiero).
 */
#[ApiResource(
    shortName: 'Componente',
    operations: [
        // Genera: GET /travel/componentes
        new GetCollection(
            normalizationContext: ['groups' => ['componente:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Mismo grupo que el Get individual, para que el frontend pueda
        // precargar en una sola petición el detalle completo (con tarifas
        // y componenteItems) de varios componentes a la vez vía ?id[]=...
        new GetCollection(
            uriTemplate: '/componentes/batch',
            normalizationContext: ['groups' => ['componente:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Genera: GET /travel/componentes/{id}
        new Get(
            normalizationContext: ['groups' => ['componente:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Genera: POST /travel/componentes
        new Post(
            denormalizationContext: ['groups' => ['componente:write']],
            securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear componentes.'
        ),

        // Genera: PUT /travel/componentes/{id}
        new Put(
            denormalizationContext: ['groups' => ['componente:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar componentes.'
        ),

        // Genera: DELETE /travel/componentes/{id}
        new Delete(
            security: "is_granted('" . Roles::MAESTROS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar componentes.'
        )
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_componente')]
#[ORM\HasLifecycleCallbacks]
class TravelComponente
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['componente:read', 'componente:item:read', 'componente:write', 'servicio:item:read', 'segmento:read', 'segmento:item:read'])]
    #[Assert\NotBlank(message: 'El nombre del componente no puede estar vacío.')]
    #[Assert\Length(
        max: 150,
        maxMessage: 'El nombre del componente no puede superar los {{ limit }} caracteres.'
    )]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombre = null;

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['componente:read', 'componente:item:read', 'componente:write', 'servicio:item:read', 'segmento:read', 'segmento:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull(message: 'El título multiidioma es requerido.')]
    #[Assert\Type(type: 'array', message: 'El título debe ser una estructura de datos válida.')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['componente:read', 'componente:item:read', 'componente:write', 'servicio:item:read', 'segmento:read', 'segmento:item:read'])]
    #[Assert\NotNull(message: 'El tipo de componente es obligatorio.')]
    #[ORM\Column(type: 'string', length: 50, enumType: ComponenteTipoEnum::class)]
    private ComponenteTipoEnum $tipo = ComponenteTipoEnum::EXTRAS;

    #[Groups(['componente:read', 'componente:item:read', 'componente:write', 'servicio:item:read', 'segmento:read', 'segmento:item:read'])]
    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1})?$/',
        message: 'La duración debe ser un número decimal válido con máximo un decimal.'
    )]
    #[Assert\PositiveOrZero(message: 'La duración estimada no puede ser negativa.')]
    #[ORM\Column(type: 'decimal', precision: 4, scale: 1, nullable: true)]
    private ?string $duracion = null;

    #[Groups(['componente:read', 'componente:item:read', 'componente:write', 'servicio:item:read', 'segmento:read', 'segmento:item:read'])]
    #[Assert\PositiveOrZero(message: 'Los días de anticipación de alerta no pueden ser negativos.')]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $anticipacionalerta = null;

    /**
     * 👇 CASCADA HACIA ABAJO (Items descriptivos)
     */
    /** @var Collection<int, TravelComponenteItem> */
    #[Assert\Valid]
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\OneToMany(mappedBy: 'componente', targetEntity: TravelComponenteItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $componenteItems;

    /**
     * 👇 CASCADA HACIA ABAJO (Tarifas)
     * Ordenamos la colección de tarifas por el campo nombreInterno de forma ascendente.
     * Se aplica Assert\Valid para disparar en cadena la validación interna de cada tarifa mapeada.
     */
    /** @var Collection<int, TravelTarifa> */
    #[Assert\Valid]
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\OneToMany(mappedBy: 'componente', targetEntity: TravelTarifa::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['nombreInterno' => 'ASC'])]
    private Collection $tarifas;

    // ─────────────────────────────────────────────────────────────────────────
    // PROVEEDOR — a quién se le compra esta logística
    //
    // Colgaba de `TravelTarifa`, y ahí el campo quedó **abandonado: 5 de 904**. No por
    // desidia — un componente llega a tener 19 tarifas y nadie repite el mismo prestador
    // 19 veces. Al subirlo aquí se llena una vez, que es lo que lo hace realista.
    //
    // Es el mismo movimiento que ya se hizo en la cotización (`docs/Cotizaciones.md` §6.c),
    // un nivel más arriba. Y desbloquea el filtro de tarifas por prestador del editor, que
    // depende de este vínculo y hoy casi nunca se activa por falta de dato.
    //
    // `readableLink: false` como el resto: la API devuelve IRIs y corta la recursividad.
    // ─────────────────────────────────────────────────────────────────────────

    #[Groups(['componente:read', 'componente:item:read', 'componente:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelOrganizacion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TravelOrganizacion $prestador = null;

    /** El servicio concreto que se le compra (ej. el tipo de habitación). */
    #[Groups(['componente:read', 'componente:item:read', 'componente:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelOrganizacionServicio::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TravelOrganizacionServicio $prestadorServicio = null;

    /**
     * 🚫 CORTE CIRCULAR: No tiene grupos de lectura profunda, solo IRIs
     */
    /** @var Collection<int, TravelServicio> */
    #[ORM\ManyToMany(targetEntity: TravelServicio::class, mappedBy: 'componentes')]
    private Collection $servicios;

    /**
     * 🔍 SOLO LECTURA: lado inverso para saber en qué Segmentos (itinerarios) se está
     * inyectando este componente. El dueño real es TravelSegmentoComponente.
     */
    /** @var Collection<int, TravelSegmentoComponente> */
    #[ORM\OneToMany(mappedBy: 'componente', targetEntity: TravelSegmentoComponente::class)]
    private Collection $segmentoComponentesInyectados;

    /**
     * Centros de operación / lugares. Este es el lado DUEÑO.
     *
     * Multivaluado a propósito: un servicio de Ica que se despacha desde Lima lleva «Lima»
     * y «Ica». Ver {@see TravelLugar} para por qué el vocabulario es plano y no un árbol.
     *
     * Va también en `componente:read` (la colección, no sólo el item) porque es el grupo que
     * sirve la carga en lote `?id[]=` con la que el cuadro de tráfico pinta sus badges de una
     * sola petición. Con `readableLink: false` viajan como IRIs, así que el coste por fila en
     * la precarga del editor de cotizaciones es mínimo.
     *
     * Los JoinColumn van explícitos: la naming strategy generaría `travel_componente_id` /
     * `travel_lugar_id` y la migración está escrita a mano — si se adivinan, divergen.
     *
     * @var Collection<int, TravelLugar>
     */
    #[Groups(['componente:read', 'componente:item:read', 'componente:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToMany(targetEntity: TravelLugar::class, inversedBy: 'componentes')]
    #[ORM\JoinTable(name: 'travel_componente_lugar_pool')]
    #[ORM\JoinColumn(name: 'componente_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'lugar_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['orden' => 'ASC', 'nombre' => 'ASC'])]
    private Collection $lugares;

    public function __construct()
    {
        $this->initializeId();
        $this->componenteItems = new ArrayCollection();
        $this->tarifas = new ArrayCollection();
        $this->servicios = new ArrayCollection();
        $this->segmentoComponentesInyectados = new ArrayCollection();
        $this->lugares = new ArrayCollection();
    }

    /**
     * 🔥 CLONACIÓN PROFUNDA (DEEP CLONE)
     * Delega la responsabilidad a la propia entidad para mantener la atomicidad.
     */
    public function __clone()
    {
        // 1. Limpieza total de identidad y auditoría (vía Traits)
        $this->resetId();
        $this->resetTimestamps();

        // 2. Ajustar el nombre operativo
        if ($this->nombre) {
            $this->nombre = '(Clon) ' . $this->nombre;
        }

        // 3. Clonar profundamente los Ítems (Inclusiones)
        $itemsOriginales = $this->componenteItems;
        $this->componenteItems = new ArrayCollection();
        foreach ($itemsOriginales as $itemOriginal) {
            $clonItem = clone $itemOriginal;
            $this->addComponenteItem($clonItem); // Esto setea el parent de forma segura
        }

        // 4. Clonar profundamente las Tarifas
        $tarifasOriginales = $this->tarifas;
        $this->tarifas = new ArrayCollection();
        foreach ($tarifasOriginales as $tarifaOriginal) {
            $clonTarifa = clone $tarifaOriginal;
            $this->addTarifa($clonTarifa);
        }

        // 5. Reiniciar la trazabilidad (Un clon no debe estar vinculado a los servicios del original)
        $this->servicios = new ArrayCollection();

        // 6. Los lugares SÍ se conservan: un clon de «City Tour Cusco» se sigue operando desde
        // Cusco. Pero hay que copiar la MEMBRESÍA, no la colección: sin esta reasignación el
        // clon comparte el mismo PersistentCollection que el original y etiquetar a uno
        // reetiqueta al otro, en silencio. Mismo criterio que TravelServicio::__clone().
        $lugaresOriginales = $this->lugares;
        $this->lugares = new ArrayCollection();
        foreach ($lugaresOriginales as $lugarOriginal) {
            $this->addLugar($lugarOriginal);
        }
    }

    /**
     * Retorna el nombre del componente para las interfaces (EasyAdmin/Selects).
     */
    public function __toString(): string
    {
        return $this->nombre ?? 'Componente sin nombre';
    }

    #[Groups(['componente:read', 'componente:item:read', 'servicio:item:read', 'segmento:read', 'segmento:item:read', 'cotizacion:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /**
     * Obtiene el nombre interno del componente.
     */
    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    /**
     * Establece el nombre interno del componente.
     */
    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    /**
     * Obtiene el título multilingüe visible para el cliente.
     *
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getTitulo(): array
    {
        return $this->titulo;
    }

    /**
     * Establece el título multilingüe visible para el cliente.
     *
     * @param list<array{language?: string, content?: string|null}> $titulo
     */
    public function setTitulo(array $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    /**
     * Obtiene la categoría operativa del componente.
     */
    public function getTipo(): ComponenteTipoEnum
    {
        return $this->tipo;
    }

    /**
     * Establece la categoría operativa del componente.
     */
    public function setTipo(ComponenteTipoEnum $tipo): self
    {
        $this->tipo = $tipo;
        return $this;
    }

    /**
     * Obtiene la duración estimada en horas.
     */
    public function getDuracion(): ?string
    {
        return $this->duracion;
    }

    /**
     * Establece la duración estimada en horas.
     */
    public function setDuracion(?string $duracion): self
    {
        $this->duracion = $duracion;
        return $this;
    }

    /**
     * Obtiene los días de anticipación para alertas operativas.
     */
    public function getAnticipacionalerta(): ?int
    {
        return $this->anticipacionalerta;
    }

    /**
     * Establece los días de anticipación para alertas operativas.
     */
    public function setAnticipacionalerta(?int $anticipacionalerta): self
    {
        $this->anticipacionalerta = $anticipacionalerta;
        return $this;
    }

    /**
     * Devuelve los ítems internos que componen este servicio logístico.
     *
     * @return Collection<int, TravelComponenteItem>
     *
     * @return Collection<int, TravelComponenteItem>
     */
    public function getComponenteItems(): Collection
    {
        return $this->componenteItems;
    }

    /**
     * Agrega un ítem descriptivo o financiero a este componente.
     */
    public function addComponenteItem(TravelComponenteItem $componenteItem): self
    {
        if (!$this->componenteItems->contains($componenteItem)) {
            $this->componenteItems->add($componenteItem);
            $componenteItem->setComponente($this);
        }
        return $this;
    }

    /**
     * Elimina un ítem descriptivo o financiero de este componente.
     */
    public function removeComponenteItem(TravelComponenteItem $componenteItem): self
    {
        if ($this->componenteItems->removeElement($componenteItem)) {
            if ($componenteItem->getComponente() === $this) {
                $componenteItem->setComponente(null);
            }
        }
        return $this;
    }

    /**
     * Devuelve el listado de tarifas configuradas para este componente.
     *
     * @return Collection<int, TravelTarifa>
     *
     * @return Collection<int, TravelTarifa>
     */
    /**
     * El servicio elegido tiene que ser de ese prestador.
     *
     * Vivía en `TravelTarifa`, y se mudó con los campos: cruzarlos sólo tiene sentido donde
     * conviven. Sin esto se puede guardar «Hotel A» con «habitación doble del Hotel B», que
     * no falla al escribir y sale mal en la cotización.
     */
    #[Assert\Callback]
    public function validarServicioDelOrganizacion(ExecutionContextInterface $context): void
    {
        if ($this->prestadorServicio === null || $this->prestador === null) {
            return;
        }

        if ($this->prestadorServicio->getOrganizacion() !== $this->prestador) {
            $context->buildViolation('El servicio seleccionado no pertenece al prestador del componente.')
                ->atPath('prestadorServicio')
                ->addViolation();
        }
    }

    public function getOrganizacion(): ?TravelOrganizacion { return $this->prestador; }
    public function setOrganizacion(?TravelOrganizacion $v): self { $this->prestador = $v; return $this; }

    public function getOrganizacionServicio(): ?TravelOrganizacionServicio { return $this->prestadorServicio; }
    public function setOrganizacionServicio(?TravelOrganizacionServicio $v): self { $this->prestadorServicio = $v; return $this; }

    /** @return Collection<int, TravelTarifa> */
    public function getTarifas(): Collection
    {
        return $this->tarifas;
    }

    /**
     * Añade una tarifa maestra al componente.
     */
    public function addTarifa(TravelTarifa $tarifa): self
    {
        if (!$this->tarifas->contains($tarifa)) {
            $this->tarifas->add($tarifa);
            $tarifa->setComponente($this);
        }

        return $this;
    }

    /**
     * Elimina una tarifa maestra del componente.
     */
    public function removeTarifa(TravelTarifa $tarifa): self
    {
        if ($this->tarifas->removeElement($tarifa)) {
            if ($tarifa->getComponente() === $this) {
                $tarifa->setComponente(null);
            }
        }
        return $this;
    }

    /**
     * Devuelve la colección de Servicios Mayores (Bolsas) que incluyen a este componente.
     *
     * @return Collection<int, TravelServicio>
     *
     * @return Collection<int, TravelServicio>
     */
    public function getServicios(): Collection
    {
        return $this->servicios;
    }

    /**
     * Añade un servicio al registro de uso de este componente.
     */
    public function addServicio(TravelServicio $servicio): self
    {
        if (!$this->servicios->contains($servicio)) {
            $this->servicios->add($servicio);
            $servicio->addComponente($this);
        }
        return $this;
    }

    /**
     * Elimina un servicio del registro de uso de este componente.
     */
    public function removeServicio(TravelServicio $servicio): self
    {
        if ($this->servicios->removeElement($servicio)) {
            $servicio->removeComponente($this);
        }
        return $this;
    }

    /**
     * @return Collection<int, TravelSegmentoComponente>
     *
     * @return Collection<int, TravelSegmentoComponente>
     */
    public function getSegmentoComponentesInyectados(): Collection
    {
        return $this->segmentoComponentesInyectados;
    }

    /**
     * @return Collection<int, TravelLugar>
     *
     * @return Collection<int, TravelLugar>
     */
    public function getLugares(): Collection
    {
        return $this->lugares;
    }

    public function addLugar(TravelLugar $lugar): self
    {
        if (!$this->lugares->contains($lugar)) {
            $this->lugares->add($lugar);
        }

        return $this;
    }

    public function removeLugar(TravelLugar $lugar): self
    {
        $this->lugares->removeElement($lugar);

        return $this;
    }

    // 🔥 VIRTUALES PARA EASYADMIN (Evitan error 500 al usar TextField con renderAsHtml)
    public function getVirtualTitulo(): string { return ''; }
    public function getVirtualServicios(): string { return ''; }
    public function getVirtualItems(): string { return ''; }
    public function getVirtualTarifas(): string { return ''; }
    public function getVirtualSegmentosInyectados(): string { return ''; }
    public function getVirtualLugares(): string { return ''; }
}