<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use App\Entity\Trait\IdTrait;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pivot Ternario: Vincula la logística (Componente) con la narrativa (Segmento),
 * pero condicionado al contexto del tour (Servicio).
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_segmento_componente')]
class TravelSegmentoComponente
{
    use IdTrait;

    #[ORM\ManyToOne(targetEntity: TravelSegmento::class, inversedBy: 'segmentoComponentes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelSegmento $segmento = null;

    #[ORM\ManyToOne(targetEntity: TravelComponente::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?TravelComponente $componente = null;

    /**
     * 🔥 NUEVO: El contexto de uso.
     * Si es nulo, esta regla logística aplica a TODOS los servicios que usen este segmento.
     * Si se define, la regla solo se dispara cuando el segmento se usa en ESTE servicio específico.
     */
    #[ORM\ManyToOne(targetEntity: TravelServicio::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TravelServicio $servicioContexto = null;

    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $hora = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $esIncluido = true;

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
        $estadoInclusion = $this->esIncluido ? '' : ' - No Incluido';

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

    public function isEsIncluido(): bool
    {
        return $this->esIncluido;
    }

    public function setEsIncluido(bool $esIncluido): self
    {
        $this->esIncluido = $esIncluido;
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