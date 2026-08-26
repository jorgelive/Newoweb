<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Service\ConocimientoGenerico;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Aviso\AvisoAlEquipo;
use App\Message\Service\Aviso\AvisoAlEquipoService;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Deja constancia de que un huésped se quedó esperando, y avisa por WhatsApp a la guardia.
 *
 * ### El problema que resuelve
 *
 * «Lo consulto con el equipo y te aviso» era **sólo texto**. El agente lo decía —a veces porque
 * una skill se lo sugería, a veces por su cuenta— y no pasaba nada más: ni marca, ni aviso, ni
 * forma de preguntarle luego al sistema qué conversaciones tenían algo prometido. La promesa se
 * cumplía porque alguien miraba el chat, no porque el sistema la recordara.
 *
 * Ahora el escalado es un HECHO con dos efectos, y en este orden a propósito:
 *
 * 1. **La conversación queda sin leer** (`incrementUnreadCount`). Es lo que sobrevive aunque
 *    falle todo lo demás: aparece en el panel como pendiente y no depende de ninguna red.
 * 2. **Se avisa por WhatsApp** a quien tenga {@see Roles::CUSTOMER_SUPPORT} y móvil registrado,
 *    con el nombre del huésped, lo que pidió y el enlace directo a la conversación.
 *
 * Si el aviso no sale, la marca sigue puesta y la respuesta lo dice. Nunca al revés: prometer
 * que se avisó cuando no se avisó es peor que no avisar.
 *
 * ### ⚠️ La ventana de 24 h de Meta
 *
 * WhatsApp sólo deja mandar texto libre a quien te escribió en las últimas 24 h; fuera de eso
 * exige una plantilla aprobada por Meta ({@see \App\Message\Service\Queue\WhatsappMetaSendEnqueuer}).
 * Un operador de guardia **no escribe al número del negocio todos los días**, así que lo normal
 * es estar fuera de ventana.
 *
 * Por eso el aviso sale de dos formas según dónde caiga, y son distintas a propósito:
 *
 * | | Cómo sale | Qué lleva |
 * |---|---|---|
 * | **Dentro de ventana** | Texto libre | Motivo, **resumen IA de lo sin contestar**, márgenes de ocupación y enlace, multilínea |
 * | **Fuera de ventana** | Plantilla `aviso_escalado_interno` | Huésped, motivo **+ resumen** en `{{motivo}}`, y el botón al chat |
 *
 * El resumen IA (`MessageConversation::$resumenIa`) entra DENTRO de `{{motivo}}` en el camino
 * de la plantilla y no como variable nueva: añadir un `{{resumen}}` obligaría a re-aprobar la
 * plantilla en Meta, con días de espera. Ver {@see self::motivoConResumen()}.
 *
 * La plantilla lleva menos porque **Meta no admite saltos de línea en los parámetros**, y el
 * bloque de márgenes es multilínea. No se pierde nada importante: el operador toca el botón y
 * lo ve en el chat.
 *
 * Si la plantilla todavía no está aprobada por Meta, el encolado falla y la marca de no leído es
 * la que garantiza que el caso no se pierde. Eso NO se da por bueno: el fallo se detecta después
 * del flush leyendo el estado del mensaje y el operador aparece en `no_avisados`.
 *
 * ### 🔌 Aquí se decide QUÉ se dice; el cómo lo hace {@see AvisoAlEquipoService}
 *
 * Encontrar el hilo del operador, elegir entre texto libre y plantilla según la ventana, no
 * dejar que un destinatario roto tape a los demás y comprobar el encolado tras el flush **ya no
 * viven en esta clase**: salieron a `src/Message/Service/Aviso/` cuando apareció el segundo
 * interesado en avisar a la guardia (los cobros por pasarela). La tabla de arriba sigue siendo
 * verdad, pero quien la aplica es el servicio.
 *
 * Lo que se quedó aquí es lo que es del escalado y de nadie más: el enfriamiento, la columna
 * `escaladoDe`, el resumen IA dentro de `{{motivo}}`, los márgenes de ocupación y dejar la
 * conversación sin leer.
 */
