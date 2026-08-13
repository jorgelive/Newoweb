<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\Frente;
use App\Message\Contract\MomentoDeFrente;
use App\Message\Contract\VinculoComercial;
use App\Message\Entity\MessageConversation;
use App\Pms\Service\Agent\PmsFrentes;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * La reserva de la que se habla en una conversación.
 *
 * ── Uno a muchos, y ése es el punto ─────────────────────────────────────────
 * Una conversación es de una PERSONA y puede tener varias reservas colgando: el titular que
 * reserva para terceros, el huésped que vuelve, el que está alojado y además cotiza una
 * ampliación. Hoy eso obliga a crear una conversación por reserva —hay un número con 7 creadas
 * el mismo día— y parte el historial en trozos que el agente no ve juntos.
 *
 * ── Qué se muda aquí, y de dónde ────────────────────────────────────────────
 * Lo que hoy vive aplastado en el JSON `contextData` de la conversación y **es del activo**:
 *
 * ```
 * msg_conversation.context_data['vinculo']     → $vinculo
 * msg_conversation.context_data['milestones']  → $milestones
 * msg_conversation.context_data['origin']      → $origen
 * msg_conversation.context_data['agency']      → $agencia
 * msg_conversation.context_data['status_tag']  → $statusTag
 * msg_conversation.context_type / context_id   → la FK de aquí abajo
 * ```
 *
 * Los `financials` e `items` de ese JSON NO se mudan: son una foto de la cuenta que el motor de
 * plantillas usa para redactar, y su sitio natural es el activo mismo
 * ({@see PmsInformacionFinanciera}), no una copia más. Se decidirá al conectar el motor.
 *
 * ── Es un ESPEJO, no la verdad ──────────────────────────────────────────────
 * ⚠️ La verdad de la reserva está en `PmsReserva`. Esto es lo que la mensajería necesitaba
 * tener a mano y ya guardaba —con los mismos riesgos de siempre: si la reserva cambia y nadie
 * refresca el enlace, aquí queda la foto vieja—. No es peor que hoy, que es exactamente el
 * mismo espejo pero dentro de un JSON sin forma; sí es más fácil de refrescar, porque ahora
 * tiene FK a la reserva y se puede recalcular sin adivinar de cuál venía.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_conversacion_enlace')]
#[ORM\UniqueConstraint(name: 'uniq_enlace_conversacion_reserva', columns: ['conversacion_id', 'reserva_id'])]
#[ORM\Index(columns: ['reserva_id'], name: 'idx_enlace_reserva')]
#[ORM\HasLifecycleCallbacks]
class PmsConversacionEnlace implements ConversacionEnlaceInterface
{
    use IdTrait;
    use TimestampTrait;

    public const string CONTEXT_TYPE = 'pms_reserva';

    #[ORM\ManyToOne(targetEntity: MessageConversation::class, inversedBy: 'enlacesPms')]
    #[ORM\JoinColumn(name: 'conversacion_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?MessageConversation $conversacion = null;

    /**
     * Sin `onDelete: CASCADE` a propósito: borrar una reserva no puede llevarse por delante el
     * hilo de mensajes con esa persona. Quien borre una reserva tiene que decidir qué hace con
     * su enlace, y que le falle el borrado es preferible a perder la conversación en silencio.
     */
    #[ORM\ManyToOne(targetEntity: PmsReserva::class)]
    #[ORM\JoinColumn(name: 'reserva_id', referencedColumnName: 'id', nullable: false)]
    private ?PmsReserva $reserva = null;

    #[ORM\Column(type: 'string', length: 30, enumType: VinculoComercial::class, options: ['default' => 'ninguno'])]
    private VinculoComercial $vinculo = VinculoComercial::Ninguno;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $milestones = [];

    /** `directo`, `airbnb`, `booking`… De qué canal vino este asunto. */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $origen = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $agencia = null;

    /** La etiqueta cruda del canal (`confirmed`, `inquiry`…), tal como llegó. */
    #[ORM\Column(name: 'status_tag', type: 'string', length: 50, nullable: true)]
    private ?string $statusTag = null;

