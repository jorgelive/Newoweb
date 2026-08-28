<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Trait\IdTrait;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Pivot Ternario: Vincula la logística (Componente) con la narrativa (Segmento),
 * condicionado de forma inteligente al contexto del Itinerario y al día de ejecución.
 *
 * Razón de existencia: Esta entidad permite desacoplar los componentes del catálogo base
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
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelComponente::class, inversedBy: 'segmentoComponentesInyectados')]
    #[ORM\JoinColumn(nullable: false)]
    private ?TravelComponente $componente = null;

    /**
     * @var TravelTarifa|null Tarifa específica del catálogo que se predefinirá al instanciar este componente.
     */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelTarifa::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TravelTarifa $tarifaPredeterminada = null;

    /**
     * El Cerebro del Timeline: Define en qué plantilla específica de itinerario
     * debe inyectarse este componente. Si es null, se considera global y se inyecta siempre.
     *
     * @var TravelItinerario|null
     */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelItinerario::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TravelItinerario $itinerarioContexto = null;

    /**
     * Filtro opcional de refinamiento: Determina el día relativo exacto de la plantilla
     * en el que se aplicará este componente logístico.
     *
     * Si es null, el componente se inyectará de forma global en cualquier día que se use el segmento.
     * Si contiene un entero (ej: 2), actuará como discriminador estricto en el generador de itinerarios.
     *
     * @var int|null
     */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dia = null;

    /**
     * @var DateTimeImmutable|null Hora exacta a la que inicia la operativa de este componente en el itinerario.
     */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $hora = null;

    /**
     * @var DateTimeImmutable|null Hora exacta a la que finaliza la operativa. Si es nula, se calcula con la duración del maestro.
     */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $horaFin = null;

    /**
     * @var ComponenteModoEnum Define la modalidad comercial del componente (INCLUIDO, NO_INCLUIDO, CORTESIA, REEMPLAZADO).
     */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ComponenteModoEnum::class)]
    private ComponenteModoEnum $modo = ComponenteModoEnum::INCLUIDO;

    /**
     * @var int Orden posicional en el que se listará el componente dentro del contenedor del segmento.
     */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'integer')]
    private int $orden = 1;

    /**
     * Promueve la hora de este componente al nivel de "servicio completo": su
     * horario (hora / horaFin) representa el span de TODA la excursión (servicio /
     * itinerario), no sólo el del segmento al que está vinculado. Se usa cuando
     * la logística del tour se ancla en un único segmento (ej. el recojo) pero su
     * horario aplica a la experiencia entera. Debe haber a lo sumo UNO promovido
     * por itinerarioContexto (se garantiza al guardar) para no mostrar horarios
     * globales en conflicto.
     *
     * @var bool
     */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $horaServicioCompleto = false;

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

    /**
     * Retorna una representación en texto del vínculo logístico.
     * * @return string
     */
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

    /**
     * @return Uuid|null
     */
    #[Groups(['segmento:read', 'segmento:item:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /**
     * @return TravelSegmento|null
     */
    public function getSegmento(): ?TravelSegmento
    {
        return $this->segmento;
    }

    /**
     * @param TravelSegmento|null $segmento
     * @return self
     */
    public function setSegmento(?TravelSegmento $segmento): self
    {
        $this->segmento = $segmento;
        return $this;
    }

    /**
     * @return TravelComponente|null
     */
    public function getComponente(): ?TravelComponente
    {
        return $this->componente;
    }

    /**
     * @param TravelComponente|null $componente
     * @return self
     */
    public function setComponente(?TravelComponente $componente): self
    {
        $this->componente = $componente;
        return $this;
    }

    /**
     * @return TravelTarifa|null
     */
    public function getTarifaPredeterminada(): ?TravelTarifa
    {
        return $this->tarifaPredeterminada;
    }

    /**
     * @param TravelTarifa|null $tarifaPredeterminada
     * @return self
     */
    public function setTarifaPredeterminada(?TravelTarifa $tarifaPredeterminada): self
    {
        $this->tarifaPredeterminada = $tarifaPredeterminada;
        return $this;
    }

    /**
     * @return TravelItinerario|null
     */
    public function getItinerarioContexto(): ?TravelItinerario
    {
        return $this->itinerarioContexto;
    }

    /**
     * @param TravelItinerario|null $itinerarioContexto
     * @return self
     */
    public function setItinerarioContexto(?TravelItinerario $itinerarioContexto): self
    {
        $this->itinerarioContexto = $itinerarioContexto;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getDia(): ?int
    {
        return $this->dia;
    }

    /**
     * @param int|null $dia
     * @return self
     */
    public function setDia(?int $dia): self
    {
        $this->dia = $dia;
        return $this;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getHora(): ?DateTimeImmutable
    {
        return $this->hora;
    }

    /**
     * @param DateTimeImmutable|null $hora
     * @return self
     */
    public function setHora(?DateTimeImmutable $hora): self
    {
        $this->hora = $hora;
        return $this;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getHoraFin(): ?DateTimeImmutable
    {
        return $this->horaFin;
    }

    /**
     * @param DateTimeImmutable|null $horaFin
     * @return self
     */
    public function setHoraFin(?DateTimeImmutable $horaFin): self
    {
        $this->horaFin = $horaFin;
        return $this;
    }

    /**
     * @return ComponenteModoEnum
     */
    public function getModo(): ComponenteModoEnum
    {
        return $this->modo;
    }

    /**
     * @param ComponenteModoEnum $modo
     * @return self
     */
    public function setModo(ComponenteModoEnum $modo): self
    {
        $this->modo = $modo;
        return $this;
    }

    /**
     * @return int
     */
    public function getOrden(): int
    {
        return $this->orden;
    }

    /**
     * @param int $orden
     * @return self
     */
    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }

    public function isHoraServicioCompleto(): bool
    {
        return $this->horaServicioCompleto;
    }

    public function setHoraServicioCompleto(bool $horaServicioCompleto): self
    {
        $this->horaServicioCompleto = $horaServicioCompleto;
        return $this;
    }

    /**
     * La promoción "hora de servicio completo" debe estar atada a una plantilla
     * (itinerarioContexto). Una promoción global aplicaría a todos los tours que
     * usen el segmento y chocaría con las horas específicas de cada plantilla —
     * y el horario de una excursión es propio de cada tour. Se valida en toda
     * vía basada en el validador (CRUD suelto, CRUD anidado y API).
     */
    /**
     * Un ALOJAMIENTO no lleva hora, y no es una preferencia de estilo: la rompe.
     *
     * En la guía del pasajero, `esEstadia` se deduce de **no tener horas** y terminar en fecha
     * posterior (`itinerarioVista` en `PaxCotizacionGuiaView.vue`). Ponerle hora hace dos cosas
     * a la vez, las dos malas y las dos mudas:
     *
     *   1. deja de repetirse cada noche —se pinta sólo el primer día, y las demás noches
     *      desaparecen del itinerario—;
     *   2. baja a tier 0 y se coloca a esa hora, en mitad de la tarde, en vez de cerrar el día.
     *
     * Un hotel de tres noches se ve como una actividad suelta de las 15:00. Sin error.
     *
     * ⚠️ **La hora de llegada al hotel SÍ existe, pero es otro campo**: `OperacionServicio::
     * $horaRecojo`, que fija el operador desde La Biblia, es editable y **no se snapshotea**.
     * Ésa es la que sirve para avisar al hotel; la del catálogo viaja a la propuesta del cliente
     * y por eso no puede llevarla.
     *
     * Ver `docs/Cotizaciones.md` §6.u.
     */
    #[Assert\Callback]
    public function validarAlojamientoSinHora(ExecutionContextInterface $context): void
    {
        if ($this->hora === null || $this->componente?->getTipo() !== ComponenteTipoEnum::ALOJAMIENTO) {
            return;
        }

        $context->buildViolation(
            'Un alojamiento no puede llevar hora: la guía del huésped deduce de ahí que es una '
            . 'estadía, y con hora dejaría de repetirse cada noche. La hora de llegada al hotel '
            . 'se pone en «Hora de recojo» desde La Biblia.',
        )
            ->atPath('hora')
            ->addViolation();
    }

    #[Assert\Callback]
    public function validarPromocionRequierePlantilla(ExecutionContextInterface $context): void
    {
        if ($this->horaServicioCompleto && $this->itinerarioContexto === null) {
            $context->buildViolation('La "Hora de servicio completo" requiere una plantilla en "Condicionado a Plantilla": una hora global aplicaría a todos los tours y generaría horarios en conflicto.')
                ->atPath('horaServicioCompleto')
                ->addViolation();
        }
    }
}