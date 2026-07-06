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
 * condicionado de forma inteligente al contexto del Itinerario y al día de ejecución.
 * * Razón de existencia: Esta entidad permite desacoplar los componentes del catálogo base
 * e inyectarles reglas de negocio dinámicas (horarios, modos comerciales y filtros por día)
 * dentro del storytelling de una cotización o plantilla.
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_segmento_componente')]
#[ORM\HasLifecycleCallbacks]
class TravelSegmentoComponente
{
    use IdTrait;

    /**
     * @var TravelSegmento|null El segmento narrativo padre al que pertenece esta configuración logístico-temporal.
     */
    #[ORM\ManyToOne(targetEntity: TravelSegmento::class, inversedBy: 'segmentoComponentes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelSegmento $segmento = null;

    /**
     * @var TravelComponente|null El componente logístico del catálogo maestro que será inyectado en el timeline.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelComponente::class, inversedBy: 'segmentoComponentesInyectados')]
    #[ORM\JoinColumn(nullable: false)]
    private ?TravelComponente $componente = null;

    /**
     * @var TravelTarifa|null Tarifa específica del catálogo que se predefinirá al instanciar este componente.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelTarifa::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TravelTarifa $tarifaPredeterminada = null;

    /**
     * El Cerebro del Timeline: Define en qué plantilla específica de itinerario
     * debe inyectarse este componente. Si es null, se considera global y se inyecta siempre.
     * * @var TravelItinerario|null
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelItinerario::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TravelItinerario $itinerarioContexto = null;

    /**
     * Filtro opcional de refinamiento: Determina el día relativo exacto de la plantilla
     * en el que se aplicará este componente logístico.
     * * Si es null, el componente se inyectará de forma global en cualquier día que se use el segmento.
     * Si contiene un entero (ej: 2), actuará como discriminador estricto en el generador de itinerarios.
     * * @var int|null
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dia = null;

    /**
     * @var DateTimeImmutable|null Hora exacta a la que inicia la operativa de este componente en el itinerario.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $hora = null;

    /**
     * @var DateTimeImmutable|null Hora exacta a la que finaliza la operativa. Si es nula, se calcula con la duración del maestro.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $horaFin = null;

    /**
     * @var ComponenteItemModoEnum Define la modalidad comercial del componente (INCLUIDO, OPCIONAL, NO_INCLUIDO).
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ComponenteItemModoEnum::class)]
    private ComponenteItemModoEnum $modo = ComponenteItemModoEnum::INCLUIDO;

    /**
     * @var int Orden posicional en el que se listará el componente dentro del contenedor del segmento.
     */
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'integer')]
    private int $orden = 1;

    public function __construct()
    {
        $this->initializeId();
    }

    /**
     * 🔥 CLONACIÓN PROFUNDA
     * Limpia la identidad para permitir su persistencia como un nuevo registro
     * vinculado al segmento clonado.
     */
    public function __clone()
    {
        $this->resetId();
        // Desvinculamos del padre original. El addSegmentoComponente()
        // de la entidad TravelSegmento volverá a establecer esta relación con el nuevo clon.
        $this->segmento = null;
    }

    public function __toString(): string
    {
        $nombreComponente = $this->componente ? (string) $this->componente : 'Nuevo vínculo';
        $diaFormateada = $this->dia ? sprintf(' [Día %d]', $this->dia) : ' [Día Global]';
        $horaFormateada = $this->hora ? sprintf(' (%s', $this->hora->format('H:i')) : '';
        $horaFinFormateada = $this->horaFin ? sprintf(' - %s)', $this->horaFin->format('H:i')) : ($this->hora ? ')' : '');
        $contexto = $this->itinerarioContexto ? sprintf(' (Plantilla: %s)', $this->itinerarioContexto->getNombreInterno()) : ' (Global)';
        $estadoInclusion = sprintf(' - [%s]', $this->modo->name);

        return $nombreComponente . $diaFormateada . $horaFormateada . $horaFinFormateada . $contexto . $estadoInclusion;
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

    public function getTarifaPredeterminada(): ?TravelTarifa
    {
        return $this->tarifaPredeterminada;
    }

    public function setTarifaPredeterminada(?TravelTarifa $tarifaPredeterminada): self
    {
        $this->tarifaPredeterminada = $tarifaPredeterminada;
        return $this;
    }

    public function getItinerarioContexto(): ?TravelItinerario
    {
        return $this->itinerarioContexto;
    }

    public function setItinerarioContexto(?TravelItinerario $itinerarioContexto): self
    {
        $this->itinerarioContexto = $itinerarioContexto;
        return $this;
    }

    public function getDia(): ?int
    {
        return $this->dia;
    }

    public function setDia(?int $dia): self
    {
        $this->dia = $dia;
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

    public function getHoraFin(): ?DateTimeImmutable
    {
        return $this->horaFin;
    }

    public function setHoraFin(?DateTimeImmutable $horaFin): self
    {
        $this->horaFin = $horaFin;
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