final readonly class EscalarAlEquipoSkill implements SkillInterface, SkillDominioInterface
{
    /** Techo del motivo. Es un aviso para leer en el móvil, no un informe. */
    private const int MAX_MOTIVO = 400;

    /**
     * La plantilla oficial del aviso interno, para cuando la ventana de 24 h está cerrada.
     *
     * Se busca por código y no se inyecta: si falta —entorno recién montado, plantilla borrada—
     * el escalado tiene que seguir funcionando en modo degradado, no reventar.
     */
    private const string PLANTILLA_AVISO = 'aviso_escalado_interno';

    /**
     * Cuánto se espera antes de volver a hacer sonar los teléfonos por la misma conversación.
     *
     * Media hora, la misma ventana que `HUMANO_AL_MANDO` en el procesador y por el mismo motivo:
     * es el tiempo en el que se da por hecho que quien está de guardia ya lo tiene delante. Más
     * corto no ahorra nada —el huésped insiste en minutos—; más largo se come el aviso de un
     * problema distinto que aparece en la misma conversación.
     */
    private const string ENFRIAMIENTO = '-30 minutes';

    public function __construct(
        private EntityManagerInterface $em,
        private ParameterBagInterface $params,
        private PmsDisponibilidadService $disponibilidad,
        private LoggerInterface $logger,
        private ConocimientoGenerico $conocimiento,
        // Quién hace sonar los teléfonos. Aquí sólo se decide QUÉ se dice y CUÁNDO callar.
        private AvisoAlEquipoService $avisos,
    ) {}

    public function nombre(): string
    {
        return 'escalar_al_equipo';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Avisa a una persona del equipo de que este huésped necesita '
                . 'respuesta, y deja su conversación marcada como pendiente. ÚSALA SIEMPRE QUE '
                . 'LE DIGAS AL HUÉSPED QUE ALGUIEN LE VA A CONTESTAR: si prometes que lo '
                . 'consultas y no me llamas, nadie se entera y el huésped se queda esperando. '
                . 'LA CLAVE ES SI PREGUNTA O SI PIDE. Preguntar es querer saber algo que ya '
                . 'está escrito («¿a qué hora es el check out?», «¿cómo va la ducha?»): eso lo '
                . 'respondes tú con su guía y NO me llamas. Pedir es querer que pase algo que '
                . 'depende de nosotros —salir más tarde, entrar antes, cambiar fechas, un '
                . 'servicio extra, una avería, un cobro que no cuadra—: eso SIEMPRE se escala, '
                . 'AUNQUE SU GUÍA EXPLIQUE LAS CONDICIONES. Que la guía diga «sujeto a '
                . 'disponibilidad y con coste» te deja contarle las condiciones, pero no te '
                . 'autoriza a concedérselo: nadie ha mirado si esa noche está libre. Al revés: '
                . 'si su guía dice que hay que coordinarlo, es la prueba de que hace falta una '
                . 'persona. No me llames para saludos ni charla. En «motivo» escribe en UNA '
                . 'frase qué necesita, con lo concreto («quiere salir a las 14:00 en vez de las '
                . '10:00»), porque es lo que el operador lee antes de abrir el chat. Después de '
                . 'llamarme, dile al huésped que ya avisaste, sin prometerle un plazo.',
            parametros: [
                SkillParameter::texto('motivo', 'En una frase: qué necesita el huésped, concreto. '
                    . 'Lo lee un operador en el móvil.'),
                SkillParameter::booleano('emergencia', 'SÓLO para lo que no puede esperar: fuego, '
                    . 'fuga de gas o de agua, alguien encerrado o herido, un problema de '
                    . 'seguridad. Con esto el aviso sale aunque ya se haya avisado hace un rato. '
                    . 'Un huésped enfadado NO es una emergencia; que algo lleve días sin '
                    . 'resolverse, tampoco.'),
                SkillParameter::texto('conversacion_id', 'Sólo si hablas desde el panel sobre el '
                    . 'chat de otro. En la conversación con el huésped no hace falta.',
                    requerido: false),
            ],
        );
    }

    /**
     * **Transversal**: pedir ayuda humana no es de ningún negocio.
     *
     * Se etiquetó `['hotelero']` al marcar en bloque las 30 skills de esta carpeta, y era un
     * error con consecuencia grave: dejaba sin escalada a cualquier actor que no fuera de
     * alojamiento. Un pasajero con una emergencia no habría hecho sonar ningún teléfono, que es
     * el daño máximo que puede hacer este catálogo.
     *
     * Lo único de PMS que hay aquí es el enriquecimiento de {@see self::margenes()}, y **ya se
     * autoprotege**: si el contexto no es `pms_reserva` devuelve cadena vacía y el aviso sale
     * igual, sólo que sin esa línea extra. Cuando turismo quiera la suya —hora del tour, punto
     * de recojo— el sitio es un método por dominio, no un segundo `if` aquí dentro.
     *
     * @return list<string>
     */
    public function dominios(): array
    {
        return [];
    }

    /** El huésped la dispara sin saberlo; el equipo puede escalar desde el panel. */
    public function rolesRequeridos(): array
    {
        // Sin esto un prospecto al que no se le sabe contestar es un callejon sin
        // salida; con esto, es un aviso al equipo. Que es literalmente un lead.
        return [Roles::PROSPECTO, Roles::HUESPED, Roles::MENSAJES_SHOW];
    }

    /**
     * Escritura INTERNA: crea mensajes y hace sonar teléfonos, pero no toca la reserva ni la
     * cuenta de nadie. Ese matiz es el que la deja llegar al chat del huésped, que se abre en
     * modo sólo-lectura; declarada como `Escritura` a secas quedaba filtrada y el huésped
     * —justo quien tiene el problema— no tenía forma de avisar. Ver NivelRiesgo::Interna.
     */
    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Interna;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $motivo = trim((string) ($entrada['motivo'] ?? ''));

        if ($motivo === '') {
            return SkillResult::error(
                'Dime en «motivo» qué necesita el huésped: es lo que va a leer el operador.'
            );
        }

        $motivo = mb_substr($motivo, 0, self::MAX_MOTIVO);

        // ⚠️ Lo decide el modelo, y eso es una decisión consciente: aquí la asimetría manda. Un
        // falso positivo cuesta un WhatsApp de más; un falso negativo silencia una emergencia de
        // verdad. Ante la duda, que suene.
        $emergencia = (bool) ($entrada['emergencia'] ?? false);

        // ── La última red: ¿esto ya está contestado? ─────────────────────────
        // Buena parte de lo que se escala son preguntas repetidas cuya respuesta no cambia
        // nunca, y cada una interrumpe a una persona para decir lo mismo otra vez.
        //
        // Se comprueba AQUÍ y no confiando en que el modelo llame antes a `consultar_conocimiento`:
        // la skill existe y su descripción se lo pide, pero «pídeselo en el prompt» ya se ha
        // demostrado insuficiente más de una vez. Esto no bloquea el escalado —si el operador
        // debe enterarse, se entera igual— sino que devuelve la respuesta junto al aviso, para
        // que el modelo pueda contestar en el acto en lugar de dejar al huésped esperando.
        $yaEscrito = $this->conocimiento->candidatosPara($motivo, $actor);

        $conversacion = $this->conversacionDe($entrada, $actor);

        if ($conversacion === null) {
            return SkillResult::error(
                'No sé de qué conversación hablas. Localízala con localizar_conversacion.'
            );
        }

        // Si hay algo escrito, se devuelve junto al escalado. El aviso sale igual: lo que se
        // ahorra no es el aviso, es que el huésped se quede sin respuesta mientras llega.
        $sugerencia = $yaEscrito === [] ? null : [
            'hay_respuesta_escrita' => true,
            'contenido' => $yaEscrito[0]->getContenido(),
            'aviso' => 'Esto ya estaba contestado: díselo AHORA en vez de dejarlo esperando. El '
                . 'equipo queda avisado igualmente.',
        ];

        // 1️⃣ La marca PRIMERO y con su propio flush: es lo único que no depende de que Meta,
        // la red o una plantilla cooperen. Si el aviso falla después, el caso sigue visible.
        $conversacion->incrementUnreadCount();

        if ($conversacion->getStatus() !== MessageConversation::STATUS_OPEN) {
            $conversacion->setStatus(MessageConversation::STATUS_OPEN);
        }

        $this->em->flush();

        // ── ❄️ ENFRIAMIENTO ─────────────────────────────────────────────────
        // La marca de arriba ya salió: el caso está visible en el panel pase lo que pase. Lo que
        // se ahorra aquí es la SEGUNDA ronda de teléfonos.
        //
        // Hace falta porque el escalado no era idempotente entre turnos y ahora se dispara solo:
        // agotada la escalera de un tema ({@see \App\Agent\Service\EscaleraDeTemas}), cada
        // insistencia posterior vuelve a ordenar el aviso. Tres «sigue sin funcionar» en una
        // tarde eran tres tandas de WhatsApp a TODA la guardia por el mismo problema, y el
        // segundo y el tercero no le dicen nada nuevo a nadie.
        if (!$emergencia && $this->yaAvisadoHacePoco($conversacion)) {
            $this->logger->info('Escalado: enfriado, ya se avisó de esta conversación hace poco.', [
                'conversacion' => (string) $conversacion->getId(),
                'motivo' => $motivo,
            ]);

            return SkillResult::ok(array_filter([
                'marcada_pendiente' => true,
                'respuesta_ya_escrita' => $sugerencia,
                'ya_avisado' => true,
                'aviso' => 'Al equipo YA se le avisó de esto hace un momento y sigue pendiente. '
                    . 'No he vuelto a avisar. Dile que su caso está en manos del equipo y que le '
                    . 'responderán, SIN prometerle plazo y sin repetirle lo que ya le contaste. '
                    . 'Si lo que cuenta AHORA es un problema distinto y grave que no admite '
                    . 'espera, vuelve a llamarme marcando «emergencia».',
            ], static fn ($v) => $v !== null && $v !== false));
        }

        // El aviso: QUÉ se dice lo decide esta skill; a quién y por dónde, el servicio.
        //
        // `escaladoDe` viaja por el ajuste y no dentro de AvisoAlEquipo a propósito: es un dato
        // del escalado —de qué conversación de huésped viene— y meterlo en el contrato
        // obligaría a los demás avisos a cargar con un campo que no significa nada para ellos.
        // Va en COLUMNA además de en metadata porque el JSON no se consulta sin atar el código
        // a la versión de MySQL, y el enfriamiento de arriba necesita consultarlo.
        $resultado = $this->avisos->notificar(new AvisoAlEquipo(
            rol: Roles::CUSTOMER_SUPPORT,
            texto: $this->redactar($conversacion, $motivo),
            plantillaCodigo: self::PLANTILLA_AVISO,
            variables: $this->variablesDelAviso($conversacion, $motivo),
            metadata: [
                'aviso_escalado' => true,
                'escalado_de' => (string) $conversacion->getId(),
                // De QUÉ se avisó, sin releer el texto: es lo que falta cuando el enfriamiento
                // silencia un aviso y alguien pregunta después qué se calló.
                'motivo_escalado' => $motivo,
            ],
            ajustarMensaje: static function (Message $mensaje) use ($conversacion): void {
                $mensaje->setEscaladoDe((string) $conversacion->getId());
            },
        ));

        if ($resultado->sinDestinatarios) {
            // No es un error de la skill: es que nadie está de guardia. Se dice, porque el
            // modelo no debe prometerle al huésped un aviso que no ha salido.
            $this->logger->warning('Escalado sin guardia: nadie con ROLE_CUSTOMER_SUPPORT y móvil.', [
                'conversacion' => (string) $conversacion->getId(),
                'motivo' => $motivo,
            ]);

            return SkillResult::ok([
                'marcada_pendiente' => true,
                'avisados' => [],
                'advertencia' => 'He dejado la conversación marcada como pendiente, pero NO he '
                    . 'podido avisar a nadie: no hay ningún operador con el rol de atención al '
                    . 'cliente y un móvil registrado. Dile al huésped que su consulta queda '
                    . 'anotada, sin prometerle cuándo. Esto lo tiene que arreglar un '
                    . 'administrador en el panel de usuarios.',
            ]);
        }

        return SkillResult::ok(array_filter([
            'marcada_pendiente' => true,
            'respuesta_ya_escrita' => $sugerencia,
            'avisados' => $resultado->avisados,
            'no_avisados' => $resultado->noAvisados !== [] ? $resultado->noAvisados : null,
            'aviso_encolado' => $resultado->alguienFueAvisado(),
            'mensaje' => $resultado->alguienFueAvisado()
                ? 'Ya está avisado el equipo. Dile al huésped que su consulta pasó a una persona '
                    . 'y que le responderán; no le des un plazo concreto, que no lo sabes.'
                : 'La conversación queda marcada como pendiente aunque el aviso no salió. Dile '
                    . 'al huésped que queda anotada, sin prometer cuándo.',
        ], static fn ($v) => $v !== null));
    }

    /**
     * La conversación sobre la que se escala.
     *
     * 🔒 El contexto manda: si quien habla es el huésped, es SU conversación y punto. El
     * parámetro sólo sirve al equipo desde el panel, donde no hay contexto de chat.
     */
    private function conversacionDe(array $entrada, ActorInterface $actor): ?MessageConversation
    {
        $repo = $this->em->getRepository(MessageConversation::class);

        if ($actor->contextoId() !== null && $actor->contextoTipo() !== null) {
            return $repo->findOneBy([
                'contextType' => $actor->contextoTipo(),
                'contextId' => $actor->contextoId(),
            ]);
        }

        // Sin contexto pero dentro de un hilo: es el prospecto. Se le localiza por la
        // conversación en curso y no por el parámetro, que sólo sirve al equipo desde el
        // panel — un prospecto no conoce ningún UUID, y la salida que le ofrecía el error
        // (`localizar_conversacion`) exige roles de equipo y ni le aparece en la lista.
        $delHilo = $actor->conversacionId();

        if ($delHilo !== null && Uuid::isValid($delHilo)) {
            return $repo->find($delHilo);
        }

        $id = trim((string) ($entrada['conversacion_id'] ?? ''));

        return $id !== '' && Uuid::isValid($id) ? $repo->find($id) : null;
    }

    /**
     * El aviso tal como se lee en el móvil: quién, qué, y el enlace para entrar directo.
     *
     * El enlace lleva `?id=` porque es lo que la SPA usa para auto-seleccionar el chat (mismo
     * formato que el push de `MessageConversationMercureListener`): el operador toca y está
     * dentro, sin buscar en la lista.
     */
    private function redactar(MessageConversation $conversacion, string $motivo): string
    {
        $huesped = $conversacion->getGuestName() ?: 'Un huésped';
        $enlace = rtrim((string) $this->params->get('util_host_url'), '/')
            . '/chat?id=' . $conversacion->getId();

        // El resumen IA va como CONTEXTO, en su propia línea y etiquetado.
        //
        // No sustituye al motivo: el motivo es por qué el agente escala —lo escribe el
        // modelo mirando la conversación entera— y el resumen es lo que el huésped pidió
        // y sigue sin contestarse, sacado de sus mensajes reales. Cuando difieren, el que
        // manda es el resumen, porque está pegado a lo que el huésped escribió.
        // Ver docs/Mensajeria.md §7.
        $resumen = trim((string) $conversacion->getResumenIa());
        $contexto = $resumen === '' ? '' : sprintf("\n\n🗒️ Sin contestar: %s", $resumen);

        return sprintf(
            "🔔 *%s* necesita respuesta\n\n%s%s%s\n\n👉 %s",
            $huesped,
            $motivo,
            $contexto,
            $this->margenes($conversacion),
            $enlace
        );
    }

    /**
     * Encola el WhatsApp para un operador.
     *
     * Se reutiliza el mecanismo de siempre —`Message` con `transientChannels`— en vez de llamar
     * a Meta a mano: así el aviso aparece en el historial, se reintenta como cualquier otro
     * envío y no hay un segundo camino de salida que mantener.
     *
     * Cada operador tiene su propia conversación de tipo `staff`, creada la primera vez. Hace
     * falta porque `WhatsappMetaSendEnqueuer` saca el número destino de
     * `MessageConversation::$guestPhone`: sin conversación no hay a quién enviar.
     */
    /**
     * ¿A esta conversación ya se le hizo sonar el teléfono a la guardia hace poco?
     *
     * Se mira en los avisos que salieron —que llevan `escalado_de` con el id del origen—, no en
     * una marca de la conversación del huésped: así el enfriamiento se apoya en que el aviso
     * EXISTIÓ, no en que alguien recordara anotarlo. Los que fallaron no cuentan; si el aviso se
     * quedó en el sitio, hay que volver a intentarlo.
     *
     * El filtro por origen se hace en PHP a propósito: son unas pocas filas en una ventana de
     * media hora, y una consulta sobre un campo JSON ataría esto a la versión de MySQL.
     */
    private function yaAvisadoHacePoco(MessageConversation $origen): bool
    {
        // Consulta EXACTA sobre `escalado_de`. Antes se traían las 100 filas más recientes de
        // las conversaciones `staff` y se filtraba en PHP, porque el dato vivía en un JSON:
        //
        //  - la cota de 100 se llenaba con el tráfico normal justo el día de más carga, que es
        //    cuando más avisos hay — el enfriamiento fallaba abierto bajo volumen;
        //  - y la cota por `contextType` ataba esto a que el hilo del operador siguiera siendo
        //    `staff`. Al fusionar el hilo de alguien del equipo que además es huésped, sus
        //    avisos dejaban de encontrarse y la guardia volvía a sonar entera.
        //
        // Con la columna no hay tope, no hay filtro en PHP y no importa en qué hilo acabó.
        return $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.escaladoDe = :origen')->setParameter('origen', (string) $origen->getId())
            ->andWhere('m.createdAt >= :desde')->setParameter('desde', new \DateTimeImmutable(self::ENFRIAMIENTO))
            // Los que fallaron no cuentan: si el aviso se quedó en el sitio, hay que volver a
            // intentarlo.
            ->andWhere('m.status NOT IN (:muertos)')
            ->setParameter('muertos', [Message::STATUS_FAILED, Message::STATUS_CANCELLED])
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Lo que la plantilla necesita para hidratarse, y en el formato que Meta acepta.
     *
     * No las puede dar un resolver de contexto: la conversación es la del OPERADOR, y estos datos
     * hablan del huésped que está esperando. Por eso viajan en el mensaje
     * ({@see Message::setVariablesPlantilla()}).
     *
     * `chat_path` es relativo —lo mismo que `guide_path` en `PmsMessageDataResolver`— porque el
     * botón de Meta ya lleva el dominio y sólo admite el sufijo. `chat_url` es el absoluto que
     * usa el respaldo en texto libre.
     *
     * @return array<string, string>
     */
    private function variablesDelAviso(MessageConversation $conversacion, string $motivo): array
    {
        return [
            'huesped' => $this->unaLinea($conversacion->getGuestName() ?: 'Un huésped'),
            'motivo' => $this->unaLinea($this->motivoConResumen($conversacion, $motivo)),
            'chat_path' => 'chat?id=' . $conversacion->getId(),
            'chat_url' => rtrim((string) $this->params->get('util_host_url'), '/')
                . '/chat?id=' . $conversacion->getId(),
        ];
    }

    /**
     * Une el motivo del agente con el resumen IA de lo que el huésped dejó sin contestar.
     *
     * En la plantilla de Meta **no hay sitio para una variable nueva**: añadir un
     * `{{resumen}}` obligaría a volver a pasar por aprobación de Meta, con días de espera.
     * Así que el contexto viaja dentro de `{{motivo}}`, que ya existe y es libre.
     *
     * Se recorta a `MAX_MOTIVO` al final y no antes: el corte tiene que aplicarse al texto
     * ya unido, o el resultado podría pasarse del tope que la plantilla espera.
     *
     * Si no hay resumen —IA apagada, escalado sin mensaje entrante previo— devuelve el
     * motivo tal cual, que es como funcionaba antes.
     */
    private function motivoConResumen(MessageConversation $conversacion, string $motivo): string
    {
        $resumen = trim((string) $conversacion->getResumenIa());

        if ($resumen === '') {
            return $motivo;
        }

        return mb_substr(sprintf('%s · Sin contestar: %s', $motivo, $resumen), 0, self::MAX_MOTIVO);
    }

    /**
     * Aplana un valor para que Meta lo acepte como parámetro de plantilla.
     *
     * Meta rechaza el envío entero si un parámetro trae saltos de línea, tabuladores o más de
     * cuatro espacios seguidos. El motivo lo escribe un modelo, así que puede venir con lo que
     * sea; y el fallo aparecería lejos, en la respuesta de la API, sobre un aviso que ya se dio
     * por mandado.
     *
     * El valor por defecto NO es cosmético: `WhatsappMetaSendMappingStrategy` lanza excepción si
     * una variable llega vacía.
     */
    private function unaLinea(string $valor): string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', $valor));

        return $limpio !== '' ? $limpio : '—';
    }

    /**
     * La línea que le ahorra al operador abrir el calendario.
     *
     * Cuando llega «quiere salir a las 14:00», la primera pregunta del operador es siempre la
     * misma: *¿está vendida esa noche?*. Va en el propio aviso, así que puede contestar desde el
     * móvil sin entrar a mirar.
     *
     * ⚠️ Sólo para el OPERADOR. Esto no se le devuelve nunca al huésped ni entra en
     * `consultar_mi_reserva`: saber que su casita está libre mañana le hace dar por hecho que
     * puede quedarse, y eso no lo decide la disponibilidad sino una persona.
     *
     * Si falla el cálculo no se rompe el aviso: se manda sin esta línea. Que el operador reciba
     * el escalado importa más que el extra.
     */
    private function margenes(MessageConversation $conversacion): string
    {
        if ($conversacion->getContextType() !== 'pms_reserva') {
            return '';
        }

        try {
            $reserva = $this->em->getRepository(PmsReserva::class)->find($conversacion->getContextId());
            $eventos = $reserva?->getEventosActivosGuia() ?? [];

            if ($eventos === []) {
                return '';
            }

            $lineas = [];

            foreach ($eventos as $evento) {
                $m = $this->disponibilidad->margenesDe($evento);
                $casita = $evento->getPmsUnidad()?->getNombre() ?? '—';

                $lineas[] = sprintf(
                    "\n\n🏠 *%s* (%s → %s)\n   Noche anterior a su entrada: %s\n   Noche del día de su salida: %s",
                    $casita,
                    $evento->getInicio()?->format('d/m') ?? '?',
                    $evento->getFin()?->format('d/m') ?? '?',
                    $m['antes']['libre'] ? '✅ libre' : '⛔ ocupada' . ($m['antes']['ocupa'] ? ' (' . $m['antes']['ocupa'] . ')' : ''),
                    $m['despues']['libre'] ? '✅ libre' : '⛔ ocupada' . ($m['despues']['ocupa'] ? ' (' . $m['despues']['ocupa'] . ')' : ''),
                );
            }

            return implode('', $lineas);
        } catch (Throwable $e) {
            $this->logger->warning('Escalado: no se pudieron calcular los márgenes.', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
