<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\User;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Repository\UserRepository;
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
 * es estar fuera de ventana. El aviso se encola igual y el fallo queda en la cola y en el log;
 * la marca de no leído es la que garantiza que el caso no se pierde.
 *
 * Para que el WhatsApp salga siempre hace falta una plantilla oficial de aviso interno dada de
 * alta en Meta. No la hay: las 11 existentes son todas para huéspedes.
 */
final readonly class EscalarAlEquipoSkill implements SkillInterface
{
    /** Techo del motivo. Es un aviso para leer en el móvil, no un informe. */
    private const int MAX_MOTIVO = 400;

    /**
     * Tipo de contexto de las conversaciones internas con el equipo.
     *
     * No se reutiliza `manual` —el walk-in de un desconocido— porque son cosas distintas y
     * quien filtre la bandeja tiene que poder separarlas: esto no es un huésped.
     */
    private const string CONTEXTO_STAFF = 'staff';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $usuarios,
        private ParameterBagInterface $params,
        private PmsDisponibilidadService $disponibilidad,
        private LoggerInterface $logger,
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
                SkillParameter::texto('conversacion_id', 'Sólo si hablas desde el panel sobre el '
                    . 'chat de otro. En la conversación con el huésped no hace falta.',
                    requerido: false),
            ],
        );
    }

    /** El huésped la dispara sin saberlo; el equipo puede escalar desde el panel. */
    public function rolesRequeridos(): array
    {
        return [Roles::HUESPED, Roles::MENSAJES_SHOW];
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

        $conversacion = $this->conversacionDe($entrada, $actor);

        if ($conversacion === null) {
            return SkillResult::error(
                'No sé de qué conversación hablas. Localízala con localizar_conversacion.'
            );
        }

        // 1️⃣ La marca PRIMERO y con su propio flush: es lo único que no depende de que Meta,
        // la red o una plantilla cooperen. Si el aviso falla después, el caso sigue visible.
        $conversacion->incrementUnreadCount();

        if ($conversacion->getStatus() !== MessageConversation::STATUS_OPEN) {
            $conversacion->setStatus(MessageConversation::STATUS_OPEN);
        }

        $this->em->flush();

        $guardia = $this->guardia();

        if ($guardia === []) {
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

        $texto = $this->redactar($conversacion, $motivo);
        $avisados = [];
        $fallos = [];

        foreach ($guardia as $operador) {
            try {
                $this->avisar($operador, $texto);
                $avisados[] = $operador->getFullname() ?: $operador->getUserIdentifier();
            } catch (Throwable $e) {
                // Un operador que falla no puede impedir que se avise al resto.
                $fallos[] = $operador->getFullname() ?: $operador->getUserIdentifier();
                $this->logger->error('Escalado: no se pudo avisar a un operador.', [
                    'operador' => $operador->getUserIdentifier(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();

        return SkillResult::ok(array_filter([
            'marcada_pendiente' => true,
            'avisados' => $avisados,
            'no_avisados' => $fallos !== [] ? $fallos : null,
            'aviso_encolado' => $avisados !== [],
            'mensaje' => $avisados !== []
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

        $id = trim((string) ($entrada['conversacion_id'] ?? ''));

        return $id !== '' && Uuid::isValid($id) ? $repo->find($id) : null;
    }

    /**
     * Quién está de guardia: rol de atención **y** móvil registrado.
     *
     * Las dos condiciones, no una: el rol sin teléfono no recibe nada —y creer que sí es peor
     * que saber que no—, y un teléfono sin el rol es alguien que no está de turno.
     *
     * Se mira la columna literal de roles (`findByRole`), no la jerarquía: la guardia se asigna
     * a dedo, no se hereda por ser admin.
     *
     * @return list<User>
     */
    private function guardia(): array
    {
        return array_values(array_filter(
            $this->usuarios->findByRole(Roles::CUSTOMER_SUPPORT),
            static fn (User $u): bool => trim((string) $u->getTelefono()) !== ''
        ));
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

        return sprintf(
            "🔔 *%s* necesita respuesta\n\n%s%s\n\n👉 %s",
            $huesped,
            $motivo,
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
    private function avisar(User $operador, string $texto): void
    {
        $conversacion = $this->conversacionStaff($operador);

        $mensaje = new Message();
        $mensaje->setConversation($conversacion);
        $mensaje->setDirection(Message::DIRECTION_OUTGOING);
        // SENDER_SYSTEM y no SENDER_HOST: lo manda el sistema, y además SENDER_HOST silencia
        // al autoresponder 30 minutos — un efecto que aquí no pinta nada.
        $mensaje->setSenderType(Message::SENDER_SYSTEM);
        $mensaje->setStatus(Message::STATUS_PENDING);
        $mensaje->setTransientChannels(['whatsapp_meta']);
        $mensaje->setContentLocal($texto);
        $mensaje->setContentExternal($texto);
        $mensaje->setLanguageCode($conversacion->getIdioma()?->getId() ?? 'es');
        $mensaje->addMetadata('aviso_escalado', true);

        $conversacion->addMessage($mensaje);
        $this->em->persist($mensaje);
    }

    /** La conversación interna con un operador, creándola la primera vez. */
    private function conversacionStaff(User $operador): MessageConversation
    {
        $repo = $this->em->getRepository(MessageConversation::class);

        $conversacion = $repo->findOneBy([
            'contextType' => self::CONTEXTO_STAFF,
            'contextId' => (string) $operador->getId(),
        ]);

        if ($conversacion !== null) {
            // El móvil pudo cambiar desde la última vez: se refresca, o los avisos seguirían
            // yendo al número viejo.
            $conversacion->setGuestPhone((string) $operador->getTelefono());

            return $conversacion;
        }

        $conversacion = new MessageConversation(self::CONTEXTO_STAFF, (string) $operador->getId());
        $conversacion->setContextOrigin('interno');
        $conversacion->setGuestPhone((string) $operador->getTelefono());
        $conversacion->setGuestName($operador->getFullname() ?: $operador->getUserIdentifier());
        $conversacion->setStatus(MessageConversation::STATUS_OPEN);

        $repoIdioma = $this->em->getRepository(MaestroIdioma::class);
        $idioma = $repoIdioma->find('es') ?? $repoIdioma->findOneBy([]);

        if ($idioma !== null) {
            $conversacion->setIdioma($idioma);
        }

        $this->em->persist($conversacion);

        return $conversacion;
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
