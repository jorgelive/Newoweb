<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Access\AgentActor;
use App\Agent\Access\AgentActorFactory;
use App\Agent\Conversation\InstruccionesDominioRegistry;
use App\Agent\Access\RestriccionCanal;
use App\Message\Contract\VinculoComercial;
use App\Agent\Conversation\AgentEngineRegistry;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Access\ActorInterface;
use App\Agent\Conversation\PerfilConversacion;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Conversation\ReglasCompartidas;
use App\Agent\Conversation\SelectorDePotencia;
use App\Agent\Skill\SkillRegistry;
use App\Agent\Triage\DecisionDeTriaje;
use App\Agent\Triage\TipoDeMensaje;
use App\Agent\Triage\Triaje;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Repository\UserRepository;
use App\Service\Phone\PhoneSanitizer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contesta con IA los mensajes de texto libre del huésped.
 *
 * Es la rama `free_text` del IntentRouter: la que hasta ahora marcaba el mensaje como
 * «resuelto» sin hacer nada ni dejar rastro (835 mensajes de Beds24 y 685 de WhatsApp).
 *
 * NO genera y envía sin más: seis guardias deciden antes si toca callarse. Cada salida
 * devuelve un motivo, que el router guarda en `inbound_intent.resolution` — así se puede
 * medir después cuántos contestó el bot y cuántos se dejaron pasar, y por qué.
 *
 * Ver docs/Mensajeria.md §9.
 */