    public function __construct(?MessageConversation $conversacion = null, ?PmsReserva $reserva = null)
    {
        $this->initializeId();
        $this->conversacion = $conversacion;
        $this->reserva = $reserva;
    }

    public function getConversacion(): ?MessageConversation
    {
        return $this->conversacion;
    }

    public function setConversacion(?MessageConversation $conversacion): self
    {
        $this->conversacion = $conversacion;

        return $this;
    }

    public function getNegocio(): string
    {
        return PmsFrentes::NEGOCIO;
    }

    public function getContextType(): string
    {
        return self::CONTEXT_TYPE;
    }

    public function getContextId(): string
    {
        return (string) $this->reserva?->getId();
    }

    public function getVinculo(): VinculoComercial
    {
        return $this->vinculo;
    }

    public function setVinculo(VinculoComercial $vinculo): self
    {
        $this->vinculo = $vinculo;

        return $this;
    }

    /**
     * Vendiéndose mientras no haya cliente; en operación en cuanto lo hay.
     *
     * Se deriva del vínculo y no se guarda: son el mismo hecho contado dos veces, y dos columnas
     * para un hecho es una invitación a que se contradigan.
     */
    public function getMomento(): MomentoDeFrente
    {
        return $this->vinculo === VinculoComercial::Cliente
            ? MomentoDeFrente::Operacion
            : MomentoDeFrente::Venta;
    }

    /** @return array<string, string> */
    public function getMilestones(): array
    {
        return $this->milestones ?? [];
    }

    /** @param array<string, string> $milestones */
    public function setMilestones(array $milestones): self
    {
        $this->milestones = $milestones;

        return $this;
    }

    public function getEtiqueta(): string
    {
        $unidades = [];
        $llegada = null;
        $salida = null;

        foreach ($this->reserva?->getEventosCalendario() ?? [] as $evento) {
            // Mismo criterio que `PmsFrentes::etiquetaDe()`: sólo los tramos que cuentan, nunca
            // `getFechaLlegada()`/`getFechaSalida()`, que son agregados y con un tramo cancelado
            // pintan una ventana que no corresponde a ninguna estancia real.
            if ($evento->getEventoOrigen() !== null
                || !in_array($evento->getEstado()?->getId(), PmsEventoEstado::IDENTIFICAN_HUESPED, true)
            ) {
                continue;
            }

            $nombre = $evento->getPmsUnidad()?->getNombre();

            if ($nombre !== null && !in_array($nombre, $unidades, true)) {
                $unidades[] = $nombre;
            }

            $inicio = $evento->getInicio();
            $fin = $evento->getFin();

            if ($inicio !== null && ($llegada === null || $inicio < $llegada)) {
                $llegada = $inicio;
            }

            if ($fin !== null && ($salida === null || $fin > $salida)) {
                $salida = $fin;
            }
        }

        $partes = [];

        if ($unidades !== []) {
            $partes[] = implode(' + ', $unidades);
        }

        if ($llegada !== null && $salida !== null) {
            $partes[] = sprintf('%s–%s', $llegada->format('d/m'), $salida->format('d/m'));
        }

        return $partes === []
            ? 'Tu reserva de alojamiento'
            : sprintf('Tu reserva %s', implode(', ', $partes));
    }

    public function comoFrente(): Frente
    {
        return new Frente(
            negocio: $this->getNegocio(),
            momento: $this->getMomento(),
            etiqueta: $this->getEtiqueta(),
            entidadTipo: self::CONTEXT_TYPE,
            entidadId: $this->getContextId(),
        );
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getReserva(): ?PmsReserva { return $this->reserva; }
    public function setReserva(?PmsReserva $reserva): self { $this->reserva = $reserva; return $this; }

    public function getOrigen(): ?string { return $this->origen; }
    public function setOrigen(?string $origen): self { $this->origen = $origen; return $this; }

    public function getAgencia(): ?string { return $this->agencia; }
    public function setAgencia(?string $agencia): self { $this->agencia = $agencia; return $this; }

    public function getStatusTag(): ?string { return $this->statusTag; }
    public function setStatusTag(?string $statusTag): self { $this->statusTag = $statusTag; return $this; }
}
