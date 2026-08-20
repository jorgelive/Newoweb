<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Message\Contract\ChannelEnqueuerInterface;
use App\Contract\ConversationMilestoneInterface;
use App\Contract\MomentoDeHito;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Entity\MessageRule;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Throwable;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Motor centralizado para la programación, actualización y cancelación de mensajes automáticos.
 * DELEGA la fabricación de colas al MessageEnqueuerEntityListener; la CANCELACIÓN por canal
 * inválido la ejecuta él mismo (ver syncPendingMessage()).
 *
 * Documentado en docs/Mensajeria.md.
 */
final readonly class MessageRuleEngine
{
    public const string TRIGGER_INSERT = 'insert';
    public const string TRIGGER_UPDATE = 'update';
    public const string TRIGGER_COMMAND = 'command';

    /**
     * Zona horaria de operación del negocio.
     *
     * Se fija explícitamente en vez de confiar en la de php.ini: los hitos se guardan como
     * texto sin zona ('Y-m-d\TH:i:s'), así que un servidor en UTC desplazaría 5 horas todos
     * los umbrales de este archivo respecto al "hoy" del hotel.
     */
    private const string TZ = 'America/Lima';

    /** Un mensaje cuya hora de envío quedó más atrás que esto ya no se programa en su fecha original. */
    private const string PAST_THRESHOLD = '-2 hours';

    /**
     * Antigüedad máxima que admite el rescate de last-minute.
     *
     * Sin este suelo, importar el histórico de un canal (todas las conversaciones entran como
     * INSERT) disparaba de golpe los mensajes de bienvenida de reservas hechas hace meses.
     */
    private const string RESCUE_WINDOW = '-24 hours';

    /**
     * A partir de aquí un mensaje pendiente se considera caducado y se cancela.
     *
     * Cubre el hueco del worker: las colas se toman con `run_at <= now` sin fecha de caducidad,
     * así que un worker caído dos días acababa enviando el "llegas mañana" tras el check-out.
     */
    private const string EXPIRY_WINDOW = '-12 hours';

    /**
     * Constructor del motor de reglas.
     *
     * @param EntityManagerInterface $em
     * @param LoggerInterface $logger
     * @param iterable<ChannelEnqueuerInterface> $enqueuers Colección de encoladores para validar viabilidad de canales.
     */
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        #[TaggedIterator('app.message.enqueuer')]
        private iterable $enqueuers,
        // Los asuntos del hilo, vengan del dominio que vengan.
        private readonly EnlacesDeConversacion $enlaces
    ) {}

    /**
     * Evalúa todas las reglas contra las AGENDAS de una conversación y orquesta su ciclo de vida.
     *
     * Una agenda por ASUNTO ({@see AgendaDeAsunto}): si la conversación tiene enlaces
     * persistidos, cada enlace aporta la suya —un titular con N reservas en el mismo hilo tiene
     * N agendas de envíos, no una—. Sin enlaces, la única agenda sale de
     * `contextType`/`contextId`/`contextData` de la conversación y el motor se comporta
     * EXACTAMENTE como antes de la cirugía de §20: la retirada de esas columnas es un paso
     * posterior y no exige ventana de mantenimiento.
     *
     * @param MessageConversation $conversation La conversación objetivo.
     * @param string $trigger El origen de la ejecución.
     * @param bool $force Ignora el blindaje de reglas CREATED. NADA MÁS.
     *
     * ⚠️ `force` NO habilita por sí solo el rescate de vencidos ni la regeneración de mensajes
     * fallidos: esas dos son operaciones de REPARACIÓN y exigen además TRIGGER_COMMAND.
     * El motivo es que `PmsReservaRecalculoService` pasa `force: true` en cada cambio de
     * reserva, y `app:message:rebuild-context` lo hace de madrugada sobre TODO el parque:
     * atar el rescate a `force` convertía ese barrido nocturno en una tanda de envíos reales
     * a las 3 AM. Ver docs/Mensajeria.md §4.4.
     */
    public function syncConversationRules(
        MessageConversation $conversation,
        string $trigger = self::TRIGGER_UPDATE,
        bool $force = false
    ): void {
        $now = new DateTimeImmutable('now', new DateTimeZone(self::TZ));
        $pastThreshold = $now->modify(self::PAST_THRESHOLD);
        $rescueFloor   = $now->modify(self::RESCUE_WINDOW);

        // 1. Curación Automática: sanar mensajes zombie y caducar vencidos antes de evaluar reglas.
        $this->healZombieMessages($conversation, $now->modify(self::EXPIRY_WINDOW));

        [$agendas, $panoramaCompleto] = $this->agendasDe($conversation);

        // 2. Un mensaje pendiente cuyo asunto ya no cuelga de la conversación no lo va a
        // reprogramar ni cancelar ningún bucle de reglas: su agenda no existe. Sin esta barrida
        // se quedaría con su fecha vieja y saldría igual — el fallo silencioso que no perdona.
        //
        // SÓLO con el panorama completo: si algún enlace no resolvió esta pasada, no se puede
        // distinguir «asunto retirado» de «asunto que hoy no cargó», y cancelar sobre una foto
        // rota apagaría mensajes legítimos de los asuntos sanos.
        if ($panoramaCompleto) {
            $this->cancelarPendientesDeAsuntosRetirados($conversation, $agendas);
        }

        $esReparacionManual = $trigger === self::TRIGGER_COMMAND && $force;

        // Cache por tipo: con varios asuntos del mismo módulo la consulta de reglas es la
        // misma y no hay por qué repetirla.
        $reglasPorTipo = [];

        foreach ($agendas as $agenda) {
            // Se traen TODAS las reglas del contexto, activas o no. Filtrar por `isActive` en la
            // consulta hacía que una regla apagada desapareciera del bucle y sus mensajes ya
            // programados se enviaran igual: la desactivación tiene que poder CANCELAR.
            $rules = $reglasPorTipo[$agenda->contextType]
                ??= $this->em->getRepository(MessageRule::class)->findBy([
                    'contextType' => $agenda->contextType
                ]);

            // AISLAMIENTO POR ASUNTO. Antes de la cirugía, un asunto que reventaba se fallaba
            // sólo a sí mismo, porque era su propia conversación. Con N agendas en el mismo
            // hilo, dejar subir la excepción significa que un asunto podrido deja a los demás
            // sin sus recordatorios — regla del dueño: «cuando regenere, el engine que no se
            // muera». Se registra y se sigue.
            try {
                $this->evaluarReglasDeAgenda(
                    $conversation,
                    $agenda,
                    $rules,
                    $trigger,
                    $force,
                    $esReparacionManual,
                    $now,
                    $pastThreshold,
                    $rescueFloor
                );
            } catch (Throwable $e) {
                $this->logger->error(sprintf(
                    'El asunto %s de la conversación %s reventó al evaluarse: %s. Se sigue con los demás.',
                    $agenda->llave(),
                    $conversation->getId(),
                    $e->getMessage()
                ));
            }
        }

        $this->em->flush();
    }

    /**
     * El ciclo de vida de los mensajes de UNA agenda: calcular, cancelar, sincronizar o crear.
     * Es el cuerpo que antes vivía inline en syncConversationRules(); se extrajo para poder
     * aislar sus fallos por asunto sin un try/catch de doscientas líneas.
     *
     * @param list<MessageRule> $rules
     */
    private function evaluarReglasDeAgenda(
        MessageConversation $conversation,
        AgendaDeAsunto $agenda,
        array $rules,
        string $trigger,
        bool $force,
        bool $esReparacionManual,
        DateTimeImmutable $now,
        DateTimeImmutable $pastThreshold,
        DateTimeImmutable $rescueFloor
    ): void {
        // El rescate de mensajes vencidos sólo tiene sentido cuando el ASUNTO acaba de
        // nacer (reserva de última hora) o cuando un humano pide explícitamente reparar.
        // Con una conversación por reserva la novedad la decía el trigger INSERT; con hilos
        // compartidos un asunto nuevo llega como UPDATE, y lo delata `esNueva` (el enlace
        // aún sin flushear). Ver AgendaDeAsunto::deEnlace().
        //
        // Deliberadamente NO basta con `force`: el recálculo del PMS lo pasa siempre, y el
        // barrido nocturno de app:message:rebuild-context recorre con él todas las reservas.
        $canRescue = $trigger === self::TRIGGER_INSERT || $esReparacionManual || $agenda->esNueva;

        foreach ($rules as $rule) {
            $runAt   = $this->calculateRunAt($rule, $agenda);
            $applies = $this->ruleAppliesToAgenda($rule, $conversation, $agenda, $now);

            $existingMessage = $this->findExistingSystemMessage($conversation, $agenda, $rule, $esReparacionManual);

            if (!$applies || $runAt === null) {
                if ($existingMessage !== null) {
                    $this->cancelPendingQueues($existingMessage);
                }
                continue;
            }

            if ($existingMessage !== null) {
                $this->syncPendingMessage($existingMessage, $rule, $runAt);
                continue;
            }

            // =========================================================
            // 🛡️ Prevención de Spam Histórico
            // =========================================================
            // Evita que un simple UPDATE en una reserva vieja dispare correos de Bienvenida
            // de forma retroactiva. Se exige INSERT, `force` o asunto recién nacido, en vez
            // de excluir sólo UPDATE: con la lista blanca, un trigger nuevo como COMMAND
            // queda protegido por defecto. `esNueva` entra por la misma razón que en el
            // rescate: la bienvenida de una reserva que se cuelga de un hilo ya existente
            // llega como UPDATE y sin ella jamás saldría.
            if (
                $rule->getMilestone() === ConversationMilestoneInterface::CREATED
                && $trigger !== self::TRIGGER_INSERT
                && !$force
                && !$agenda->esNueva
            ) {
                $this->logger->info(sprintf(
                    "Omisión preventiva: Regla CREATED ('%s') ignorada en trigger '%s' para %s",
                    $rule->getTemplate()?->getName() ?? '¿?',
                    $trigger,
                    $conversation->getGuestName()
                ));
                continue;
            }

            if ($runAt > $pastThreshold) {
                // Flujo regular: El mensaje está en el futuro o acaba de cumplirse
                $this->createNewScheduledMessage($conversation, $agenda, $rule, $runAt);
                continue;
            }

            // Flujo de Rescate (Last-Minute Booking), con suelo de antigüedad
            if ($canRescue && $runAt > $rescueFloor) {
                $this->createNewScheduledMessage($conversation, $agenda, $rule, $now);
                continue;
            }

            if ($canRescue) {
                $this->logger->info(sprintf(
                    "Rescate descartado: la regla '%s' vencía el %s, fuera de la ventana de %s para %s",
                    $rule->getName(),
                    $runAt->format('Y-m-d H:i'),
                    self::RESCUE_WINDOW,
                    $conversation->getGuestName()
                ));
            }
        }
    }

    /**
     * Las agendas de envío de la conversación, una por asunto, más la señal de si el panorama
     * quedó COMPLETO (ningún enlace se saltó).
     *
     * Con enlaces persistidos, cada uno es una agenda y las columnas viejas de la conversación
     * DEJAN de leerse aquí — pero no se borran: son la red de seguridad para volver atrás y la
     * fuente de la adopción de mensajes legados (ver messageBelongsToAsunto()).
     *
     * Sin enlaces, la agenda única sale de la conversación misma: comportamiento idéntico al
     * anterior, byte a byte. Es lo que permite desplegar esto con las tablas de enlaces vacías.
     *
     * ── Los asuntos basura se saltan, no tumban a los demás ─────────────────
     * La base trae de dónde: hay conversaciones apuntando a reservas BORRADAS (`context_id` es
     * texto sin integridad referencial) y filas duplicadas de un bug de guardado. Un enlace que
     * no resuelve a nada —sin id, o cuyo proxy revienta al cargar— se registra y se salta, y
     * las agendas de los demás asuntos siguen su vida. Antes de esta cirugía un asunto malo
     * sólo se fallaba a sí mismo (era su propia conversación); con N por hilo, dejarlo
     * propagarse callaría a los sanos.
     *
     * @return array{list<AgendaDeAsunto>, bool} [agendas, panorama completo]
     */
    private function agendasDe(MessageConversation $conversation): array
    {
        $enlaces = $this->enlaces->de($conversation);

        if ($enlaces === []) {
            return [[AgendaDeAsunto::deConversacion($conversation)], true];
        }

        $agendas = [];
        $completo = true;

        foreach ($enlaces as $enlace) {
            // Sólo el TITULAR programa. Un asunto puede colgar de varios hilos —una reserva con
            // dos huéspedes que escriben desde números distintos son dos personas y dos hilos,
            // y fundirlos sería mentir— pero la AGENDA no puede duplicarse: la bienvenida, el
            // recordatorio de saldo y el check-out saldrían dos veces. Y el aviso de pago no es
            // para el acompañante.
            //
            // El no titular sigue existiendo y el agente lo lee: puede contestarle sobre el
            // asunto. Lo que no hace es programarle nada.
            if (!$enlace->esTitular()) {
                continue;
            }

            try {
                $agenda = AgendaDeAsunto::deEnlace($enlace);
            } catch (Throwable $e) {
                // Típicamente un proxy de Doctrine que no encuentra su fila. El asunto queda
                // sin evaluar esta pasada; los demás no tienen la culpa.
                $this->logger->error(sprintf(
                    'Asunto irresoluble en la conversación %s: %s. Se salta y se sigue con el resto.',
                    $conversation->getId(),
                    $e->getMessage()
                ));
                $completo = false;
                continue;
            }

            if ($agenda->contextId === '') {
                $this->logger->warning(sprintf(
                    'Asunto sin identidad en la conversación %s (tipo %s): enlace sin activo detrás. Se salta.',
                    $conversation->getId(),
                    $agenda->contextType
                ));
                $completo = false;
                continue;
            }

            $agendas[] = $agenda;
        }

        return [$agendas, $completo];
    }

    /**
     * Cancela los pendientes del sistema cuyo asunto ya no existe en la conversación.
     *
     * ¿Cuándo pasa?: al fusionar hilos (§20 paso 4) los mensajes viajan con su asunto estampado,
     * y si el enlace de un asunto se retira —reserva desvinculada, repoblado que corrige un
     * enlace mal puesto— sus mensajes quedan huérfanos: ninguna agenda los evalúa, así que nadie
     * les movería la fecha ni los cancelaría al cancelarse la reserva. Enviar con datos que ya
     * nadie mantiene es peor que no enviar: se cancelan y queda el rastro en el log.
     *
     * En una conversación sin enlaces es inocua por construcción: la única agenda ES el par de
     * la conversación, y los mensajes sin estampar pertenecen a ese par por definición.
     *
     * @param list<AgendaDeAsunto> $agendas
     */
    private function cancelarPendientesDeAsuntosRetirados(MessageConversation $conversation, array $agendas): void
    {
        $vigentes = [];
        foreach ($agendas as $agenda) {
            $vigentes[$agenda->llave()] = true;
        }

        foreach ($conversation->getMessages() as $msg) {
            if ($msg->getSenderType() !== Message::SENDER_SYSTEM) {
                continue;
            }

            if (!in_array($msg->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
                continue;
            }

            // Un mensaje sin estampar es de la era anterior: su asunto es el propio de la
            // conversación. El mismo criterio que messageBelongsToAsunto(), en la otra dirección.
            $llave = ($msg->getAsuntoType() ?? $conversation->getContextType())
                . '|' . ($msg->getAsuntoId() ?? $conversation->getContextId());

            if (isset($vigentes[$llave])) {
                continue;
            }

            $this->cancelPendingQueues($msg);
            $this->logger->info(sprintf(
                'Asunto retirado: mensaje %s cancelado porque su asunto (%s) ya no cuelga de la conversación %s.',
                $msg->getId(),
                $llave,
                $conversation->getId()
            ));
        }
    }

    /**
     * Valida si el estado, el contexto, la segmentación y los hitos cronológicos
     * de la agenda permiten la ejecución de la regla.
     */
    private function ruleAppliesToAgenda(
        MessageRule $rule,
        MessageConversation $conversation,
        AgendaDeAsunto $agenda,
        DateTimeImmutable $now
    ): bool {
        // Una regla apagada nunca aplica, pero SÍ entra al bucle para poder cancelar lo suyo.
        if (!$rule->isActive()) {
            return false;
        }

        if ($rule->getContextType() !== $agenda->contextType) {
            return false;
        }

        $milestone = $rule->getMilestone();
        $status    = $conversation->getStatus();

        // BARRERA DE ESTADO. Archivado = muerto para todo, venga la agenda de donde venga.
        if ($status === MessageConversation::STATUS_ARCHIVED) {
            return false;
        }

        if ($agenda->esLegado) {
            // Era anterior: la cancelación de la reserva y el cierre del hilo son EL MISMO
            // hecho (el factory cierra la conversación al detectar la reserva cancelada), así
            // que exigir "abierta" dejaba el hito CANCELLED inalcanzable.
            if ($milestone === ConversationMilestoneInterface::CANCELLED) {
                // Cierre manual de un operador ≠ cancelación de la reserva. Lo que distingue un
                // caso del otro es la presencia del hito `cancelled_at`, que calculateRunAt() ya exige.
                if ($status !== MessageConversation::STATUS_CLOSED) {
                    return false;
                }
            } elseif ($status === MessageConversation::STATUS_CLOSED) {
                return false;
            }
        } else {
            // Con asuntos persistidos la muerte es DEL ASUNTO y viaja en él (hito `cancelled_at`
            // o vínculo Terminado), no en el estado del hilo: cancelar una reserva del titular
            // no puede exigir que el hilo de la persona esté cerrado, porque eso mataría las
            // agendas de sus otras reservas vivas.
            //
            // ⚠️ Y es CATEGÓRICO por decisión del dueño del producto («cuando regenere, el
            // engine que no se muera»): un asunto muerto no encola NI UN mensaje — ni siquiera
            // el aviso de cancelación, que en modo legado sí existe (rama de arriba). El motivo
            // es la basura medida en producción: reservas canceladas duplicadas por un bug de
            // guardado. Un repoblado o un rebuild sobre ellas habría significado un envío real
            // POR CADA duplicado. Consecuencia asumida y documentada en §20: el aviso de
            // cancelación por asunto queda mudo hasta que producto decida recuperarlo con una
            // guarda mejor (p. ej. sólo el primer asunto muerto del par persona+fechas).
            if ($agenda->estaMuerta()) {
                return false;
            }

            // Sin asunto muerto no hay cancelación que avisar; y el cierre manual del hilo
            // sigue siendo el silencio de TODA la persona, como hoy.
            if ($milestone === ConversationMilestoneInterface::CANCELLED
                || $status === MessageConversation::STATUS_CLOSED) {
                return false;
            }
        }

        // Segmentación: fuente única en la entidad (incluye allowedAgencies, que el motor
        // ignoraba). El origen y la agencia son del ASUNTO: la regla «sólo directas» decide
        // reserva a reserva aunque compartan hilo.
        if (!$rule->matchesSegmentation($agenda->origen, $agenda->agencia)) {
            return false;
        }

        $milestones = $agenda->milestones;
        $today = $now->setTime(0, 0, 0);

        // Hitos con fecha propia que, una vez pasados, invalidan la regla.
        // CREATED queda fuera a propósito: siempre está en el pasado, y a él lo acota la
        // ventana de rescate, no esta guarda.
        $hitosCaducables = [
            ConversationMilestoneInterface::START,
            ConversationMilestoneInterface::END,
            ConversationMilestoneInterface::EXPECTED_ARRIVAL,
        ];

        if (in_array($milestone, $hitosCaducables, true)) {
            $fechaStr = $milestones[$milestone] ?? null;
            if (!$fechaStr) {
                return false;
            }

            $fecha = $this->parseMilestone($fechaStr);
            if ($fecha === null || $fecha->setTime(0, 0, 0) < $today) {
                return false;
            }
        }

        return true;
    }

    /**
     * Proyecta la fecha matemática exacta de envío aplicando el offset de la regla al hito.
     */
    private function calculateRunAt(MessageRule $rule, AgendaDeAsunto $agenda): ?DateTimeImmutable
    {
        $baseDateString = $agenda->milestones[$rule->getMilestone()] ?? null;

        if (!$baseDateString) {
            return null;
        }

        return $this->parseMilestone($baseDateString)
            ?->modify(sprintf('%+d minutes', $rule->getOffsetMinutes()));
    }

    /**
     * Interpreta un hito del JSON en la zona horaria de operación.
     *
     * Es una línea porque toda la tolerancia —texto, objeto, la forma serializada de las filas
     * viejas— vive ahora en {@see MomentoDeHito::de()}, que es el único sitio del módulo que
     * convierte. Antes esto era una de cinco copias de la misma decisión, y la que se olvidó de
     * escribir tumbó la sincronización de reservas canceladas (`docs/Mensajeria.md` §22.16).
     *
     * @param string|DateTimeInterface|array{date?: string} $raw
     */
    private function parseMilestone(string|DateTimeInterface|array $raw): ?DateTimeImmutable
    {
        return MomentoDeHito::de($raw)?->comoFecha();
    }

    /**
     * Curación de Mensajes Zombie.
     * Escanea los mensajes de la conversación y actualiza su estado real en base a sus colas.
     * NUNCA elimina la fecha scheduledAt para preservar la línea de tiempo visual del frontend.
     *
     * @param DateTimeImmutable $expiryLimit Frontera de caducidad para pendientes atrasados.
     */
    private function healZombieMessages(MessageConversation $conversation, DateTimeImmutable $expiryLimit): void
    {
        foreach ($conversation->getMessages() as $msg) {
            if (!in_array($msg->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED, Message::STATUS_CANCELLED], true)) {
                continue;
            }

            $oldStatus = $msg->getStatus();
            $this->resolveMessageStatus($msg);

            // Un pendiente sin ninguna cola es inejecutable por definición: nadie lo va a enviar.
            // resolveMessageStatus() no puede deducirlo (no tiene colas que leer), así que se
            // dictamina aquí, donde sabemos que el mensaje viene de BD y no de esta misma pasada.
            if ($msg->getAllQueues() === []
                && in_array($msg->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
                $msg->setStatus(Message::STATUS_CANCELLED);
            }

            // Caducidad: un recordatorio que lleva medio día atascado ya no informa de nada.
            // Sólo aplica a mensajes del sistema con fecha programada; los que un operador dejó
            // en cola a mano son suyos y se respetan.
            $scheduledAt = $msg->getScheduledAt();
            if ($msg->getSenderType() === Message::SENDER_SYSTEM
                && $scheduledAt !== null
                && $scheduledAt < $expiryLimit
                && in_array($msg->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
                $msg->setStatus(Message::STATUS_CANCELLED);
                $this->logger->info(sprintf(
                    'Caducidad: mensaje %s programado para %s se cancela por exceder la ventana de %s.',
                    $msg->getId(),
                    $scheduledAt->format('Y-m-d H:i'),
                    self::EXPIRY_WINDOW
                ));
            }

            if ($oldStatus !== $msg->getStatus()) {
                $this->logger->info("Sanidad: Mensaje {$msg->getId()} corregido de {$oldStatus} a {$msg->getStatus()}.");
            }
        }
    }

    /**
     * Localiza el último intento de un mensaje generado por el sistema para una regla y un asunto.
     * Ignora los intentos muertos (cancelados) para forzar la creación de uno nuevo.
     *
     * ¿Por qué existe?: Facilita la reactivación de reservas. Si una reserva fue cancelada
     * (y su mensaje murió) pero luego se reactiva, este método asegura que el motor genere
     * una nueva entidad limpia.
     *
     * La identidad es (REGLA, ASUNTO), y las dos mitades importan:
     *
     * - La regla y no la plantilla: dos reglas pueden compartir plantilla y antes se reconocían
     *   como el mismo mensaje, pisándose la fecha de envío. Los mensajes anteriores a la
     *   columna `rule_id` se emparejan por plantilla como antes (legado).
     * - El asunto, porque con varios colgando del mismo hilo la MISMA regla programa N mensajes
     *   legítimos: sin esta mitad, al evaluar la reserva B el motor encontraba el recordatorio
     *   de la A y le pisaba la fecha — de N recordatorios sobrevivía uno.
     *
     * @param bool $allowFailedRetry Permite regenerar un mensaje FAILED que ya agotó reintentos.
     *                               Por defecto NO: un FAILED puede seguir en ciclo de reintentos
     *                               en el Worker y regenerarlo arriesga un envío doble al huésped.
     *                               Sólo lo activa la reparación manual (`sync-rules --force`).
     */
    private function findExistingSystemMessage(
        MessageConversation $conversation,
        AgendaDeAsunto $agenda,
        MessageRule $rule,
        bool $allowFailedRetry = false
    ): ?Message {
        $ruleId = (string) $rule->getId();
        $templateId = $rule->getTemplate() !== null ? (string) $rule->getTemplate()->getId() : null;

        // Invertimos el arreglo para evaluar siempre el intento más reciente primero.
        $messages = array_reverse($conversation->getMessages()->toArray());

        foreach ($messages as $msg) {
            if ($msg->getSenderType() !== Message::SENDER_SYSTEM) {
                continue;
            }

            if (!$this->messageBelongsToRule($msg, $ruleId, $templateId)) {
                continue;
            }

            if (!$this->messageBelongsToAsunto($msg, $agenda, $conversation)) {
                continue;
            }

            // ADOPCIÓN: la primera vez que el motor por-asunto toca un mensaje de la era
            // anterior, lo estampa. Sin esto, cada pasada repetiría el emparejamiento por
            // deducción, y el día que las columnas de contexto de la conversación se retiren
            // (§20 paso 5) la deducción se quedaría sin base.
            if ($msg->getAsuntoType() === null) {
                $msg->setAsunto($agenda->contextType, $agenda->contextId);
            }

            // 🔥 LA MAGIA DE LA INMUTABILIDAD:
            // Solo generamos un intento nuevo si la cancelación fue una decisión de negocio definitiva.
            if ($msg->getStatus() === Message::STATUS_CANCELLED) {
                return null;
            }

            // Salida de emergencia del callejón sin salida: un FAILED con todas sus colas
            // agotadas no lo va a reintentar nadie, y sin esto el mensaje quedaba muerto para
            // siempre porque su mera existencia bloqueaba la creación de uno nuevo.
            if ($allowFailedRetry
                && $msg->getStatus() === Message::STATUS_FAILED
                && $this->queuesAreExhausted($msg)) {
                return null;
            }

            // Si está pendiente, fallido, enviado o en proceso, retornamos este mismo para actualizarlo
            return $msg;
        }

        return null;
    }

    /**
     * Empareja un mensaje con una regla. Prefiere el vínculo explícito y sólo cae en la
     * plantilla para los mensajes creados antes de que existiera la columna `rule_id`.
     */
    private function messageBelongsToRule(Message $msg, string $ruleId, ?string $templateId): bool
    {
        $msgRule = $msg->getRule();
        if ($msgRule !== null) {
            return (string) $msgRule->getId() === $ruleId;
        }

        if ($templateId === null || $msg->getTemplate() === null) {
            return false;
        }

        return (string) $msg->getTemplate()->getId() === $templateId;
    }

    /**
     * Empareja un mensaje con un asunto. Prefiere el estampado explícito
     * ({@see Message::getAsuntoType()}) y sólo deduce para los mensajes creados antes de que
     * existieran las columnas: un mensaje sin estampar sólo pudo programarlo el asunto propio
     * de la conversación, porque en esa era la conversación ERA el asunto.
     *
     * La deducción es también lo que hace la transición sin ventana: al estrenar un enlace cuyo
     * par coincide con el de la conversación, los mensajes ya encolados se reconocen como suyos
     * (y se adoptan) en vez de duplicarse — duplicar aquí es enviar dos veces al huésped.
     */
    private function messageBelongsToAsunto(
        Message $msg,
        AgendaDeAsunto $agenda,
        MessageConversation $conversation
    ): bool {
        if ($msg->getAsuntoType() !== null) {
            return $msg->getAsuntoType() === $agenda->contextType
                && $msg->getAsuntoId() === $agenda->contextId;
        }

        return $agenda->contextType === $conversation->getContextType()
            && $agenda->contextId === $conversation->getContextId();
    }

    /**
     * ¿Se acabaron de verdad las opciones de este mensaje?
     * Cualquier cola viva (pendiente, en curso o con reintentos disponibles) o cualquier
     * indicio de entrega previa lo descarta: no queremos duplicar un envío ya realizado.
     */
    private function queuesAreExhausted(Message $message): bool
    {
        $queues = $message->getAllQueues();
        if ($queues === []) {
            return true;
        }

        foreach ($queues as $q) {
            $status = $q->getStatus();

            if (in_array($status, ['pending', 'processing', 'success', 'sent', 'delivered'], true)) {
                return false;
            }

            if ($status === 'failed' && $q->getRetryCount() < $q->getMaxAttempts()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sincroniza la nueva fecha y realiza una "Poda de Canales" reactiva.
     * Si un canal de la regla ya no es válido para el contexto actual (ej. Beds24 en Directo),
     * se cancela su cola.
     */
    private function syncPendingMessage(Message $message, MessageRule $rule, DateTimeImmutable $newRunAt): void
    {
        // CORTAFUEGOS DE ESTADOS TERMINALES (Inmutabilidad estricta)
        if (in_array($message->getStatus(), [Message::STATUS_SENT, Message::STATUS_DELIVERED, Message::STATUS_READ], true)) {
            return;
        }

        // 1. Obtenemos los canales que la REGLA quiere usar
        $targetChannelIds = $rule->getTargetCommunicationChannels()->map(fn($c) => $c->getId())->toArray();

        // 2. 🔥 FILTRO DE SEGURIDAD: Validamos cada canal contra su Enqueuer
        $validChannelIds = [];
        foreach ($targetChannelIds as $channelId) {
            foreach ($this->enqueuers as $enqueuer) {
                if ($enqueuer->supports($this->em->getReference(MessageChannel::class, $channelId))) {
                    if ($enqueuer->isValid($message)) {
                        $validChannelIds[] = $channelId;
                    } else {
                        $this->logger->info("Regla Engine: Canal '$channelId' invalidado por el Enqueuer para el mensaje {$message->getId()}");
                    }
                    break;
                }
            }
        }

        // 3. Publicamos la lista filtrada para el Listener (fabricación de canales nuevos).
        $message->setTransientChannels($validChannelIds);

        // 4. Si no quedan canales válidos, cancelamos el mensaje padre completamente
        if (empty($validChannelIds)) {
            $this->cancelPendingQueues($message);
            return;
        }

        // 5. Poda explícita de las colas cuyo canal dejó de ser válido.
        //
        // No se delega en el preUpdate del MessageEnqueuerEntityListener a propósito:
        // `transientChannels` NO es una columna mapeada, así que cambiarla sola no ensucia la
        // entidad, Doctrine no calcula changeset y el preUpdate nunca se dispara. La cola de
        // un canal ya prohibido se quedaba viva y acababa enviando. Cancelar aquí sí modifica
        // entidades gestionadas y el flush del motor lo persiste.
        $this->cancelQueuesOutsideChannels($message, $validChannelIds);

        if (in_array($message->getStatus(), [Message::STATUS_QUEUED, Message::STATUS_PENDING], true)) {
            if ($message->getScheduledAt() != $newRunAt) {
                $message->setScheduledAt($newRunAt);
            }
        }

        $this->resolveMessageStatus($message);
    }

    /**
     * Cancela, de forma agnóstica al canal, toda cola viva que no esté en la lista permitida.
     *
     * @param string[] $allowedChannelIds
     */
    private function cancelQueuesOutsideChannels(Message $message, array $allowedChannelIds): void
    {
        foreach ($message->getAllQueues() as $queue) {
            if (!$queue instanceof MessageQueueItemInterface) {
                continue;
            }

            if (in_array($queue->getChannelId(), $allowedChannelIds, true)) {
                continue;
            }

            if (in_array($queue->getStatus(), ['pending', 'queued'], true)) {
                $queue->setStatus('cancelled');
                $this->logger->info(sprintf(
                    "Poda de canales: cola %s del mensaje %s cancelada por canal no permitido.",
                    $queue->getChannelId(),
                    $message->getId()
                ));
            }
        }
    }

    /**
     * Marca el mensaje como cancelado. El Listener propagará esto a las colas.
     */
    private function cancelPendingQueues(Message $message): void
    {
        if (in_array($message->getStatus(), [Message::STATUS_QUEUED, Message::STATUS_PENDING], true)) {
            $message->setStatus(Message::STATUS_CANCELLED);
        }
    }

    private function createNewScheduledMessage(
        MessageConversation $conversation,
        AgendaDeAsunto $agenda,
        MessageRule $rule,
        DateTimeImmutable $runAt
    ): void {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setTemplate($rule->getTemplate());
        $message->setRule($rule);

        // Se estampa SIEMPRE, también en modo legado (donde el asunto es el par de la propia
        // conversación): dejar el estampado sólo para los enlaces habría creado una tercera
        // generación de mensajes —ni viejos ni completos— que la fusión de hilos (§20 paso 4)
        // tendría que volver a deducir.
        $message->setAsunto($agenda->contextType, $agenda->contextId);

        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setSenderType(Message::SENDER_SYSTEM);
        $message->setStatus(Message::STATUS_QUEUED);

        $message->setScheduledAt($runAt);

        // Mapeo de canales destino configurados en la regla
        $channelIds = $rule->getTargetCommunicationChannels()->map(fn($c) => $c->getId())->toArray();
        $message->setTransientChannels($channelIds);

        $conversation->addMessage($message);
        $this->em->persist($message);

        $this->logger->info("Programado nuevo mensaje automático para {$conversation->getGuestName()} a las {$runAt->format('Y-m-d H:i')}");
    }

    /**
     * Evalúa la realidad física de las colas para dictaminar el estado real del mensaje raíz.
     * Es 100% agnóstico a los canales, delegando la recolección a la entidad Message.
     *
     * El caso "sin colas" no se decide aquí: durante una pasada del motor un mensaje recién
     * creado todavía no las tiene materializadas. Lo resuelve healZombieMessages().
     */
    private function resolveMessageStatus(Message $message): void
    {
        $hasPending = $hasSuccess = $hasFailed = $hasCancelled = false;

        foreach ($message->getAllQueues() as $q) {
            $s = $q->getStatus();

            if (in_array($s, ['pending', 'processing'], true)) {
                $hasPending = true;
            }
            if (in_array($s, ['success', 'sent', 'delivered'], true)) {
                $hasSuccess = true;
            }
            if ($s === 'failed') {
                $hasFailed = true;
            }
            if ($s === 'cancelled') {
                $hasCancelled = true;
            }
        }

        if ($hasPending) {
            $message->setStatus(Message::STATUS_QUEUED);
            return;
        }

        if ($hasSuccess) {
            $message->setStatus(Message::STATUS_SENT);
            return;
        }

        if ($hasFailed) {
            $message->setStatus(Message::STATUS_FAILED);
            return;
        }

        // Todas las colas canceladas: el mensaje no tiene por dónde salir. Sin esta rama se
        // quedaba QUEUED para siempre y el motor lo seguía tratando como vivo en cada pasada.
        if ($hasCancelled
            && in_array($message->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
            $message->setStatus(Message::STATUS_CANCELLED);
        }
    }
}
