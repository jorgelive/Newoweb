<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad base para la logística pura (El insumo financiero).
 * Representa costos crudos (Ej: Ticket de Tren, Guía, Almuerzo).
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_componente')]
class TravelComponente
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombre = null;

    // 🔥 NUEVO: El título público que verá el huésped
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[ORM\Column(type: 'string', length: 50, enumType: ComponenteTipoEnum::class)]
    private ComponenteTipoEnum $tipo = ComponenteTipoEnum::EXTRAS;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 1, nullable: true)]
    private ?string $duracion = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $anticipacionalerta = null;

    /**
     * Los ítems internos (Descriptivos o Upsells) que componen este servicio logístico.
     */
    #[ORM\OneToMany(mappedBy: 'componente', targetEntity: TravelComponenteItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $componenteItems;

    /**
     * El listado de tarifas configuradas para este componente.
     */
    #[ORM\OneToMany(mappedBy: 'componente', targetEntity: TravelTarifa::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tarifas;

    /**
     * Servicios (Tours/Paquetes) que incluyen este componente en su "Bolsa" (Pool Logístico).
     * Esta es la relación ManyToMany limpia (Reemplaza al antiguo pivot de fecha/hora).
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
     * Retorna el nombre del componente para las interfaces (EasyAdmin/Selects).
     */
    public function __toString(): string
    {
        return $this->nombre ?? 'Componente sin nombre';
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