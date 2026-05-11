<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Trait\IdTrait;
use App\Travel\Enum\ComponenteItemModoEnum;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Pivot Ternario: Vincula la logística (Componente) con la narrativa (Segmento),
 * pero condicionado de forma inteligente al contexto del Itinerario.
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_segmento_componente')]
#[ORM\HasLifecycleCallbacks]
class TravelSegmentoComponente
{
    use IdTrait;

    // 🚫 CORTE CIRCULAR: No serializamos el padre hacia arriba
    #[ORM\ManyToOne(targetEntity: TravelSegmento::class, inversedBy: 'segmentoComponentes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelSegmento $segmento = null;

    /**
     * El componente logístico que será inyectado.
     * readableLink false para que Vue solo necesite enviar/recibir el IRI.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelComponente::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?TravelComponente $componente = null;

    /**
     * El Cerebro del Timeline: Define en qué plantilla específica de itinerario
     * debe inyectarse este componente. Si es null, se inyecta siempre.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelItinerario::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TravelItinerario $itinerarioContexto = null;

    /**
     * Hora exacta a la que inicia la operativa de este componente en el itinerario.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $hora = null;

    /**
     * Hora exacta a la que finaliza la operativa.
     * Si está vacía, el frontend usará la duración por defecto del componente.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $horaFin = null;

    /**
     * Define si el componente suma al costo, no incluye, o es opcional.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ComponenteItemModoEnum::class)]
    private ComponenteItemModoEnum $modo = ComponenteItemModoEnum::INCLUIDO;

    /**
     * Orden en el que se lista dentro del segmento.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'integer')]
    private int $orden = 1;

    public function __construct()
    {
        $this->initializeId();
    }

    public function __toString(): string
    {
        $nombreComponente = $this->componente ? (string) $this->componente : 'Nuevo vínculo';
        $horaFormateada = $this->hora ? sprintf(' [%s', $this->hora->format('H:i')) : '';
        $horaFinFormateada = $this->horaFin ? sprintf(' - %s]', $this->horaFin->format('H:i')) : ($this->hora ? ']' : '');
        $contexto = $this->itinerarioContexto ? sprintf(' (Plantilla: %s)', $this->itinerarioContexto->getNombreInterno()) : ' (Global)';
        $estadoInclusion = sprintf(' - [%s]', $this->modo->name);

        return $nombreComponente . $horaFormateada . $horaFinFormateada . $contexto . $estadoInclusion;
    }

    /**
     * Obtiene el segmento narrativo padre.
     */
    public function getSegmento(): ?TravelSegmento
    {
        return $this->segmento;
    }

    /**
     * Establece el segmento narrativo padre.
     */
    public function setSegmento(?TravelSegmento $segmento): self
    {
        $this->segmento = $segmento;
        return $this;
    }

    /**
     * Obtiene el componente logístico vinculado.
     */
    public function getComponente(): ?TravelComponente
    {
        return $this->componente;
    }

    /**
     * Establece el componente logístico a inyectar.
     */
    public function setComponente(?TravelComponente $componente): self
    {
        $this->componente = $componente;
        return $this;
    }

    /**
     * Obtiene la plantilla de itinerario a la que está condicionada esta logística.
     */
    public function getItinerarioContexto(): ?TravelItinerario
    {
        return $this->itinerarioContexto;
    }

    /**
     * Establece la plantilla de itinerario para filtrar su inyección.
     */
    public function setItinerarioContexto(?TravelItinerario $itinerarioContexto): self
    {
        $this->itinerarioContexto = $itinerarioContexto;
        return $this;
    }

    /**
     * Obtiene la hora de inicio de la operativa.
     */
    public function getHora(): ?DateTimeImmutable
    {
        return $this->hora;
    }

    /**
     * Establece la hora de inicio de la operativa.
     */
    public function setHora(?DateTimeImmutable $hora): self
    {
        $this->hora = $hora;
        return $this;
    }

    /**
     * Obtiene la hora de fin de la operativa.
     */
    public function getHoraFin(): ?DateTimeImmutable
    {
        return $this->horaFin;
    }

    /**
     * Establece la hora de fin de la operativa.
     */
    public function setHoraFin(?DateTimeImmutable $horaFin): self
    {
        $this->horaFin = $horaFin;
        return $this;
    }

    /**
     * Obtiene el modo comercial en el que se inyecta.
     */
    public function getModo(): ComponenteItemModoEnum
    {
        return $this->modo;
    }

    /**
     * Establece el modo comercial (ej. INCLUIDO, OPCIONAL).
     */
    public function setModo(ComponenteItemModoEnum $modo): self
    {
        $this->modo = $modo;
        return $this;
    }

    /**
     * Obtiene el orden de aparición.
     */
    public function getOrden(): int
    {
        return $this->orden;
    }

    /**
     * Establece el orden de aparición.
     */
    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }
}