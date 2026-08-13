<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\ConversationMilestoneInterface;
use App\Message\Contract\Frente;
use App\Message\Contract\HitoDeAsunto;
use App\Message\Contract\MomentoDeFrente;
use App\Message\Contract\VinculoComercial;
use App\Message\Entity\MessageConversation;
use App\Pms\Service\Agent\PmsFrentes;
use DateTimeImmutable;
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

    /**
     * Los hitos DERIVADOS de los tramos: llegada, salidas temporales, reingresos, cambios de
     * casita, salida final. Cada uno con su fecha y su detalle.
     *
     * ── Por qué convive con `$milestones` y no lo sustituye ─────────────────
     * `$milestones` es el mapa plano de siempre —`start`, `end`, `created`— que es lo que el
     * motor de reglas lee hoy y con lo que están escritas las reglas en producción. Esto es la
     * lista completa, y **admite varias ocurrencias del mismo tipo**: dos escapadas en un viaje
     * largo son dos salidas temporales, y en un mapa `clave => fecha` sólo cabe una.
     *
     * Se guarda derivado y no calculado al vuelo porque el motor barre cientos de asuntos por
     * pasada y recorrer los tramos de cada reserva en cada barrido sale caro. Lo mantiene al día
     * {@see \App\Pms\Service\Message\PmsSincronizadorDeEnlace}, que corre en cada cambio de la
     * reserva — incluido el borrado de un evento, que puede hacer desaparecer un hueco.
     *
     * @var list<array{tipo: string, fecha: string, detalle: ?string, detalleAnterior: ?string}>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $hitos = [];

    /**
     * Deja constancia de que este asunto se canceló, con su fecha.
     *
     * ── Se marca, no se borra ───────────────────────────────────────────────
     * Un asunto cancelado sigue siendo parte de lo que le pasó a esa persona, y borrarlo deja el
     * hilo contando una versión incompleta. Además es lo único que permite que el aviso de
     * cancelación pueda existir: {@see \App\Message\Service\Queue\AgendaDeAsunto::estaCancelada()}
     * lee este hito, y sin enlace no hay hito que leer.
     *
     * ⚠️ Marcarlo **no** hace que se envíe nada: el motor sigue tratando el asunto como muerto y
     * no encola ni un mensaje. Lo que cambia es que el dato existe, y con él se puede decidir
     * después —con la guarda que haga falta— si el aviso vuelve. Sin el dato, esa decisión ni
     * siquiera se podía plantear.
     */
    public function marcarCancelado(?string $cuando = null): self
    {
        $plano = $this->milestones ?? [];
        $plano[ConversationMilestoneInterface::CANCELLED] = $cuando ?: (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->milestones = $plano;

        return $this;
    }

    /** ¿Este asunto está cancelado? */
    public function estaCancelado(): bool
    {
        return ($this->milestones[ConversationMilestoneInterface::CANCELLED] ?? '') !== '';
    }

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

    /**
     * Los hitos completos, reconstruidos desde el JSON.
     *
     * @return list<HitoDeAsunto>
     */
    public function getHitos(): array
    {
        return array_values(array_map(
            static fn (array $h): HitoDeAsunto => new HitoDeAsunto(
                $h['tipo'],
                new DateTimeImmutable($h['fecha']),
                $h['detalle'] ?? null,
                $h['detalleAnterior'] ?? null,
            ),
            $this->hitos ?? []
        ));
    }

    /**
     * Guarda los hitos y, de paso, **mantiene `$milestones` en sintonía**.
     *
     * Los dos salen de la misma derivación a propósito: si el mapa plano se siguiera copiando
     * del `contextData` de la conversación mientras la lista se deriva de los tramos, tendríamos
     * dos verdades sobre las mismas fechas y acabarían discrepando — que es justo el fallo que
     * este trabajo persigue. El mapa se queda con el primer `start` y el último `end`, que es lo
     * que las reglas de hoy esperan.
     *
     * @param list<HitoDeAsunto> $hitos
     */
    public function setHitos(array $hitos): self
    {
        $this->hitos = array_values(array_map(
            static fn (HitoDeAsunto $h): array => [
                'tipo' => $h->tipo,
                'fecha' => $h->fecha->format('Y-m-d H:i:s'),
                'detalle' => $h->detalle,
                'detalleAnterior' => $h->detalleAnterior,
            ],
            $hitos
        ));

        // ⚠️ Se RECONSTRUYEN las dos claves que le pertenecen a esta derivación, no se fusionan.
        //
        // Fusionar con `!isset` parecía prudente y era el bug: `start` sólo se escribía si el
        // mapa no lo tenía ya, o sea **una vez en la vida del enlace**. Mover la llegada del 10
        // al 12 dejaba la lista diciendo 12 y el mapa diciendo 10 — y como el motor programa con
        // el mapa, el check-in salía calculado contra la fecha vieja. Justo el síntoma que este
        // trabajo venía a matar, reintroducido por una guarda de más.
        //
        // Se borran antes del bucle para que una estancia que se queda sin tramos vivos no
        // conserve un `start` fantasma de cuando los tenía. Las claves ajenas a esta derivación
        // —`created_at`, `expected_arrival`— se respetan: las pone quien sabe de ellas.
        $plano = $this->milestones ?? [];
        unset($plano[ConversationMilestoneInterface::START], $plano[ConversationMilestoneInterface::END]);

        foreach ($hitos as $hito) {
            // El PRIMER `start` de esta derivación es la llegada; el ÚLTIMO `end`, la salida
            // final. Los intermedios no caben en un mapa, y por eso existe la lista.
            if ($hito->tipo === ConversationMilestoneInterface::START && !isset($plano[ConversationMilestoneInterface::START])) {
                $plano[ConversationMilestoneInterface::START] = $hito->fecha->format('Y-m-d H:i:s');
            }

            if ($hito->tipo === ConversationMilestoneInterface::END) {
                $plano[ConversationMilestoneInterface::END] = $hito->fecha->format('Y-m-d H:i:s');
            }
        }

        $this->milestones = $plano;

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
