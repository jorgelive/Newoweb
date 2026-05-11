<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Trait\IdTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Pivot que ordena la narrativa dentro de la plantilla del itinerario.
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_itinerario_segmento_rel')]
#[ORM\HasLifecycleCallbacks]
class TravelItinerarioSegmentoRel
{
    use IdTrait;

    // 🚫 CORTE CIRCULAR
    #[ORM\ManyToOne(targetEntity: TravelItinerario::class, inversedBy: 'itinerarioSegmentos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelItinerario $itinerario = null;

    /**
     * 🔥 TRUCO API PLATFORM: readableLink false.
     * Recibimos el IRI del segmento de catálogo que queremos inyectar en este día.
     */
    #[Groups(['itinerario:item:read', 'itinerario:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelSegmento::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?TravelSegmento $segmento = null;

    #[Groups(['itinerario:item:read', 'itinerario:write'])]
    #[ORM\Column(type: 'integer')]
    private int $dia = 1;

    #[Groups(['itinerario:item:read', 'itinerario:write'])]
    #[ORM\Column(type: 'integer')]
    private int $orden = 1;

    public function __construct()
    {
        $this->initializeId();
    }

    public function __toString(): string
    {
        // Devuelve el nombre interno del segmento, o un fallback si aún no está asignado
        if ($this->segmento instanceof TravelSegmento) {
            return sprintf('Día %d - %s', $this->dia, $this->segmento->getNombreInterno() ?? 'Sin nombre');
        }

        return 'Nuevo Segmento de Itinerario';
    }

    public function getItinerario(): ?TravelItinerario
    {
        return $this->itinerario;
    }

    public function setItinerario(?TravelItinerario $itinerario): self
    {
        $this->itinerario = $itinerario;
        return $this;
    }

    public function getSegmento(): ?TravelSegmento
    {
        return $this->segmento;
    }

    public function setSegmento(?TravelSegmento $segmento): self
    {
        $this->segmento = $segmento;
        return $this;
    }

    public function getDia(): int
    {
        return $this->dia;
    }

    public function setDia(int $dia): self
    {
        $this->dia = $dia;
        return $this;
    }

    public function getOrden(): int
    {
        return $this->orden;
    }

    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }
}