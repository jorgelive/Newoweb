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
 * pero condicionado al contexto del tour (Servicio).
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_segmento_componente')]
class TravelSegmentoComponente
{
    use IdTrait;

    // 🚫 CORTE CIRCULAR: No serializamos el padre hacia arriba
    #[ORM\ManyToOne(targetEntity: TravelSegmento::class, inversedBy: 'segmentoComponentes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelSegmento $segmento = null;

    /**
     * 🔥 TRUCO API PLATFORM: readableLink false.
     * Solo necesitamos que Vue envíe y reciba el IRI del componente (/api/travel_componentes/UUID).
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelComponente::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?TravelComponente $componente = null;

    /**
     * El contexto de uso. Igual que el componente, solo IRI.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelServicio::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TravelServicio $servicioContexto = null;

    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $hora = null;

    // 🔥 Reemplazado de bool a Enum
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ComponenteItemModoEnum::class)]
    private ComponenteItemModoEnum $modo = ComponenteItemModoEnum::INCLUIDO;

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
        $horaFormateada = $this->hora ? sprintf(' [%s]', $this->hora->format('H:i')) : '';
        $contexto = $this->servicioContexto ? sprintf(' (Solo en: %s)', $this->servicioContexto->getNombreInterno()) : ' (Global)';

        // Etiqueta visual basada en el Enum
        $estadoInclusion = sprintf(' - [%s]', $this->modo->name);

        return $nombreComponente . $horaFormateada . $contexto . $estadoInclusion;
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

    public function getComponente(): ?TravelComponente
    {
        return $this->componente;
    }

    public function setComponente(?TravelComponente $componente): self
    {
        $this->componente = $componente;
        return $this;
    }

    public function getServicioContexto(): ?TravelServicio
    {
        return $this->servicioContexto;
    }

    public function setServicioContexto(?TravelServicio $servicioContexto): self
    {
        $this->servicioContexto = $servicioContexto;
        return $this;
    }

    public function getHora(): ?DateTimeImmutable
    {
        return $this->hora;
    }

    public function setHora(?DateTimeImmutable $hora): self
    {
        $this->hora = $hora;
        return $this;
    }

    public function getModo(): ComponenteItemModoEnum
    {
        return $this->modo;
    }

    public function setModo(ComponenteItemModoEnum $modo): self
    {
        $this->modo = $modo;
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