<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Access\AgentActor;
use App\Agent\Access\AgentActorFactory;
use App\Agent\Conversation\AgentEngineRegistry;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\PotenciaRequerida;
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
        private PhoneSanitizer $telefonos,
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

        try {
            $respuesta = $this->generar($conversacion, $message);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'IA: fallo generando respuesta para la conversación %s: %s',
                $conversacion->getId(),
                $e->getMessage()
            ));

            return 'error_ia';
        }

        if ($respuesta === null || trim($respuesta) === '') {
            return 'ia_sin_respuesta';
        }

        // SEGUNDA MIRADA A LA RÁFAGA. Generar tarda segundos, y en ese hueco el huésped ha
        // podido añadir algo que cambia la pregunta («¿cuánto cuesta?» → «da igual, ya lo vi»).
        // Mandar ahora esta respuesta es contestar a destiempo; se tira, y el trabajo del
        // mensaje nuevo responderá a todo junto. Es lo único que cuesta dinero de la ráfaga:
        // el modelo ya se pagó. Preferible a que el huésped lea una respuesta desfasada.
        if ($this->hayMensajePosterior($conversacion, $message)) {
            $this->logger->info(sprintf(
                'IA: respuesta descartada en la conversación %s; el huésped escribió mientras se generaba.',
                $conversacion->getId()
            ));

            return 'rafaga_superada_al_responder';
        }

        $this->encolarRespuesta($conversacion, $message, $respuesta);

        return 'ia';
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

    private function hayHumanoAtendiendo(MessageConversation $conversacion): bool
    {
        $desde = new DateTimeImmutable(self::HUMANO_AL_MANDO);

        foreach ($conversacion->getMessages() as $m) {
            if ($m->getSenderType() !== Message::SENDER_HOST) {
                continue;
            }

            $cuando = $m->getScheduledAt() ?? $m->getCreatedAt();
            if ($cuando !== null && $cuando >= $desde) {
                return true;
            }
        }

        return false;
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
        $actor = $delEquipo !== null
            ? $this->actores->delEquipoPorChat($delEquipo, $origen, $conversacion->getContextType(), $conversacion->getContextId(), tambienHuesped: true)
            : $this->actores->huesped($origen, $conversacion->getContextType(), $conversacion->getContextId());

        if ($delEquipo !== null) {
            $this->logger->info(sprintf(
                'IA: %s identificado como equipo por su número; se le atiende con sus roles.',
                $delEquipo->getUserIdentifier()
            ));
        }

        $mensaje = trim((string) $entrante->getContentExternal());
        $historial = $this->historial($conversacion, $entrante);

        // PASO 1 — TRIAJE. Barato, sin herramientas, y con un desenlace («indeterminado») que
        // deja todo exactamente como estaba antes de que existiera. Se le pasa el contexto
        // (idioma, nombre) porque la charla la contesta él mismo. Ver App\Agent\Triage\Triaje.
        $decision = $this->triaje->clasificar($actor, $mensaje, $historial, $this->contexto($conversacion));

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
            systemPrompt: $this->reglas(),
            contexto: $this->contexto($conversacion, $decision),
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
    private function reglas(): string
    {
        return <<<PROMPT
        Eres el asistente de reservas de un alojamiento en Cusco, Perú. Hablas con un huésped
        por el chat de su reserva.

        NO TIENES NINGÚN DATO DE SU RESERVA EN ESTE MENSAJE. Ni fechas, ni importes, ni cuál es
        su casita. Están en las herramientas, y hay que pedirlos:
        - «consultar_mi_reserva» trae lo suyo: cuándo entra, CUÁNDO SALE, su casita, noches,
          localizador, total, pagado y SALDO PENDIENTE, y los enlaces a su guía y al catálogo.
        - «consultar_cuenta» trae el desglose: cada cargo y cada pago por separado, y cuánto
          sale pagar el saldo con tarjeta. Es la de «¿por qué me cobráis esto?» y «¿cómo pago?».
        Cuánto debe y cuándo sale son las dos cosas que más se preguntan: llama a la herramienta
        y dale la cifra y la fecha exactas. Nunca las estimes ni digas que no puedes verlas.

        Reglas:
        - Sé breve y concreto: es un chat, no un correo. Dos o tres frases bastan.
        - Responde SOLO con lo que te devuelvan las herramientas. Su guía es la de SU casita y
          no es igual en todas.
        - NUNCA inventes precios, disponibilidad, horarios ni políticas que no te hayan devuelto
          las herramientas. Tampoco expliques POR QUÉ se decide algo si nadie te lo ha dicho: si
          no sabes de qué depende, no lo supongas.

        DISTINGUE SIEMPRE ENTRE PREGUNTAR Y PEDIR:
        - PREGUNTAR es querer SABER algo que ya está escrito («¿cuánto debo?», «¿cuándo salgo?»,
          «¿a qué hora es el check out?», «¿cómo funciona la ducha?»). Eso lo respondes tú:
          consulta su reserva o su guía y dale el dato.
        - PEDIR es querer que PASE algo que depende de nosotros: salir más tarde, entrar antes,
          cambiar fechas, un servicio extra, una avería, un cobro que no cuadra, una queja.
          Eso NO lo decides tú. Ni siquiera cuando su guía explique las condiciones: que diga
          «sujeto a disponibilidad y con coste» te deja contarle las condiciones, pero nadie ha
          mirado todavía si se puede. Cuéntale lo que dice su guía y AVISA AL EQUIPO.

        - ⚠️ SI YA SE LO EXPLICASTE Y VUELVE A INSISTIR, no repitas la explicación: mira el
          historial. Que diga otra vez «sigue sin funcionar» o «ya lo probé» significa que las
          instrucciones no eran el problema — es una AVERÍA y necesita que alguien vaya. Discúlpate,
          no le hagas repetir la comprobación y avisa al equipo. Repetir lo mismo dos veces es lo
          que más enfada a quien ya tiene un problema.
        - ⚠️ SIEMPRE que le digas que alguien le va a contestar, llama a «escalar_al_equipo» en
          ese mismo turno. Si lo prometes y no la llamas, no se entera nadie y se queda
          esperando: es el peor fallo que puedes cometer aquí. Vale también si te quedas sin
          saber qué responder.
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
    private function contexto(MessageConversation $conversacion, ?DecisionDeTriaje $decision = null): string
    {
        $idioma = $conversacion->getIdioma()?->getId() ?? 'es';
        $huesped = $conversacion->getGuestName() ?? 'el huésped';

        $contexto = <<<CONTEXTO
        Hablas con {$huesped}.
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
     * ⚠️ Promete una persona y no avisa a ninguna: no llama a `escalar_al_equipo`. Se sostiene
     * porque ahora sale poquísimo —sólo cuando el motor no devuelve texto—, pero mientras siga
     * así es una promesa a medias. Ver docs/Mensajeria.md §11, «Te responderá una persona».
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

            $texto = trim((string) ($m->getContentExternal() ?? $m->getContentLocal() ?? ''));
            if ($texto === '') {
                continue;
            }

            $turnos[] = [
                'rol' => $m->getDirection() === Message::DIRECTION_INCOMING ? 'usuario' : 'asistente',
                'texto' => $texto,
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

        $conversacion->addMessage($salida);
        $this->em->persist($salida);

        $this->logger->info(sprintf(
            'IA: respuesta encolada para la conversación %s por el canal %s.',
            $conversacion->getId(),
            $canal?->getId() ?? '¿?'
        ));
    }
}