final readonly class AiConversationProcessor
{
    /** Mensajes de historial que se mandan al modelo. Acota coste y latencia. */
    private const int HISTORIAL_MAX = 20;

    /** Para fechar el prompt. El negocio y quien escribe están en Perú. */
    private const string TZ_PERU = 'America/Lima';

    /**
     * Si un operador humano escribió hace menos de esto, el bot no interviene.
     *
     * Es el guardia más importante de todos: nada enfada más a un huésped —ni deja peor al
     * hotel— que un bot pisando a la persona que ya le está atendiendo.
     */
    private const string HUMANO_AL_MANDO = '-30 minutes';

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private AgentEngineRegistry $motores,
        private SelectorDePotencia $potencias,
        private SkillRegistry $skills,
        private Triaje $triaje,
        private UserRepository $usuarios,
        private AgentActorFactory $actores,
        private InstruccionesDominioRegistry $dominios,
        private PhoneSanitizer $telefonos,
        private EscaleraDeTemas $escalera,
        private ConocimientoGenerico $conocimiento,
        private bool $habilitado,
    ) {}

    /**
     * @return string Motivo de resolución, que acaba en `inbound_intent.resolution`.
     */
    public function process(Message $message): string
    {
        if (!$this->habilitado) {
            return 'ia_desactivada';
        }

        if (!$this->motores->hayDisponible()) {
            $this->logger->warning('IA: habilitada pero sin credenciales de ningún proveedor; no se responde.');
            return 'ia_sin_credenciales';
        }

        $conversacion = $message->getConversation();
        if ($conversacion === null) {
            return 'sin_conversacion';
        }

        if ($motivo = $this->motivoParaCallarse($conversacion, $message)) {
            return $motivo;
        }

        // 🪜 SE ABRE EL TURNO EN LIMPIO. `EscaleraDeTemas` es un servicio compartido y el worker
        // de Messenger es un proceso largo que atiende conversaciones distintas en fila: un turno
        // que consultó la guía y acabó callándose —ráfaga, humano que entra a mitad, motor sin
        // texto— dejaba la huella colgando y se la pegaba al saliente del SIGUIENTE huésped. Como
        // los ítems de guía se comparten entre casitas, ese id colisiona de verdad: alguien que
        // preguntaba por primera vez recibía el paso 1.
        $this->escalera->limpiar();

        try {
            $respuesta = $this->generar($conversacion, $message);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'IA: fallo generando respuesta para la conversación %s: %s',
                $conversacion->getId(),
                $e->getMessage()
            ));

            // 🩹 Un fallo del motor no puede ser SILENCIO para el huésped.
            //
            // Antes se devolvía `error_ia` y no salía nada: ni respuesta, ni acuse, ni
            // reintento. El huésped miraba el chat sin saber si alguien le había leído, y la
            // única señal era un `error` en el log y el no leído en el panel.
            //
            // El acuse ya existía para el caso «el motor respondió sin texto»; una caída de red
            // a mitad de turno deja al huésped exactamente igual de solo, así que se le trata
            // igual. Es el suelo, no la política.

            // El acuse NO hereda la huella: si `generar()` reventó después de que la guía
            // corriera, el huésped no llegó a leer ese peldaño. Estamparlo en un «lo estoy
            // revisando» le daría por explicado algo que nunca recibió, y la próxima vez se le
            // serviría el paso siguiente.
            $this->escalera->limpiar();
            $this->encolarRespuesta($conversacion, $message, $this->acuseDeRecibo($conversacion));

            return 'error_ia';
        }

        $respuesta = $this->limpiarMarcasDeHistorial($respuesta);

        if ($respuesta === null || trim($respuesta) === '') {
            return 'ia_sin_respuesta';
        }

        // SEGUNDA MIRADA A LA RÁFAGA. Generar tarda segundos, y en ese hueco el huésped ha
        // podido añadir algo que cambia la pregunta («¿cuánto cuesta?» → «da igual, ya lo vi»).
        // Mandar ahora esta respuesta es contestar a destiempo; se tira, y el trabajo del
        // mensaje nuevo responderá a todo junto. Es lo único que cuesta dinero de la ráfaga:
        // el modelo ya se pagó. Preferible a que el huésped lea una respuesta desfasada.
        // Y la misma pregunta para la persona: entre el guardia de arriba y este punto ha
        // pasado la generación entera. Si un operador contestó en ese hueco, esta respuesta
        // llega tarde y encima de la suya.
        if ($this->hayHumanoAtendiendo($conversacion)) {
            $this->logger->info(sprintf(
                'IA: respuesta descartada en %s; un operador contestó mientras se generaba.',
                $conversacion->getId()
            ));

            return 'humano_atendiendo_al_responder';
        }

        if ($this->hayMensajePosterior($conversacion, $message)) {
            $this->logger->info(sprintf(
                'IA: respuesta descartada en la conversación %s; el huésped escribió mientras se generaba.',
                $conversacion->getId()
            ));

            return 'rafaga_superada_al_responder';
        }

        $this->encolarRespuesta($conversacion, $message, $respuesta);

        // PEDIDO DE UN COLABORADOR YA RESUELTO → la conversación se da por leída.
        //
        // Cuando quien escribe es del equipo (identificado por su número) y el agente le ha
        // contestado, no queda nada que atender: nadie del equipo tiene que responderle a
        // otro compañero. Si se dejara sin leer, esa consulta interna se quedaría para
        // siempre en la bandeja de pendientes, en el badge del icono y en el panel de Home,
        // compitiendo con huéspedes que sí esperan respuesta.
        //
        // Solo se marca cuando la respuesta SALIÓ. Si el bot se calló por cualquiera de los
        // guardias, la conversación sigue sin leer a propósito: ahí sí hay algo pendiente.
        $this->marcarLeidaSiEsDelEquipo($conversacion, $message);

        return 'ia';
    }

    /**
     * Da por leída la conversación cuando el que preguntaba era del equipo.
     *
     * Se reconoce por el mismo camino que el actor: el número en `user.telefono`. Con un
     * huésped no se hace nunca —su mensaje sigue pendiente de que alguien lo mire, conteste
     * el bot o no—, porque el operador quiere revisar qué se le respondió.
     *
     * Se ponen a cero las DOS fuentes, el contador y el estado de los mensajes. Bajar solo
     * el contador es lo que dejó 54 conversaciones con 0 no leídos y 156 mensajes en
     * `received` (ver docs/Mensajeria.md §7); no se repite aquí.
     */
    private function marcarLeidaSiEsDelEquipo(MessageConversation $conversacion, Message $entrante): void
    {
        if ($this->usuarios->findByTelefono($conversacion->getGuestPhone(), $this->telefonos) === null) {
            return;
        }

        $pendientes = $this->em->getRepository(Message::class)->findBy([
            'conversation' => $conversacion,
            'direction'    => Message::DIRECTION_INCOMING,
            'status'       => Message::STATUS_RECEIVED,
        ]);

        foreach ($pendientes as $pendiente) {
            $pendiente->setStatus(Message::STATUS_READ);
        }

        $conversacion->resetUnreadCount();
        $this->em->flush();

        $this->logger->info(sprintf(
            'IA: consulta interna resuelta en %s; se marca leída (%d mensajes).',
            $conversacion->getId(),
            count($pendientes)
        ));
    }

    /**
     * Escribe en la conversación el resumen que trajo el triaje, si trajo alguno.
     *
     * Es la delegación del §11: una sola llamada al modelo hace las dos cosas. La marca
     * `resumenIaHasta` es la que corta la segunda pasada —la guarda de idempotencia de
     * {@see \App\Message\Service\Resumen\ResumenConversacionService::actualizar()} la
     * compara con el mensaje más reciente—, así que sin ponerla la delegación no sirve de
     * nada aunque el texto se guarde.
     */
    private function guardarResumenDelTriaje(
        MessageConversation $conversacion,
        Message $entrante,
        DecisionDeTriaje $decision
    ): void {
        if ($decision->resumen === null) {
            return;
        }

        $conversacion->setResumenIa($decision->resumen);
        $conversacion->setResumenIaHasta($entrante->getEffectiveDateTime() ?? $entrante->getCreatedAt());

        $this->em->flush();

        $this->logger->info(sprintf(
            'IA: resumen entregado por el triaje (sin llamada extra) en %s: «%s».',
            $conversacion->getId(),
            $decision->resumen
        ));
    }

    /**
     * Los guardias. Devuelve el motivo por el que NO hay que contestar, o null para seguir.
     */
    private function motivoParaCallarse(MessageConversation $conversacion, Message $entrante): ?string
    {
        // 1. La conversación ya terminó su ciclo.
        if ($conversacion->getStatus() !== MessageConversation::STATUS_OPEN) {
            return 'conversacion_no_abierta';
        }

        // 2. HUMANO AL MANDO. Si un operador contestó hace poco, la conversación es suya.
        if ($this->hayHumanoAtendiendo($conversacion)) {
            return 'humano_atendiendo';
        }

        // 3. RÁFAGA. El huésped siguió escribiendo mientras corría la espera del listener, así
        // que este trozo ya no es el final de lo que quiere decir. Se calla y se marca
        // resuelto: el trabajo del mensaje posterior verá esto en el historial y contestará a
        // la ráfaga entera de una vez. De cuatro trabajos encolados sobrevive uno.
        if ($this->hayMensajePosterior($conversacion, $entrante)) {
            return 'rafaga_superada';
        }

        // 4. IDEMPOTENCIA. El transporte async reintenta hasta 3 veces (messenger.yaml), y un
        // reintento después de haber enviado duplicaría el mensaje al huésped. Si ya hay una
        // respuesta del sistema posterior a este mensaje, el trabajo está hecho.
        if ($this->yaSeRespondio($conversacion, $entrante)) {
            return 'ya_respondido';
        }

        // 5. VENTANA DE 24 H DE WHATSAPP. Fuera de sesión Meta sólo acepta plantillas
        // aprobadas, así que un texto generado sería rechazado por el enqueuer y acabaría
        // como mensaje FAILED. Mejor no generarlo: ahorra la llamada al modelo y el ruido.
        $canal = (string) ($entrante->getChannel()?->getId() ?? '');
        if ($canal === 'whatsapp_meta' && !$conversacion->isWhatsappSessionActive()) {
            return 'fuera_de_ventana_24h';
        }

        // 6. Canal deshabilitado por rebote duro (número inválido, bloqueo de Meta).
        if ($canal === 'whatsapp_meta' && $conversacion->isWhatsappDisabled()) {
            return 'canal_deshabilitado';
        }

        return null;
    }

    /**
     * ¿Ha escrito una PERSONA del alojamiento hace poco?
     *
     * ⚠️ Consulta a la TABLA, no a `$conversacion->getMessages()`. La colección se cargó al
     * empezar el turno y generar tarda segundos —con Gemini, hasta ocho vueltas de
     * herramientas—; en ese hueco cabe de sobra que un operador conteste desde el panel. Con la
     * colección en memoria no se le veía, y el bot encolaba su respuesta encima: dos respuestas
     * a la vez, y el bot pudiendo contradecir a la persona.
     *
     * Es el mismo motivo por el que `hayMensajePosterior()` consulta en fresco, y la misma
     * trampa del `uuid` explícito: sin el tipo, el parámetro no convierte a binario y la
     * consulta devuelve cero filas EN SILENCIO — que aquí significaría «no hay humano».
     */
    private function hayHumanoAtendiendo(MessageConversation $conversacion): bool
    {
        $desde = new DateTimeImmutable(self::HUMANO_AL_MANDO);

        $cuantos = (int) $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.conversation = :conversacion')
            ->andWhere('m.senderType = :persona')
            ->andWhere('m.status != :cancelado')
            ->andWhere('COALESCE(m.scheduledAt, m.createdAt) >= :desde')
            ->setParameter('conversacion', $conversacion->getId(), 'uuid')
            ->setParameter('persona', Message::SENDER_HOST)
            ->setParameter('cancelado', Message::STATUS_CANCELLED)
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getSingleScalarResult();

        return $cuantos > 0;
    }

    /**
     * ¿El huésped ha escrito algo DESPUÉS de este mensaje?
     *
     * Es el corazón de la agrupación por ráfaga. Se consulta dos veces por turno: antes de
     * llamar al modelo (descarta los trozos intermedios) y justo antes de encolar la
     * respuesta (por si escribió mientras el modelo generaba).
     *
     * Dos decisiones que parecen detalles y no lo son:
     *
     * - **Se consulta a la tabla, no a `$conversacion->getMessages()`.** Entre el primer
     *   guardia y el final del turno pasan segundos —la llamada al modelo— y puede haber otro
     *   worker persistiendo mensajes nuevos. Lo que Doctrine tenga cargado en memoria ya no
     *   describe la realidad.
     * - **Manda `createdAt`, y el UUID sólo desempata.** No son lo mismo: en el flujo real
     *   `createdAt` NO es la hora de inserción, sino la que trae el mensaje del canal
     *   (`WhatsappMetaReceivePersister::…setCreatedAt($msgDate)`), o sea cuándo escribió el
     *   huésped. Esa es la verdad que interesa. Pero viene con precisión de segundo, y en una
     *   ráfaga es normal que dos caigan en el mismo: entonces ninguno sería «posterior» al
     *   otro y contestarían LOS DOS. Ahí entra el id, que es UUIDv7 y lleva milisegundos
     *   dentro, dando el orden de inserción — que es justo el criterio bueno para decidir
     *   cuál de los dos trabajos debe sobrevivir.
     *
     *   ⚠️ No se puede ordenar sólo por UUID: en los mensajes importados en bloque el id se
     *   generó al insertar y no guarda relación con la fecha real. Hay casos en la BD con
     *   UUID mayor y fecha anterior.
     */
    private function hayMensajePosterior(MessageConversation $conversacion, Message $entrante): bool
    {
        $referencia = $entrante->getCreatedAt();
        $actual = $entrante->getId();
        if ($referencia === null || $actual === null) {
            return false;
        }

        $candidatos = $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->andWhere('m.conversation = :conversacion')
            ->andWhere('m.senderType = :huesped')
            ->andWhere('m.status != :cancelado')
            // Igual, no mayor: los del mismo segundo son justo los que hay que desempatar.
            ->andWhere('m.createdAt >= :desde')
            // ⚠️ El id va con su tipo EXPLÍCITO. Pasar la entidad —o el UuidV7 a secas— no
            // convierte a binario, y esto no falla: devuelve CERO filas en silencio. Así se
            // coló en la primera versión, que en la prueba real contestó al «hola» y descartó
            // la pregunta de verdad. `findBy()` no sufre esto porque el persister sí conoce
            // el tipo del identificador; el QueryBuilder no lo infiere.
            ->setParameter('conversacion', $conversacion->getId(), 'uuid')
            ->setParameter('huesped', Message::SENDER_GUEST)
            ->setParameter('cancelado', Message::STATUS_CANCELLED)
            ->setParameter('desde', $referencia)
            ->getQuery()
            ->getResult();

        foreach ($candidatos as $candidato) {
            $cuando = $candidato->getCreatedAt();
            if ($cuando === null || $cuando < $referencia) {
                continue;
            }

            if ($cuando > $referencia) {
                return true;
            }

            // Mismo segundo: decide el orden de inserción que lleva dentro el UUIDv7.
            $otro = $candidato->getId();
            if ($otro !== null && $otro->compare($actual) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Hay ya una respuesta automática posterior al mensaje del huésped?
     */
    private function yaSeRespondio(MessageConversation $conversacion, Message $entrante): bool
    {
        $referencia = $entrante->getCreatedAt();
        if ($referencia === null) {
            return false;
        }

        foreach ($conversacion->getMessages() as $m) {
            if ($m->getSenderType() !== Message::SENDER_SYSTEM
                || $m->getDirection() !== Message::DIRECTION_OUTGOING) {
                continue;
            }

            if ($m->getStatus() === Message::STATUS_CANCELLED) {
                continue;
            }

            // 📅 UNA PLANTILLA PROGRAMADA A FUTURO NO ES UNA RESPUESTA.
            //
            // Este guardia existe para que el bot no conteste dos veces si su propio trabajo se
            // reintenta. Pero contaba como «ya respondido» CUALQUIER mensaje de sistema con
            // `createdAt` posterior al del huésped — y los del motor de reglas se crean con la
            // hora de inserción aunque salgan dentro de tres días.
            //
            // Bastaba con que alguien tocase la reserva durante la espera de ráfaga, o con que
            // el propio mensaje entrante disparase el resync de reglas, para que naciera un
            // recordatorio programado y el bot se callase ante una pregunta real. El huésped no
            // recibe nada y sólo queda el no leído.
            if ($m->isScheduledForFuture()) {
                continue;
            }

            $cuando = $m->getCreatedAt();
            if ($cuando !== null && $cuando >= $referencia) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pide la respuesta al motor. Aquí no hay SDK: sólo el contexto del huésped y la política
     * de cuándo su respuesta vale.
     */
    private function generar(MessageConversation $conversacion, Message $entrante): ?string
    {
        $origen = (string) ($entrante->getChannel()?->getId() ?? 'chat');

        // ¿Escribe alguien del equipo desde su móvil, o un huésped?
        //
        // Lo decide el NÚMERO: si está registrado en `user.telefono`, el actor lleva los
        // roles de esa persona y el agente le contesta con las skills de operación —quién
        // sale mañana, cuánto debe la casita 2—. Sin número registrado es un huésped, y sus
        // skills quedan acotadas a SU reserva por el contexto de la conversación.
        //
        // Este cable faltaba: `UserRepository::findByTelefono()` y
        // `AgentActor::delEquipoPorChat()` existían desde el principio y no los llamaba
        // nadie, así que TODO el que escribía por WhatsApp era huésped. Un operador
        // preguntando por las salidas del día recibía «no tengo acceso a esa información».
        //
        // ⚠️ Esto convierte el número de teléfono en una credencial. Es aceptable porque
        // `permitirEscritura` sigue en `false` más abajo: por este canal solo se consulta,
        // nunca se modifica. Si algún día se abre la escritura aquí, hay que replantear la
        // identificación — un número se puede perder con el teléfono.
        $delEquipo = $this->usuarios->findByTelefono($conversacion->getGuestPhone(), $this->telefonos);

        // `tambienHuesped: true` — los privilegios se ACUMULAN. Alguien del equipo con una
        // reserva a su nombre es las dos cosas a la vez: debe poder consultar su propia
        // estancia sin dejar de ser operador. Las skills de huésped siguen acotadas por el
        // contexto de ESTA conversación, así que sumar el rol no le abre la reserva de nadie.
        // Y si no es del equipo: ¿es huésped nuestro, o alguien que aún no es nadie?
        //
        // Lo decide tener una RESERVA detrás. Un número desconocido escribiendo a preguntar
        // precios no tiene ninguna, y hasta ahora se le trataba como huésped: se le daban las
        // nueve skills del huésped, de las que siete mueren en «Esta conversación no está
        // asociada a ninguna reserva» y ninguna sabe de disponibilidad ni de tarifas. Escribir
        // para preguntar cuánto cuesta una casita era chocar contra una pared.
        //
        // `ROLE_PROSPECTO` invierte eso: menos acceso a lo privado —no hay reserva que
        // consultar— y a cambio lo que un desconocido sí puede saber, que es justo lo que hay
        // que contestarle para venderle algo.
        // El hilo va a los tres por igual: es enrutado, no permiso. Al prospecto le importa
        // especialmente —nace sin contexto a propósito, y sin el hilo `escalar_al_equipo` no
        // encontraba la conversación y todo lead que pedía hablar con alguien moría ahí.
        $conversacionId = $conversacion->getId()?->toRfc4122();

        // Tener contexto NO significa ser cliente: una consulta de OTA cuelga de una reserva
        // igual que una estancia confirmada, y distinguirlas es justo lo que faltaba.
        //
        // Se lee del snapshot y no de la entidad viva porque para esta ruta es lo mismo:
        // `Beds24ReceivePersister` llama a `upsertFromContext()` al recibir cada mensaje, así
        // que acaba de reescribirse cuando llegamos aquí. El fallback a `deStatusTag()` cubre
        // las conversaciones anteriores al campo, cuyo upsert todavía no ha vuelto a correr.
        $vinculo = $conversacion->getContextVinculo()
            ?? VinculoComercial::deStatusTag($conversacion->getContextStatusTag());

        $restriccion = RestriccionCanal::deOrigenYVinculo($conversacion->getContextOrigin(), $vinculo);

        // 🌍 Prospecto es «no hay vínculo», NO «su contexto no es una reserva del PMS».
        //
        // La versión anterior comparaba contra `CONTEXTO_RESERVA`, y con eso el primer módulo
        // que creara conversaciones con otro `context_type` —tours, soporte— habría visto a
        // sus clientes tratados como desconocidos: sin contexto, sin skills acotadas y con el
        // prompt de venta. El fallo habría sido silencioso, porque nada de eso revienta.
        //
        // Preguntándoselo al vínculo, cada dominio decide por su cuenta y esto deja de tener
        // opinión sobre qué módulos existen. Para lo que hay hoy el resultado es idéntico:
        // las conversaciones `manual` y `staff` no traen vínculo y siguen siendo prospectos.
        $esProspecto = $delEquipo === null && VinculoComercial::Ninguno === $vinculo;

        $actor = match (true) {
            $delEquipo !== null => $this->actores->delEquipoPorChat($delEquipo, $origen, $conversacion->getContextType(), $conversacion->getContextId(), tambienHuesped: true, conversacionId: $conversacionId),
            $esProspecto => $this->actores->prospecto($origen, $conversacionId),
            default => $this->actores->huesped($origen, $conversacion->getContextType(), $conversacion->getContextId(), $conversacionId, $vinculo, $restriccion),
        };

        if (!$esProspecto && $delEquipo === null && VinculoComercial::Cliente !== $vinculo) {
            $this->logger->info(sprintf(
                'IA: %s tiene contexto %s pero vínculo «%s»%s; se le atiende como INTERESADO.',
                $conversacion->getGuestPhone() ?? '(sin número)',
                $conversacion->getContextType(),
                $vinculo->etiqueta(),
                $restriccion->restringe() ? ' y canal restringido' : ''
            ));
        }

        if ($esProspecto) {
            $this->logger->info(sprintf(
                'IA: %s no tiene reserva ni es del equipo; se le atiende como PROSPECTO.',
                $conversacion->getGuestPhone() ?? '(sin número)'
            ));
        }

        if ($delEquipo !== null) {
            $this->logger->info(sprintf(
                'IA: %s identificado como equipo por su número; se le atiende con sus roles.',
                $delEquipo->getUserIdentifier()
            ));
        }

        $mensaje = $entrante->getTextoEntrante();
        $historial = $this->historial($conversacion, $entrante);

        // PASO 1 — TRIAJE. Barato, sin herramientas, y con un desenlace («indeterminado») que
        // deja todo exactamente como estaba antes de que existiera. Se le pasa el contexto
        // (idioma, nombre) porque la charla la contesta él mismo. Ver App\Agent\Triage\Triaje.
        $decision = $this->triaje->clasificar($actor, $mensaje, $historial, $this->contexto($conversacion, $actor));

        // El triaje ya leyó estos mensajes para clasificarlos, así que su resumen sale sin
        // pagar una llamada extra. Se persiste AQUÍ para que el trabajo de
        // GenerarResumenDispatch —que corre después cuando el autoresponder está encendido—
        // encuentre la marca puesta y se vaya sin llamar al modelo.
        //
        // Con el autoresponder APAGADO nada de esto corre y ResumenConversacionService lo
        // genera por su cuenta: la capacidad no depende del agente, solo se delega en él
        // cuando está disponible. Ver docs/Mensajeria.md §7.
        $this->guardarResumenDelTriaje($conversacion, $entrante, $decision);

        // PASO 2a — CHARLA. La respuesta vino EN la llamada del triaje: «hola» cuesta una sola
        // llamada, y quien vigila que no se cuele una petición es el propio clasificador. Si
        // vino sin respuesta —modelo tacaño, JSON roto—, camino largo: no se pierde el mensaje.
        if ($decision->tipo === TipoDeMensaje::Conversacion && $decision->respuesta !== null) {
            $this->logger->info(sprintf(
                'IA: charla resuelta en el propio triaje en la conversación %s: «%s».',
                $conversacion->getId(),
                $decision->respuesta
            ));

            return $decision->respuesta;
        }

        // PASO 2b — CAMINO LARGO, con el catálogo entero. El tramo de potencia lo pone lo que
        // decidió el triaje; el proveedor sale de las claves de potencia, con caída al de
        // `AGENT_IA_PROVEEDOR` si están sin configurar. El chat del huésped nunca ha elegido
        // proveedor por conversación, y sigue sin hacerlo: es configuración, no negociación.
        $elegido = $this->potencias->elegir($this->tramoPara($decision));
        if ($elegido === null) {
            return null;
        }

        $respuesta = $elegido->motor->conversar(new ConversationRequest(
            actor: $actor,
            systemPrompt: $this->reglas($actor),
            contexto: $this->contexto($conversacion, $actor, $decision),
            mensaje: $mensaje,
            historial: $historial,
            // Sólo lectura hacia fuera: una escritura disparada por un huésped tendría que
            // confirmarse, y aquí no hay a quién preguntar. Ver NivelRiesgo.
            permitirEscritura: false,
            maxTokens: 1024,
            modelo: $elegido->modelo,
        ));

        // 🔥 `sin_skill` YA NO TIRA LA RESPUESTA. Durante un tiempo sí: se cambiaba por el acuse
        // de recibo, con el razonamiento de que sin skill el texto salía del modelo y no de los
        // datos. El precio era absurdo — a «hola, soy Jorge» el modelo contesta un saludo, que
        // no necesita ninguna herramienta ni ningún dato, y el huésped recibía «un compañero te
        // responderá en breve». Un bot que no sabe devolver un saludo no parece prudente, parece
        // roto.
        //
        // Lo que hacía peligroso confiar era el volcado de la reserva en el prompt: con el saldo
        // delante, un `sin_skill` era el modelo recitando cifras de memoria. Fuera el volcado
        // ({@see self::reglas()}), lo que queda sin herramienta es cortesía: no hay datos
        // de la reserva que inventar porque no se le han dado.
        //
        // Queda un riesgo, y conviene tenerlo escrito: ante «¿a qué hora es el check-in?» el
        // modelo puede contestar de memoria en vez de mirar la guía. El freno para eso son las
        // reglas del prompt, no tirar la respuesta — tirarla nunca distinguió una cosa de la
        // otra, y se llevaba por delante las buenas.
        // Se registra con el texto entero: es lo que leyó el huésped sin que ninguna herramienta
        // lo respaldara, así que es lo que hay que poder revisar después.
        if ($respuesta->motivo === 'sin_skill' && $respuesta->tieneTexto()) {
            $this->logger->info(sprintf(
                'IA: respuesta sin skill entregada en la conversación %s: «%s».',
                $conversacion->getId(),
                trim((string) $respuesta->texto)
            ));
        }

        if ($respuesta->tieneTexto()) {
            return $respuesta->texto;
        }

        // El acuse NO hereda la huella, igual que en el `catch` de `process()`. Éste es el
        // TERCER camino al acuse y el más fácil de pasar por alto: aquí no hay excepción, se
        // devuelve el acuse como si fuera la respuesta y se encola por la vía normal. Si la guía
        // corrió antes de que el motor se quedara sin texto, la huella acabaría estampada en un
        // «un compañero te responderá» y el huésped tendría por explicado algo que nunca leyó.
        $this->escalera->limpiar();

        // Sin texto no hay nada que entregar —el motor no respondió, o los clasificadores del
        // proveedor declinaron, o al huésped le faltaban permisos—. Antes esto era silencio y
        // el huésped se quedaba mirando el chat sin saber si alguien le leyó. El acuse de
        // recibo es el suelo, no la política: se manda cuando NO hay respuesta, no cuando la
        // respuesta no nos gusta.
        $this->logger->warning(sprintf(
            'IA: sin texto (%s) en la conversación %s; se manda el acuse de recibo.',
            $respuesta->motivo,
            $conversacion->getId()
        ));

        return $this->acuseDeRecibo($conversacion);
    }

    /**
     * 🔥 AQUÍ NO VAN LOS DATOS DE LA RESERVA, y es a propósito.
     *
     * Los llevaba: un volcado de las 23 variables del resolver —fechas, importes, saldo—.
     * Parecía un atajo (el modelo contesta sin gastar una vuelta) y era una trampa, porque
     * choca de frente con el guardia de `sin_skill` de {@see self::generar()}: teniendo el
     * dato delante el modelo responde SIN llamar a ninguna herramienta, el motor lo marca
     * `sin_skill` —«respondió improvisando»— y la respuesta buena se tira a la basura para
     * mandar el acuse de recibo genérico.
     *
     * Medido en la prueba real: a «cuato estoy debiendo?» el huésped recibió «un compañero te
     * responderá en breve», teniendo el saldo escrito dos líneas más arriba en este prompt.
     * Y cuanto mejor era el volcado, más se callaba el bot.
     *
     * Así que la reserva se pide con `consultar_mi_reserva` y punto: cuesta una vuelta más,
     * pero cada dato que llega al huésped tiene detrás una llamada que se puede auditar, y
     * `sin_skill` vuelve a significar lo que dice —que no había con qué responder—.
     */
    /**
     * El prompt del sistema: un tronco común y el bloque del perfil de quien escribe.
     *
     * Era uno solo, escrito para un huésped alojado —«Hablas con un huésped por el chat de su
     * reserva»—, y por el mismo WhatsApp entran cuatro clases de persona. Contestarles a todas
     * con la misma voz falla por los dos lados: al compañero se le trata de usted y se le
     * esconden cifras que puede ver, y a quien va a limpiar se le podría soltar el saldo de un
     * huésped por no haberle dicho que no.
     *
     * ⚠️ **Esto NO sustituye a los permisos.** Lo que cada uno puede hacer lo decide
     * `SkillRegistry::paraActor()` con los roles. El perfil aporta la otra mitad —el tono y el
     * criterio— que ningún filtro puede dar. Los permisos evitan el acceso; el perfil, el
     * desliz.
     *
     * El tronco va PRIMERO y el perfil después, para que el bloque específico pueda endurecerlo
     * pero no aflojarlo: no inventar datos y no escribir de memoria un número de cuenta valen
     * para los cuatro, sin excepción.
     */
    private function reglas(ActorInterface $actor): string
    {
        $perfil = PerfilConversacion::deActor($actor);

        // El perfil dice QUIÉN escribe; el dominio, DE QUÉ va el negocio. Estaban juntos en el
        // enum y por eso cada perfil nuevo nacía hotelero. Se resuelve por `context_type` con
        // el mismo patrón de tag iterator que ya usa `MessageDataResolverRegistry`.
        $dominio = $this->dominios->para($actor->contextoTipo(), $perfil);

        return $dominio === ''
            ? $this->reglasComunes()
            : $this->reglasComunes() . "\n\n" . $dominio;
    }

    /**
     * Lo que vale para los cuatro perfiles. Aquí sólo entra lo que sería un error grave en
     * cualquiera de las cuatro conversaciones.
     */
    /**
     * Quita del texto generado la marca de fecha del historial.
     *
     * ### Por qué existe
     *
     * El historial se le da al modelo con la fecha DENTRO del texto de cada turno —`[12/08]
     * …`, ver {@see self::historial()}— y eso resolvió un fallo real: sin fecha, una cotización
     * que el propio bot dio hace una semana volvía a contexto como si fuera de hoy.
     *
     * Pero el precio fue otro fallo. Al ir dentro del texto, la marca es contenido a imitar y
     * no metadato: el modelo aprendió el patrón y, en vez de responder, **continuó la
     * transcripción escribiendo el siguiente turno DEL HUÉSPED**. Salió por el canal, en 2ª
     * persona y con su voz, un «Un favor, si le podría comentar al equipo para que me envíen
     * foto del baño… Muchas gracias!» que la huésped leyó como si se lo mandáramos a ella.
     * Entendible: no sabía a quién le hablaba el sistema.
     *
     * El prompt ya se lo pide, pero **un prompt es una petición**. Esto es el cierre: si la
     * marca aparece, se quita antes de encolar. Barato, determinista y no depende de que
     * ningún modelo obedezca.
     *
     * Sólo se toca el PRINCIPIO. Un `[12/08]` a mitad de frase puede ser una fecha legítima que
     * el huésped preguntó, y ahí el bot está citando, no imitando.
     */
    private function limpiarMarcasDeHistorial(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        return preg_replace('/^\s*\[\d{1,2}\/\d{1,2}\]\s*/u', '', $texto) ?? $texto;
    }

    private function reglasComunes(): string
    {
        // 📅 QUÉ DÍA ES HOY. Sin esto el modelo no puede resolver «del 8 al 10 de noviembre»,
        // y no falla en voz alta: elige un año, cotiza tarifas de un noviembre PASADO y suena
        // perfectamente seguro. Ocurrió en producción —79.00 y 181.00 en vez de 65.00 y
        // 141.00— y costó rato entender que los números no eran inventados, eran de 2025.
        //
        // El asistente del panel lo lleva desde siempre; este prompt no, y ésa era toda la
        // diferencia entre una cotización buena y una mala con el MISMO modelo y la MISMA
        // skill.
        $hoy = new DateTimeImmutable('now', new DateTimeZone(self::TZ_PERU));

        // Compartidas con el asistente del panel y el de voz: ver ReglasCompartidas.
        $copiar = ReglasCompartidas::DATOS_QUE_SE_COPIAN;
        $parametros = ReglasCompartidas::NO_INVENTES_PARAMETROS;

        return <<<PROMPT
        Eres el asistente de un alojamiento en Cusco, Perú, que atiende por WhatsApp.

        Hoy es {$hoy->format('Y-m-d')} ({$hoy->format('l')}), zona horaria America/Lima.
        Úsalo para resolver fechas relativas: si dicen «del 8 al 10 de noviembre» sin año, se
        refieren a la PRÓXIMA vez que ocurra esa fecha, nunca a una pasada. Ante la duda,
        pregunta el año antes de cotizar: una tarifa del año que no es parece correcta y no
        hay forma de que el cliente lo note.

        En el historial cada turno viene con su fecha delante, así: «[12/08] texto». Esa marca
        es NUESTRA, para que sepas cuándo se dijo cada cosa. NO la escribas nunca en tu
        respuesta, y no continúes la transcripción: tú contestas al huésped, no redactas su
        siguiente mensaje.

        Reglas que valen SIEMPRE, hables con quien hables:
        - Sé breve y concreto: es un chat, no un correo. Dos o tres frases bastan.
        - Responde SOLO con lo que te devuelvan las herramientas.
        - NUNCA inventes precios, disponibilidad, horarios ni políticas que no te hayan devuelto
          las herramientas. Tampoco expliques POR QUÉ se decide algo si nadie te lo ha dicho: si
          no sabes de qué depende, no lo supongas.

        {$copiar}

        {$parametros}

        ⚠️ SIEMPRE que digas que alguien va a contestar, llama a «escalar_al_equipo» en ese
        mismo turno. Si lo prometes y no la llamas, no se entera nadie y se queda esperando: es
        el peor fallo que puedes cometer aquí. Vale también si te quedas sin saber qué
        responder.

        ⚠️ Y AL REVÉS, QUE ES EL QUE SE OLVIDA: escala también cuando te falte un DATO, aunque
        no hayas prometido nada y aunque la pregunta parezca de las fáciles. Si llamaste a la
        herramienta que tocaba y no traía lo que te piden, ahí se acabó lo que puedes hacer tú.
        No rellenes el hueco con algo verosímil ni des una respuesta a medias para salir del
        paso. Una espera de diez minutos se perdona; un número de cuenta equivocado, no.

        - No prometas plazos («enseguida», «en 5 minutos»): no sabes cuándo van a leerlo.
        - No menciones que eres una IA salvo que te lo pregunten directamente.
        PROMPT;
    }


    /**
     * Qué tramo de potencia atiende el camino largo, según lo que dijo el triaje.
     *
     * - **Emergencia → Alta.** Es el único caso donde el precio no entra en la conversación. Lo
     *   que hay que hacer está claro (avisar al equipo), pero cómo se le habla a alguien que
     *   está asustado no lo está, y son cuatro mensajes al año: ahorrar ahí no ahorra nada.
     * - **Petición con skill conocida → lo que diga la skill.** Su
     *   {@see \App\Agent\Skill\SkillDefinition::$siguientePaso} sabe cuánto trabajo queda por
     *   hacer después de elegirla mejor que ninguna regla general.
     * - **Todo lo demás → Media**, que es lo que hace hoy el agente entero. Incluye el
     *   `indeterminado`: si el triaje no supo, no se toca nada.
     */
    private function tramoPara(DecisionDeTriaje $decision): PotenciaRequerida
    {
        if ($decision->tipo === TipoDeMensaje::Emergencia) {
            return PotenciaRequerida::Alta;
        }

        if ($decision->skill !== null) {
            $skill = $this->skills->buscar($decision->skill);

            if ($skill !== null) {
                return $skill->definicion()->siguientePaso;
            }
        }

        return PotenciaRequerida::Media;
    }

    /**
     * Lo único que cambia de una conversación a otra. Va al final del `system` y SIN caché.
     *
     * Son dos líneas a propósito: cada token que se ponga aquí se paga entero en cada consulta,
     * mientras que lo de {@see self::reglas()} lo pagó ya otra conversación. Si algún día hace
     * falta más contexto por huésped, piénsalo dos veces — y si es un dato de su reserva, va en
     * una skill, no aquí.
     *
     * Lo que añade el triaje ({@see self::pistaDelTriaje()}) son otras dos líneas como mucho, y
     * también van aquí, sin caché: cambian con cada mensaje por definición.
     */
    private function contexto(
        MessageConversation $conversacion,
        ActorInterface $actor,
        ?DecisionDeTriaje $decision = null
    ): string {
        $idioma = $conversacion->getIdioma()?->getId() ?? 'es';
        $huesped = $conversacion->getGuestName() ?? 'el huésped';

        // 🏷️ Lo que hay que saber de este negocio ahora mismo: en alojamiento, en qué punto
        // va la estancia y qué pasa alrededor de sus fechas. Lo calcula el DOMINIO — antes
        // vivía aquí, y para ello este servicio importaba `PmsReserva`, inyectaba
        // `PmsEspacioEstancia` y comparaba `context_type` contra `'pms_reserva'` a pelo.
        $dominio = $this->dominios->contextoVolatil($conversacion);

        // 🎭 Quién escribe. Va aquí y no en las reglas porque el prompt del triaje es idéntico
        // byte a byte en todas las conversaciones —es el bloque cacheado— y esto es lo volátil.
        // Sin ello, el triaje resolvía una charla de un compañero del equipo con la voz del
        // huésped: «¿en qué te puedo ayudar con tu reserva?». Visto en producción.
        $quien = PerfilConversacion::deActor($actor)->enUnaLinea();
        $quien = $quien === '' ? '' : "\n" . $quien;

        // 🚧 Qué NO puede salir por este tubo. Va también en lo volátil y por el mismo motivo:
        // depende de la conversación, no del prompt común.
        //
        // ⚠️ Esto NO es lo que impide la fuga —el filtro de verdad está en la fuente, que
        // descarta el contenido antes de que exista el turno—. Esto es para que el modelo
        // sepa EXPLICARLO: sin el aviso, al no encontrar la dirección se la inventa o promete
        // que «el equipo te la envía», que es exactamente lo que hizo cinco veces seguidas.
        $limites = $actor->restriccion()->avisoParaElPrompt();
        $limites = $limites === '' ? '' : "\n" . $limites;

        // 📚 Los temas sobre los que HAY respuesta escrita ({@see ConocimientoGenerico}).
        //
        // Va aquí, en lo volátil, y no en el prompt cacheado: la tabla se alimenta desde el panel
        // cada vez que alguien nota una pregunta repetida, y meterla en el prefijo obligaría a
        // invalidar la caché de todas las conversaciones cada vez que se añade una categoría.
        //
        // ⚠️ Sin este bloque la skill de conocimiento MIENTE: su descripción le dice al modelo
        // «en el contexto de este turno tienes la lista de TEMAS», así que se inventaba una
        // categoría verosímil, no existía, y la skill respondía «no hay nada escrito, no insistas
        // con otro tema: escala». Una pregunta CON respuesta escrita acababa en escalado.
        $temas = $this->conocimiento->bloqueDeCategorias();
        $temas = $temas === '' ? '' : "\n" . $temas;

        $contexto = <<<CONTEXTO
        Hablas con {$huesped}.{$dominio}{$quien}{$limites}{$temas}
        Responde SIEMPRE en el idioma con código "{$idioma}".
        CONTEXTO;

        $pista = $decision === null ? '' : $this->pistaDelTriaje($decision);

        return $pista === '' ? $contexto : $contexto . "\n" . $pista;
    }

    /**
     * Lo que el triaje le cuenta al modelo del camino largo.
     *
     * ⚠️ **Está redactado como una sugerencia y no como una orden, y eso es el diseño, no un
     * descuido.** El triaje ve el mensaje pero no lo que devolverán las herramientas; quien
     * responde ve las dos cosas. Si la pista viniera como «USA consultar_guia», un triaje
     * equivocado arrastraría al modelo bueno a una skill que no toca, y el error no aparecería
     * en ningún sitio salvo en la respuesta al huésped.
     *
     * La emergencia es la excepción y va imperativa: ahí el coste de no hacer nada es peor que
     * el de avisar de más.
     */
    private function pistaDelTriaje(DecisionDeTriaje $decision): string
    {
        if ($decision->tipo === TipoDeMensaje::Emergencia) {
            return 'ESTO PARECE UNA EMERGENCIA. Llama a «escalar_al_equipo» en este mismo turno, '
                . 'cuéntale al huésped que ya has avisado y dile qué puede hacer mientras tanto '
                . 'si lo sabes. No le pidas que espere sin más y no prometas plazos.';
        }

        // El tema exacto vale más que la skill: con él, consultar_guia(tema_id) trae el
        // contenido a la primera, sin la vuelta de pedir el catálogo. Ya viene validado
        // contra la casita del huésped ({@see \App\Agent\Triage\Triaje}).
        if ($decision->temaId !== null) {
            return sprintf(
                'Una revisión previa del mensaje sugiere que la guía responde a esto: llama a '
                    . '«consultar_guia» con tema_id «%s»%s. Es una sugerencia, no una orden: '
                    . 'tienes todas tus herramientas y decides tú cuál usar, o ninguna.',
                $decision->temaId,
                $decision->pista !== null ? sprintf(' (el tema parece «%s»)', $decision->pista) : ''
            );
        }

        if ($decision->skill === null) {
            return '';
        }

        $pista = sprintf(
            'Una revisión previa del mensaje sugiere que «%s» puede responder a esto.',
            $decision->skill
        );

        if ($decision->pista !== null) {
            $pista .= sprintf(' El tema parece ser «%s».', $decision->pista);
        }

        return $pista . ' Es una sugerencia, no una orden: tienes todas tus herramientas y '
            . 'decides tú cuál usar, o ninguna.';
    }

    /**
     * «Un compañero te responde en breve», en el idioma de la conversación.
     *
     * Va escrito a mano y NO se le pide al modelo: es la respuesta que se da precisamente
     * cuando el modelo no tenía con qué responder, así que generarla sería volver a confiar
     * en lo que acaba de fallar. Además debe ser idéntica siempre — es un acuse de recibo,
     * no una conversación.
     *
     * ⚠️ Promete una persona y no avisa a ninguna: no llama a `escalar_al_equipo`. Sigue siendo
     * una promesa a medias, y ahora sale en DOS sitios —cuando el motor no devuelve texto y
     * cuando revienta a mitad de turno—, así que pesa más que antes.
     *
     * Lo que la sostiene hoy es que el mensaje entrante queda sin leer y el panel lo enseña. Lo
     * que la cerraría del todo es llamar aquí a `escalar_al_equipo`, que ya es idempotente en
     * su marca. Ver docs/Mensajeria.md §11, «Te responderá una persona».
     */
    private function acuseDeRecibo(MessageConversation $conversacion): string
    {
        $idioma = $conversacion->getIdioma()?->getId() ?? 'es';

        return match ($idioma) {
            'en' => 'Thanks for your message. A member of our team will get back to you shortly.',
            'pt' => 'Obrigado pela sua mensagem. Um membro da nossa equipa responder-lhe-á em breve.',
            'fr' => 'Merci pour votre message. Un membre de notre équipe vous répondra sous peu.',
            'it' => 'Grazie per il suo messaggio. Un membro del nostro team le risponderà a breve.',
            'de' => 'Vielen Dank für Ihre Nachricht. Ein Mitarbeiter meldet sich in Kürze bei Ihnen.',
            'nl' => 'Bedankt voor uw bericht. Een collega neemt zo spoedig mogelijk contact met u op.',
            default => 'Gracias por tu mensaje. Un compañero te responderá en breve.',
        };
    }

    /**
     * Hilo reciente en el formato neutral del motor. El huésped es `usuario`; todo lo que
     * salió del alojamiento —operador, plantilla automática o el propio bot— es `asistente`.
     *
     * El mensaje que dispara el turno NO se incluye: lo aporta la petición.
     *
     * @return list<array{rol: string, texto: string}>
     */
    private function historial(MessageConversation $conversacion, Message $entrante): array
    {
        $turnos = [];

        foreach ($conversacion->getMessages() as $m) {
            if ($m->getStatus() === Message::STATUS_CANCELLED || $m === $entrante) {
                continue;
            }

            // 🔒 Las NOTAS INTERNAS no son parte de la conversación: son lo que el equipo se
            // apunta entre sí sobre este huésped —«disputó un cargo, no ofrecer descuento»— y
            // entraban aquí como un turno del asistente, listas para que el modelo se las
            // citara al propio huésped. Hoy no hay ninguna en producción; esto cierra la
            // puerta antes de que alguien escriba la primera.
            if ($m->getSenderType() === Message::SENDER_INTERNAL) {
                continue;
            }

            $texto = $m->getTextoEntrante();
            if ($texto === '') {
                continue;
            }

            // 📅 CADA TURNO CON SU FECHA. Sin ella, una cotización que el propio bot dio hace
            // una semana entra en contexto como si fuera de ahora, y «responde SOLO con lo que
            // devuelvan las herramientas» no lo frena: aquella cifra SÍ salió de una
            // herramienta… en otro turno. Es el fallo que ya ocurrió con los precios.
            //
            // Con la fecha delante y el «hoy es» del prompt, el modelo puede ver que un dato
            // es viejo. Se le da hecho en vez de pedírselo, que es la lección de esta noche.
            $cuando = $m->getCreatedAt();

            $turnos[] = [
                'rol' => $m->getDirection() === Message::DIRECTION_INCOMING ? 'usuario' : 'asistente',
                'texto' => $cuando !== null
                    ? sprintf('[%s] %s', $cuando->format('d/m'), $texto)
                    : $texto,
            ];
        }

        return array_slice($turnos, -self::HISTORIAL_MAX);
    }

    /**
     * Crea el mensaje de respuesta. No lo envía: al persistirlo, el
     * MessageEnqueuerEntityListener genera las colas del canal por el que llegó la consulta.
     */
    private function encolarRespuesta(MessageConversation $conversacion, Message $entrante, string $texto): void
    {
        $canal = $entrante->getChannel();

        $salida = new Message();
        $salida->setConversation($conversacion);
        $salida->setChannel($canal);
        $salida->setTransientChannels($canal !== null ? [(string) $canal->getId()] : []);
        $salida->setDirection(Message::DIRECTION_OUTGOING);
        $salida->setSenderType(Message::SENDER_SYSTEM);
        $salida->setStatus(Message::STATUS_PENDING);
        // Sólo el EXTERNAL: el bot escribe en el idioma del huésped —lee su mensaje original,
        // no la traducción— y el español lo pone `MessageTranslator` en prePersist, para que el
        // operador lea el chat en su idioma. Rellenar los dos aquí hacía que el traductor se
        // saltara el mensaje y el panel mostrara la respuesta del asistente en inglés.
        $salida->setContentExternal($texto);
        $salida->setLanguageCode($conversacion->getIdioma()?->getId() ?? 'es');
        $salida->addMetadata('generado_por', 'ia');

        // 🪜 La huella del tema, si en este turno se sirvió uno con escalera. Es lo que permite
        // que la próxima vez que vuelva sobre lo mismo se le dé el paso siguiente en vez de la
        // misma explicación. Vive en el mensaje y no en la conversación a propósito: la fusión
        // de hilos por contacto (§20) se llevaría por delante un contador agregado, mientras que
        // los mensajes viajan enteros con su metadata. Ver EscaleraDeTemas.
        // ⚠️ LA LISTA ENTERA, EN UNA SOLA CLAVE. `addMetadata()` sobrescribe, así que estampar
        // una clave por vuelta dejaba sólo el último tema — y la lista existe justamente para que
        // «no hay agua caliente y el calefactor no prende» no pierda la escalera de la ducha.
        $servidos = $this->escalera->tomarServido();

        if ($servidos !== []) {
            $salida->addMetadata('temas_peldano', $servidos);
        }

        $conversacion->addMessage($salida);
        $this->em->persist($salida);

        $this->logger->info(sprintf(
            'IA: respuesta encolada para la conversación %s por el canal %s.',
            $conversacion->getId(),
            $canal?->getId() ?? '¿?'
        ));
    }
}
