<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Queue;

use App\Message\Contract\ChannelEnqueuerInterface;
use App\Contract\ConversationMilestoneInterface;
use App\Contract\MapaDeHitos;
use App\Contract\MomentoDeHito;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Entity\MessageRule;
use App\Contract\VinculoComercial;
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Message\Service\Queue\MessageRuleEngine;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * El motor de reglas programa por ASUNTO, no por conversación.
 *
 * Es el paso 3 de la cirugía de docs/Mensajeria.md §20 y la pieza que reparte dinero: decide
 * qué mensajes automáticos salen hacia huéspedes reales y cuándo. Lo que se protege aquí:
 *
 *  - la compatibilidad hacia atrás (sin enlaces, el comportamiento de siempre, byte a byte),
 *  - que cada asunto tenga su propia agenda (el titular de N reservas, N recordatorios),
 *  - la idempotencia (re-ejecutar no duplica: duplicar es escribirle dos veces al huésped).
 *
 * Unitario puro: entidades reales, EntityManager de doble y un encolador anónimo. Sin
 * contenedor ni base de datos, al estilo de SkillRegistryTest.
 */
final class MessageRuleEngineTest extends TestCase
{
    private const string TZ = 'America/Lima';

    /** @var list<object> Lo que el motor pidió persistir, capturado por el doble del EM. */
    private array $persistidos = [];

    protected function setUp(): void
    {
        $this->persistidos = [];
    }

    // =========================================================================
    // DOBLES Y AYUDANTES
    // =========================================================================

    /**
     * @param list<MessageRule> $reglas Lo que "hay en BD"; el doble filtra por contextType
     *                                  igual que lo haría el findBy real.
     * @param list<ChannelEnqueuerInterface> $enqueuers
     */
    private function motor(array $reglas, array $enqueuers = []): MessageRuleEngine
    {
        $repositorio = $this->createStub(EntityRepository::class);
        $repositorio->method('findBy')->willReturnCallback(
            static fn (array $criterios): array => array_values(array_filter(
                $reglas,
                static fn (MessageRule $r): bool => $r->getContextType() === $criterios['contextType']
            ))
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repositorio);
        $em->method('persist')->willReturnCallback(function (object $entidad): void {
            $this->persistidos[] = $entidad;
        });
        // syncPendingMessage() pide referencias de canal para validar contra los enqueuers.
        $em->method('getReference')->willReturnCallback(
            static fn (string $clase, mixed $id): MessageChannel => new MessageChannel()->setId((string) $id)
        );

        return new MessageRuleEngine($em, new NullLogger(), $enqueuers, $this->enlacesDe(...$this->enlaces));
    }

    /** @var list<ConversacionEnlaceInterface> Los asuntos que verá el motor en esta prueba. */
    private array $enlaces = [];

    /**
     * El servicio de enlaces con un proveedor de mentira.
     *
     * Antes los asuntos se colgaban de la conversación con `addEnlacePms()`, lo que ataba el
     * test a una entidad del PMS para probar el motor de MENSAJERÍA. Ahora se inyectan.
     */
    private function enlacesDe(ConversacionEnlaceInterface ...$enlaces): EnlacesDeConversacion
    {
        $proveedor = new class (array_values($enlaces)) implements ProveedorDeEnlacesInterface {
            /** @param list<ConversacionEnlaceInterface> $enlaces */
            public function __construct(private readonly array $enlaces) {}

            public function getNegocio(): string { return 'prueba'; }

            public function paraConversacion(MessageConversation $conversacion): array { return $this->enlaces; }
        };

        return new EnlacesDeConversacion([$proveedor]);
    }

    /** Encolador que da por bueno todo lo del canal indicado: el camino feliz del worker. */
    private function enqueuer(string $canalId = 'whatsapp_meta'): ChannelEnqueuerInterface
    {
        return new class ($canalId) implements ChannelEnqueuerInterface {
            public function __construct(private readonly string $canalId) {}

            public function supports(MessageChannel $channel): bool { return $channel->getId() === $this->canalId; }
            public function isValid(Message $message): bool { return true; }
            public function isAlreadyEnqueued(Message $message): bool { return false; }
            public function createQueueEntity(
                Message $message,
                MessageChannel $channel,
                DateTimeImmutable $runAt
            ): ?MessageQueueItemInterface { return null; }
        };
    }

