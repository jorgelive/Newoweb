<?php

declare(strict_types=1);

namespace App\Travel\Entity;

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

/**
 * Entidad base para la logística pura (El insumo financiero).
 */
#[ApiResource(
    shortName: 'Componente', // 🔥 Define el recurso base para generar '/componentes'
    operations: [
        // Genera: GET /travel/componentes
        new GetCollection(
            normalizationContext: ['groups' => ['componente:read']],
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
    ],  // 🔥 Agrupa todas las rutas bajo el módulo logístico
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_componente')]
class TravelComponente
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['componente:read', 'componente:item:read', 'componente:write', 'servicio:item:read', 'segmento:item:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombre = null;

    #[Groups(['componente:read', 'componente:item:read', 'componente:write', 'servicio:item:read', 'segmento:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['componente:read', 'componente:item:read', 'componente:write', 'servicio:item:read', 'segmento:item:read'])]
    #[ORM\Column(type: 'string', length: 50, enumType: ComponenteTipoEnum::class)]
    private ComponenteTipoEnum $tipo = ComponenteTipoEnum::EXTRAS;

    #[Groups(['componente:read', 'componente:item:read', 'componente:write', 'servicio:item:read', 'segmento:item:read'])]
    #[ORM\Column(type: 'decimal', precision: 4, scale: 1, nullable: true)]
    private ?string $duracion = null;

    #[Groups(['componente:read', 'componente:item:read', 'componente:write', 'servicio:item:read', 'segmento:item:read'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $anticipacionalerta = null;

    /**
     * 👇 CASCADA HACIA ABAJO (Items descriptivos)
     */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\OneToMany(mappedBy: 'componente', targetEntity: TravelComponenteItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $componenteItems;

    /**
     * 👇 CASCADA HACIA ABAJO (Tarifas)
     */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\OneToMany(mappedBy: 'componente', targetEntity: TravelTarifa::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tarifas;

    /**
     * 🚫 CORTE CIRCULAR: No tiene grupos de lectura profunda, solo IRIs
     */
    #[ORM\ManyToMany(targetEntity: TravelServicio::class, mappedBy: 'componentes')]
    private Collection $servicios;

    public function __construct()
    {
        $this->initializeId();
        $this->componenteItems = new ArrayCollection();
        $this->tarifas = new ArrayCollection();
        $this->servicios = new ArrayCollection();
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
    }

    /**
     * Retorna el nombre del componente para las interfaces (EasyAdmin/Selects).
     */
    public function __toString(): string
    {
        return $this->nombre ?? 'Componente sin nombre';
    }

    #[Groups(['componente:read', 'componente:item:read', 'servicio:item:read', 'segmento:item:read', 'cotizacion:read'])]
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
     */
    public function getTitulo(): array
    {
        return $this->titulo;
    }

    /**
     * Establece el título multilingüe visible para el cliente.
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
     */
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
}