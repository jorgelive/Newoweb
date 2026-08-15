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
use App\Pms\Service\Agent\PmsProcedenciaComercial;
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

    /**
     * Los hitos que se RECALCULAN enteros desde los tramos en cada cambio.
     *
     * Todo lo demás que haya en la lista es un HECHO ocurrido —una cancelación parcial— que ya
     * no se puede deducir de los tramos, porque los que lo provocaron ya no están. `setHitos()`
     * reemplaza estos y conserva aquéllos.
     */
    private const array TIPOS_DERIVADOS = [
        ConversationMilestoneInterface::START,
        ConversationMilestoneInterface::END,
        ConversationMilestoneInterface::TEMPORARY_END,
        ConversationMilestoneInterface::REENTRY,
        ConversationMilestoneInterface::UNIT_CHANGE,
        ConversationMilestoneInterface::SERVICE,
    ];

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
    /**
     * ⚠️ **Acepta objeto de fecha, y por eso normaliza.**
     *
     * Quien llama a esto saca el valor de `PmsReservaMessageContext::getMilestones()`, que
     * devuelve `DateTimeInterface` — no cadenas, pese a lo que sugiere el nombre compartido con
     * el getter de esta entidad. Son dos mapas distintos: el del contexto es el **vivo**,
     * calculado, y el de aquí es el **persistido**, de texto plano.
     *
     * Con la firma en `?string` esto reventaba con un `TypeError` **antes de entrar al cuerpo**,
     * en toda reserva cancelada que trajera fecha de modificación de canal —o sea, en casi
     * todas—. El worker de Beds24 lo capturaba, así que no salía ningún 500: simplemente se
     * abandonaba la reserva a medio procesar y el hito nunca se grababa. Y sin el hito,
     * {@see \App\Message\Service\Queue\AgendaDeAsunto::estaCancelada()} no tiene qué leer, con lo
     * que el aviso de cancelación no podía existir — justo lo que este método venía a habilitar.
     * Visto en producción el 15/08/2026.
     *
     * Es la misma normalización que ya hacen {@see self::setMilestones()} y
     * {@see \App\Message\Entity\MessageConversation::addContextMilestone()}: **toda puerta que
     * escriba en este mapa tiene que aceptar las dos formas y guardar texto.**
     */
    public function marcarCancelado(string|\DateTimeInterface|null $cuando = null): self
    {
        $plano = $this->milestones ?? [];
        $plano[ConversationMilestoneInterface::CANCELLED] = match (true) {
            $cuando instanceof \DateTimeInterface => $cuando->format('Y-m-d H:i:s'),
            is_string($cuando) && '' !== trim($cuando) => $cuando,
            default => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        };
        $this->milestones = $plano;

        return $this;
    }

    /**
     * Anota que la reserva perdió algo pero sigue viva: una casita de dos, un tramo.
     *
     * Se guarda en la lista —donde sobrevive a los recálculos— **y** en el mapa plano, para que
     * una regla pueda colgarse de `partial_cancellation` sin esperar a que el motor sepa
     * programar por hito. En el mapa queda la ÚLTIMA, que es la que tiene sentido anunciar: si
     * pierde dos casitas en dos momentos, lo que importa es lo último que se le quitó.
     *
     * @param string $perdido Qué dejó de ser suyo, en palabras que se le pueden leer: «Casita 1».
     */
    public function anotarCancelacionParcial(string $perdido, ?DateTimeImmutable $cuando = null): self
    {
        $cuando ??= new DateTimeImmutable();

        $this->hitos = array_merge($this->hitos ?? [], [[
            'tipo' => ConversationMilestoneInterface::PARTIAL_CANCELLATION,
            'fecha' => $cuando->format('Y-m-d H:i:s'),
            'detalle' => $perdido,
            'detalleAnterior' => null,
        ]]);

        $this->proyectarEnMapaPlano();

        return $this;
    }

    /**
     * Las unidades que cubren los hitos DERIVADOS, para poder ver qué se perdió entre dos
     * recálculos.
     *
     * Sólo los derivados: si contara también las cancelaciones parciales ya anotadas, la casita
     * perdida volvería a aparecer como presente y el siguiente recálculo la daría por perdida
     * otra vez, anotando un duplicado en cada guardado.
     *
     * @return list<string>
     */
    public function unidadesDerivadas(): array
    {
        $unidades = [];

        foreach ($this->hitos ?? [] as $h) {
            if (!in_array($h['tipo'], self::TIPOS_DERIVADOS, true)) {
                continue;
            }

            foreach (explode(' + ', (string) ($h['detalle'] ?? '')) as $unidad) {
                $unidad = trim($unidad);

                if ($unidad !== '' && !in_array($unidad, $unidades, true)) {
                    $unidades[] = $unidad;
                }
            }
        }

        sort($unidades);

        return $unidades;
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

    /**
     * ⚠️ **Normaliza a texto, y no es cosmético.**
     *
     * `PmsReservaMessageContext::getMilestones()` devuelve objetos `DateTimeImmutable`, no
     * cadenas, pese a lo que dice este contrato. Guardados tal cual, el mapa queda con objetos
     * **en memoria** hasta que la entidad da la vuelta por JSON — y en esa ventana el motor de
     * reglas los lee y revienta: `parseMilestone(): Argument #1 ($raw) must be of type string,
     * DateTimeImmutable given`. Visto en producción el 13/08/2026: cuatro fallos en 96 segundos
     * sobre la misma reserva, y después el dato en la base se veía correcto, que es lo que hace
     * este fallo tan difícil de perseguir.
     *
     * El asunto que revienta se salta entero: esa reserva se queda **sin sus recordatorios**.
     *
     * @param array<string, string|\DateTimeInterface|array{date?: string}> $milestones
     */
    public function setMilestones(array $milestones): self
    {
        $normalizados = [];

        foreach ($milestones as $clave => $valor) {
            if ($valor instanceof \DateTimeInterface) {
                $normalizados[$clave] = $valor->format('Y-m-d H:i:s');

                continue;
            }

            // 🩹 La FORMA SERIALIZADA de un DateTimeImmutable: `{"date": "...", "timezone": ...}`.
            // Es lo que quedó grabado en las filas escritas antes de que esto normalizara, y no
            // se puede ignorar: al releerlas llegan como array y el motor de reglas se lleva por
            // delante el asunto entero. Se acepta y se aplana, para que reparar sea sólo volver a
            // guardar.
            if (is_array($valor)) {
                $fecha = trim((string) ($valor['date'] ?? ''));
                $normalizados[$clave] = $fecha === ''
                    ? ''
                    : (new DateTimeImmutable($fecha))->format('Y-m-d H:i:s');

                continue;
            }

            $normalizados[$clave] = (string) $valor;
        }

        // Una clave vacía es peor que no tenerla: el motor la trata como hito existente y
        // programa contra una fecha que no se puede interpretar.
        $normalizados = array_filter($normalizados, static fn (string $v): bool => $v !== '');

        $this->milestones = $normalizados;

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
        // Los hechos ocurridos sobreviven al recálculo; los derivados se reemplazan. Sin esto,
        // una cancelación parcial desaparecería en cuanto la reserva volviera a tocarse, y el
        // aviso que la anuncia se quedaría sin fecha a la que colgarse.
        $historicos = array_values(array_filter(
            $this->hitos ?? [],
            static fn (array $h): bool => !in_array($h['tipo'], self::TIPOS_DERIVADOS, true)
        ));

        $this->hitos = array_values(array_map(
            static fn (HitoDeAsunto $h): array => [
                'tipo' => $h->tipo,
                'fecha' => $h->fecha->format('Y-m-d H:i:s'),
                'detalle' => $h->detalle,
                'detalleAnterior' => $h->detalleAnterior,
            ],
            $hitos
        ));

        $this->hitos = array_merge($historicos, $this->hitos);

        $this->proyectarEnMapaPlano();

        return $this;
    }

    /**
     * El mapa plano es una PROYECCIÓN de la lista, no un almacén paralelo.
     *
     * ⚠️ Existe porque tenerlo como almacén ya falló dos veces. La primera, `start` se escribía
     * una sola vez en la vida del enlace y no se actualizaba nunca. La segunda, la cancelación
     * parcial aparecía en el mapa al anotarla y **desaparecía en el siguiente guardado**: el
     * sincronizador repone el mapa del contexto en cada recálculo, y como la pérdida ya no se
     * volvía a detectar, la clave se perdía y la regla que colgara de ella dejaba de tener fecha.
     *
     * Reproyectando desde la lista, el mapa no puede desincronizarse: se rehace entero desde la
     * única fuente que hay. Lo que no sale de aquí —`created_at`, `expected_arrival`— lo pone el
     * contexto y se respeta.
     */
    private function proyectarEnMapaPlano(): void
    {
        // Se borran las claves que proyecta la lista —y sólo ésas— para que una estancia que se
        // queda sin tramos vivos no conserve un `start` fantasma de cuando los tenía. Las ajenas
        // (`created_at`, `expected_arrival`) las pone el contexto y se respetan.
        $plano = $this->milestones ?? [];
        unset(
            $plano[ConversationMilestoneInterface::START],
            $plano[ConversationMilestoneInterface::END],
            $plano[ConversationMilestoneInterface::PARTIAL_CANCELLATION],
        );

        foreach ($this->hitos ?? [] as $h) {
            $tipo = (string) $h['tipo'];

            // El PRIMER `start` es la llegada. Los intermedios no caben en un mapa, y por eso
            // existe la lista.
            if ($tipo === ConversationMilestoneInterface::START && !isset($plano[$tipo])) {
                $plano[$tipo] = $h['fecha'];
            }

            // De la salida final y de la pérdida parcial manda la ÚLTIMA: si pierde dos casitas
            // en dos momentos, lo que hay que anunciar es lo último que se le quitó.
            if ($tipo === ConversationMilestoneInterface::END
                || $tipo === ConversationMilestoneInterface::PARTIAL_CANCELLATION
            ) {
                $plano[$tipo] = $h['fecha'];
            }
        }

        $this->milestones = $plano;
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
            procedencia: $this->procedenciaParaElPrompt(),
        );
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getReserva(): ?PmsReserva { return $this->reserva; }
    public function setReserva(?PmsReserva $reserva): self { $this->reserva = $reserva; return $this; }

    public function getOrigen(): ?string { return $this->origen; }
    public function setOrigen(?string $origen): self { $this->origen = $origen; return $this; }

    public function getAgencia(): ?string { return $this->agencia; }
    public function setAgencia(?string $agencia): self { $this->agencia = $agencia; return $this; }

    /**
     * Lo que el alojamiento necesita que el modelo sepa del canal por el que se vendió.
     *
     * Se escribe **en positivo para todas las ramas**: cada una dice algo cierto, y así no queda
     * nada que el modelo tenga que callarse. Con esto delante deja de tener que ir a buscar
     * `channel_name` con `consultar_mi_reserva` para decidir el tono de una respuesta de pagos —y
     * de arriesgarse a elegir rama a ciegas cuando no lo consulta—.
     *
     * La agencia manda sobre el origen cuando existe: una reserva que entró por una mayorista es
     * suya aunque el canal técnico diga otra cosa. Hoy está a NULL en todas, pero el orden de
     * precedencia queda escrito para cuando se pueble.
     */
    public function procedenciaParaElPrompt(): ?string
    {
        return PmsProcedenciaComercial::frase($this->origen, $this->agencia);
    }

    public function getStatusTag(): ?string { return $this->statusTag; }
    public function setStatusTag(?string $statusTag): self { $this->statusTag = $statusTag; return $this; }
}