    private function regla(
        string $hito = ConversationMilestoneInterface::START,
        int $offsetMinutos = -1440,
        ?MessageChannel $canal = null,
        string $nombre = 'recordatorio'
    ): MessageRule {
        $regla = new MessageRule();
        $regla->setName($nombre);
        $regla->setMilestone($hito);
        $regla->setOffsetMinutes($offsetMinutos);

        if ($canal !== null) {
            $regla->addTargetCommunicationChannel($canal);
        }

        return $regla;
    }

    /** Un hito como lo serializa la conversación: texto sin zona, interpretado en Lima. */
    private function hito(string $modificador): MomentoDeHito
    {
        return MomentoDeHito::de(new DateTimeImmutable($modificador, new DateTimeZone(self::TZ)));
    }

    /** El instante en que el motor debe programar: hito + offset, ambos en la zona del hotel. */
    private function esperado(MomentoDeHito $hito, int $offsetMinutos): int
    {
        return $hito->comoFecha()
            ->modify(sprintf('%+d minutes', $offsetMinutos))
            ->getTimestamp();
    }

    /**
     * Cuelga una reserva real de la conversación, como quedará tras el poblado del paso 2.
     * `setCreatedAt` marca el enlace como ya flusheado: un enlace con `createdAt === null`
     * es «asunto recién nacido» y habilita rescates que aquí no se quieren por accidente.
     */
    private function enlace(
        MessageConversation $conversacion,
        MapaDeHitos $hitos,
        ?PmsReserva $reserva = null,
        ?string $origen = null
    ): PmsConversacionEnlace {
        $enlace = new PmsConversacionEnlace($conversacion, $reserva ?? new PmsReserva());
        $enlace->setMilestones($hitos);
        $enlace->setOrigen($origen);
        $enlace->setCreatedAt(new DateTimeImmutable('-1 hour'));
        // Se ACUMULA: hay pruebas con varios asuntos en el mismo hilo, y reemplazar la lista
        // dejaba sólo el último — que es justo lo contrario de lo que esas pruebas comprueban.
        $this->enlaces[] = $enlace;

        return $enlace;
    }

    /**
     * Simula lo que en producción hace MessageEnqueuerEntityListener durante el flush del
     * motor: materializar la cola física. Sin ella, la segunda pasada vería un mensaje QUEUED
     * sin colas y healZombieMessages() lo cancelaría — con razón: sería inejecutable.
     */
    private function fabricarCola(Message $mensaje, string $estado = 'pending'): void
    {
        $cola = new WhatsappMetaSendQueue();
        $cola->setStatus($estado);
        $mensaje->addQueue($cola);
    }

    /** @return list<Message> */
    private function mensajesDelSistema(MessageConversation $conversacion): array
    {
        return array_values(array_filter(
            $conversacion->getMessages()->toArray(),
            static fn (Message $m): bool => $m->getSenderType() === Message::SENDER_SYSTEM
        ));
    }

    // =========================================================================
    // COMPATIBILIDAD HACIA ATRÁS: SIN ENLACES, EL MOTOR DE SIEMPRE
    // =========================================================================

    /**
     * La garantía que permite desplegar sin ventana de mantenimiento: mientras las tablas de
     * enlaces estén vacías, la agenda sale del JSON `contextData` como toda la vida.
     */
    #[Test]
    public function sin_enlaces_programa_desde_el_json_de_la_conversacion(): void
    {
        $inicio = $this->hito('+5 days noon');

        $conversacion = new MessageConversation('pms_reserva', 'R-LEGADO');
        $conversacion->addContextMilestone(ConversationMilestoneInterface::START, $inicio);

        $this->motor([$this->regla()])
            ->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_INSERT);

        $mensajes = $this->mensajesDelSistema($conversacion);

        self::assertCount(1, $mensajes);
        self::assertSame($this->esperado($inicio, -1440), $mensajes[0]->getScheduledAt()?->getTimestamp());
        self::assertSame(Message::STATUS_QUEUED, $mensajes[0]->getStatus());

        // El estampado del asunto es aditivo también en modo legado: el par de la conversación,
        // que en esta era ES el asunto.
        self::assertSame('pms_reserva', $mensajes[0]->getAsuntoType());
        self::assertSame('R-LEGADO', $mensajes[0]->getAsuntoId());
    }

    // =========================================================================
    // POR ASUNTO: LOS HITOS SALEN DEL ENLACE
    // =========================================================================

    /**
     * Con un enlace, la fecha de envío sale de SUS milestones y no del JSON de la conversación:
     * si saliera del JSON, este test programaría para +2 días en vez de +6.
     */
    #[Test]
    public function con_un_enlace_los_hitos_salen_del_enlace_y_no_del_json(): void
    {
        $inicioViejo = $this->hito('+2 days noon');
        $inicioReal  = $this->hito('+6 days noon');

        $reserva = new PmsReserva();
        $conversacion = new MessageConversation('pms_reserva', (string) $reserva->getId());
        $conversacion->addContextMilestone(ConversationMilestoneInterface::START, $inicioViejo);

        $this->enlace($conversacion, MapaDeHitos::de([ConversationMilestoneInterface::START => $inicioReal]), $reserva);

        $this->motor([$this->regla()])
            ->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_UPDATE);

        $mensajes = $this->mensajesDelSistema($conversacion);

        self::assertCount(1, $mensajes);
        self::assertSame($this->esperado($inicioReal, -1440), $mensajes[0]->getScheduledAt()?->getTimestamp());
        self::assertSame((string) $reserva->getId(), $mensajes[0]->getAsuntoId());
    }

    /**
     * El corazón de la cirugía: el titular con varias reservas en el MISMO hilo recibe el
     * recordatorio de CADA una. Antes esto exigía una conversación por reserva; fusionarlas
     * sin este cambio dejaba una sola agenda para todas.
     */
    #[Test]
    public function varios_enlaces_significan_varios_mensajes_de_la_misma_regla(): void
    {
        $inicioA = $this->hito('+5 days noon');
        $inicioB = $this->hito('+9 days noon');

        $reservaA = new PmsReserva();
        $conversacion = new MessageConversation('pms_reserva', (string) $reservaA->getId());

        $this->enlace($conversacion, MapaDeHitos::de([ConversationMilestoneInterface::START => $inicioA]), $reservaA);
        $enlaceB = $this->enlace($conversacion, MapaDeHitos::de([ConversationMilestoneInterface::START => $inicioB]));

        $this->motor([$this->regla()])
            ->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_UPDATE);

        $mensajes = $this->mensajesDelSistema($conversacion);
        self::assertCount(2, $mensajes);

        $porAsunto = [];
        foreach ($mensajes as $mensaje) {
            $porAsunto[$mensaje->getAsuntoId()] = $mensaje->getScheduledAt()?->getTimestamp();
        }

        self::assertSame($this->esperado($inicioA, -1440), $porAsunto[(string) $reservaA->getId()]);
        self::assertSame($this->esperado($inicioB, -1440), $porAsunto[$enlaceB->getContextId()]);
    }

    // =========================================================================
    // IDEMPOTENCIA: RE-EJECUTAR NO ESCRIBE DOS VECES AL HUÉSPED
    // =========================================================================

    /**
     * La doble pasada es el camino NORMAL del motor (recalculo con UPDATE y luego INSERT, más
     * el barrido de cron cada 15 minutos): cada pasada tiene que reconocer los mensajes de la
     * anterior por (regla, asunto) y sincronizarlos, nunca crear otros.
     */
    #[Test]
    public function re_ejecutar_no_duplica_ni_reprograma_de_mas(): void
    {
        $inicioA = $this->hito('+5 days noon');
        $inicioB = $this->hito('+9 days noon');

        $canal = new MessageChannel()->setId('whatsapp_meta');
        $regla = $this->regla(canal: $canal);

        $reservaA = new PmsReserva();
        $conversacion = new MessageConversation('pms_reserva', (string) $reservaA->getId());
        $this->enlace($conversacion, MapaDeHitos::de([ConversationMilestoneInterface::START => $inicioA]), $reservaA);
        $this->enlace($conversacion, MapaDeHitos::de([ConversationMilestoneInterface::START => $inicioB]));

        $motor = $this->motor([$regla], [$this->enqueuer()]);

        $motor->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_INSERT);
        self::assertCount(2, $this->mensajesDelSistema($conversacion));

        // En producción el flush del motor materializa las colas vía listener; sin esto la
        // segunda pasada curaría los mensajes como zombis sin cola. Ver fabricarCola().
        foreach ($this->mensajesDelSistema($conversacion) as $mensaje) {
            $this->fabricarCola($mensaje);
        }

        $motor->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_UPDATE);
        $motor->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_UPDATE);

        $mensajes = $this->mensajesDelSistema($conversacion);

        self::assertCount(2, $mensajes, 'Re-ejecutar duplicó mensajes: eso es escribirle dos veces al huésped.');
        self::assertCount(2, array_filter($this->persistidos, static fn (object $e): bool => $e instanceof Message));

        // Y las fechas siguen siendo las de cada asunto, no una pisando a la otra.
        $fechas = array_map(static fn (Message $m): ?int => $m->getScheduledAt()?->getTimestamp(), $mensajes);
        sort($fechas);
        self::assertSame([$this->esperado($inicioA, -1440), $this->esperado($inicioB, -1440)], $fechas);
    }

    /**
     * La transición sin ventana: un mensaje encolado en la era una-conversación-por-activo
     * (sin estampar) tiene que reconocerse como del asunto cuyo par coincide con el de la
     * conversación — y quedar adoptado, no duplicado.
     */
    #[Test]
    public function un_mensaje_de_la_era_anterior_se_adopta_en_vez_de_duplicarse(): void
    {
        $inicioNuevo = $this->hito('+6 days noon');

        $canal = new MessageChannel()->setId('whatsapp_meta');
        $regla = $this->regla(canal: $canal);

        $reserva = new PmsReserva();
        $conversacion = new MessageConversation('pms_reserva', (string) $reserva->getId());

        // El mensaje que dejó programado el motor de la era anterior: con regla, sin asunto.
        $legado = new Message();
        $legado->setConversation($conversacion);
        $legado->setRule($regla);
        $legado->setSenderType(Message::SENDER_SYSTEM);
        $legado->setStatus(Message::STATUS_QUEUED);
        $legado->setScheduledAt(new DateTimeImmutable('+2 days noon', new DateTimeZone(self::TZ)));
        $this->fabricarCola($legado);

        // Llega el paso 2 de la cirugía: el enlace se puebla, con la reserva ya movida de fecha.
        $this->enlace($conversacion, MapaDeHitos::de([ConversationMilestoneInterface::START => $inicioNuevo]), $reserva);

        $this->motor([$regla], [$this->enqueuer()])
            ->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_UPDATE);

        $mensajes = $this->mensajesDelSistema($conversacion);

        self::assertCount(1, $mensajes, 'El mensaje legado no se reconoció como del asunto y se duplicó.');
        self::assertSame($legado, $mensajes[0]);
        self::assertSame((string) $reserva->getId(), $legado->getAsuntoId(), 'La adopción debe estampar el asunto.');
        self::assertSame($this->esperado($inicioNuevo, -1440), $legado->getScheduledAt()?->getTimestamp());
    }

    // =========================================================================
    // LA REGLA DEL DUEÑO: UN ASUNTO MUERTO NO ENCOLA NI UN MENSAJE
    // =========================================================================

    /**
     * «Cuando regenere, el engine que no se muera»: un asunto CANCELADO apaga lo suyo ya
     * encolado y no genera NADA nuevo — ni siquiera el aviso de cancelación, que en modo
     * enlace queda mudo a propósito (la base trae canceladas duplicadas por un bug de
     * guardado, y regenerar sobre ellas era un envío real por duplicado). Las agendas de las
     * demás reservas del hilo ni se enteran, con la conversación ABIERTA.
     */
    #[Test]
    public function un_asunto_cancelado_se_apaga_del_todo_sin_tocar_a_los_demas(): void
    {
        $inicioVivo = $this->hito('+5 days noon');
        $inicioMuerto = $this->hito('+9 days noon');

        $reglaLlegada = $this->regla(nombre: 'llegada');
        $reglaCancelacion = $this->regla(ConversationMilestoneInterface::CANCELLED, 0, nombre: 'cancelación');

        $reservaViva = new PmsReserva();
        $conversacion = new MessageConversation('pms_reserva', (string) $reservaViva->getId());
        $this->enlace($conversacion, MapaDeHitos::de([ConversationMilestoneInterface::START => $inicioVivo]), $reservaViva);
        $enlaceMuerto = $this->enlace($conversacion, MapaDeHitos::de([
            ConversationMilestoneInterface::START => $inicioMuerto,
            ConversationMilestoneInterface::CANCELLED => $this->hito('-30 minutes'),
        ]));

        // El recordatorio que la reserva cancelada dejó encolado antes de morir.
        $encolado = new Message();
        $encolado->setConversation($conversacion);
        $encolado->setRule($reglaLlegada);
        $encolado->setAsunto('pms_reserva', $enlaceMuerto->getContextId());
        $encolado->setSenderType(Message::SENDER_SYSTEM);
        $encolado->setStatus(Message::STATUS_QUEUED);
        $encolado->setScheduledAt($inicioMuerto->comoFecha());
        $this->fabricarCola($encolado);

        $this->motor([$reglaLlegada, $reglaCancelacion])
            ->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_UPDATE);

        self::assertSame(
            Message::STATUS_CANCELLED,
            $encolado->getStatus(),
            'El recordatorio de la reserva cancelada tenía que apagarse.'
        );

        $vivos = array_values(array_filter(
            $this->mensajesDelSistema($conversacion),
            static fn (Message $m): bool => $m->getStatus() === Message::STATUS_QUEUED
        ));

        self::assertSame(MessageConversation::STATUS_OPEN, $conversacion->getStatus());
        self::assertCount(1, $vivos, 'Sólo la reserva viva puede tener mensajes en pie.');
        self::assertSame((string) $reservaViva->getId(), $vivos[0]->getAsuntoId());
        self::assertSame('llegada', $vivos[0]->getRule()?->getName());
    }

    /** La otra cara de la muerte de un asunto: el vínculo `Terminado` silencia igual que la cancelación. */
    #[Test]
    public function un_asunto_con_vinculo_terminado_no_encola_nada(): void
    {
        $reserva = new PmsReserva();
        $conversacion = new MessageConversation('pms_reserva', (string) $reserva->getId());
        $this->enlace($conversacion, MapaDeHitos::de([ConversationMilestoneInterface::START => $this->hito('+5 days noon')]), $reserva)
            ->setVinculo(VinculoComercial::Terminado);

        $this->motor([$this->regla()])
            ->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_INSERT);

        self::assertCount(0, $this->mensajesDelSistema($conversacion));
    }

    /**
     * El modo legado NO cambia: sin enlaces, la cancelación sigue viajando en el estado del
     * hilo y el aviso de cancelación sigue saliendo. Es la compatibilidad dura del despliegue:
     * las conversaciones de hoy se comportan igual hasta que alguien les cuelgue enlaces.
     */
    #[Test]
    public function sin_enlaces_el_aviso_de_cancelacion_sigue_funcionando_como_siempre(): void
    {
        $cancelacion = $this->hito('-30 minutes');

        $conversacion = new MessageConversation('pms_reserva', 'R-LEGADO');
        $conversacion->addContextMilestone(ConversationMilestoneInterface::CANCELLED, $cancelacion);
        $conversacion->setStatus(MessageConversation::STATUS_CLOSED);

        $this->motor([$this->regla(ConversationMilestoneInterface::CANCELLED, 0, nombre: 'cancelación')])
            ->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_UPDATE);

        $mensajes = $this->mensajesDelSistema($conversacion);

        self::assertCount(1, $mensajes);
        self::assertSame($this->esperado($cancelacion, 0), $mensajes[0]->getScheduledAt()?->getTimestamp());
    }

    // =========================================================================
    // ASUNTOS BASURA: SE SALTAN, NO TUMBAN EL BARRIDO
    // =========================================================================

    /**
     * La base trae enlaces que no resuelven a nada (reservas borradas, filas de un bug de
     * guardado). Uno de ésos se registra y se salta; el asunto sano del mismo hilo recibe su
     * mensaje igual. Y con el panorama incompleto, la barrida de huérfanos NO corre: cancelar
     * sobre una foto rota apagaría mensajes legítimos.
     */
    #[Test]
    public function un_asunto_irresoluble_se_salta_sin_callar_a_los_sanos(): void
    {
        $inicio = $this->hito('+5 days noon');
        $regla = $this->regla();

        $reservaSana = new PmsReserva();
        $conversacion = new MessageConversation('pms_reserva', (string) $reservaSana->getId());
        $this->enlace($conversacion, MapaDeHitos::de([ConversationMilestoneInterface::START => $inicio]), $reservaSana);

        // El enlace podrido: sin reserva detrás, su contextId es la cadena vacía.
        $roto = new PmsConversacionEnlace($conversacion, null);
        $roto->setCreatedAt(new DateTimeImmutable('-1 hour'));
        $this->enlaces[] = $roto;

        // Un pendiente de un asunto que hoy no aparece: con el panorama roto no se puede saber
        // si fue retirado o si simplemente no cargó, así que tiene que sobrevivir la pasada.
        $dudoso = new Message();
        $dudoso->setConversation($conversacion);
        $dudoso->setRule($regla);
        $dudoso->setAsunto('pms_reserva', 'asunto-en-duda');
        $dudoso->setSenderType(Message::SENDER_SYSTEM);
        $dudoso->setStatus(Message::STATUS_QUEUED);
        $dudoso->setScheduledAt(new DateTimeImmutable('+3 days noon', new DateTimeZone(self::TZ)));
        $this->fabricarCola($dudoso);

        $this->motor([$regla])
            ->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_UPDATE);

        $delSano = array_values(array_filter(
            $this->mensajesDelSistema($conversacion),
            static fn (Message $m): bool => $m->getAsuntoId() === (string) $reservaSana->getId()
        ));

        self::assertCount(1, $delSano, 'El asunto sano tenía que recibir su mensaje aunque otro enlace esté podrido.');
        self::assertSame($this->esperado($inicio, -1440), $delSano[0]->getScheduledAt()?->getTimestamp());
        self::assertSame(Message::STATUS_QUEUED, $dudoso->getStatus(), 'Con panorama incompleto no se barre a los dudosos.');
    }

    /**
     * Un pendiente cuyo asunto ya no cuelga de la conversación no lo evalúa ninguna agenda:
     * sin la barrida de huérfanos saldría con su fecha vieja y datos que nadie mantiene.
     */
    #[Test]
    public function un_asunto_retirado_no_deja_mensajes_programados_atras(): void
    {
        $regla = $this->regla();

        $reserva = new PmsReserva();
        $conversacion = new MessageConversation('pms_reserva', (string) $reserva->getId());
        $this->enlace($conversacion, MapaDeHitos::de([ConversationMilestoneInterface::START => $this->hito('+5 days noon')]), $reserva);

        $huerfano = new Message();
        $huerfano->setConversation($conversacion);
        $huerfano->setRule($regla);
        $huerfano->setAsunto('pms_reserva', 'reserva-que-ya-no-esta');
        $huerfano->setSenderType(Message::SENDER_SYSTEM);
        $huerfano->setStatus(Message::STATUS_QUEUED);
        $huerfano->setScheduledAt(new DateTimeImmutable('+3 days noon', new DateTimeZone(self::TZ)));
        $this->fabricarCola($huerfano);

        $this->motor([$regla])
            ->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_UPDATE);

        self::assertSame(Message::STATUS_CANCELLED, $huerfano->getStatus());
    }
}
