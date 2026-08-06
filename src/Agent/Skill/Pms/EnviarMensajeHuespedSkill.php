<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsReserva;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Envía un mensaje de texto al huésped por los canales que se indiquen. **Escribe fuera.**
 *
 * Es la skill de mayor alcance del catálogo: lo que hace sale del sistema y llega a una
 * persona real. De ahí que el texto **lo redacte el modelo pero lo apruebe un humano**: sin
 * `confirmado: true` devuelve el borrador exacto y los canales por los que iría, sin enviar.
 *
 * ### El texto lo compone el modelo, no la skill
 *
 * No existe un `enviar_estado_de_cuenta` que arme la tabla por dentro. El modelo consulta
 * `consultar_cuenta`, redacta —en el idioma del huésped, con el tono que toque— y esta skill
 * lo manda. Así sirve igual para el estado de cuenta, para una instrucción de llegada o para
 * lo que haga falta mañana, sin una skill nueva por cada tipo de mensaje.
 *
 * ### Formato por canal
 *
 * El texto viaja igual a los dos canales, pero no se lee igual: WhatsApp interpreta
 * `*negrita*` y muestra saltos de línea; el chat de una OTA es texto plano y suele verse en
 * una caja estrecha. La skill avisa de eso al modelo en su descripción en vez de reescribir
 * el texto: reformatear a ciegas lo que un humano acaba de aprobar sería cambiar el mensaje
 * después de la revisión.
 *
 * ### Sin plantilla: el `Message` se crea con `transientChannels`
 *
 * `MessageDispatcher::resolveChannels()` da prioridad a la plantilla cuando la hay; aquí no
 * la hay, así que manda la lista de canales. Ver docs/Mensajeria.md §5.
 */
final readonly class EnviarMensajeHuespedSkill implements SkillInterface
{
    /** Techo del texto. WhatsApp corta antes, pero un mensaje así ya es un error de uso. */
    private const int MAX_CARACTERES = 3000;

    /**
     * @param iterable<ChannelEnqueuerInterface> $enqueuers
     */
    public function __construct(
        private EntityManagerInterface $em,
        #[AutowireIterator('app.message.enqueuer')]
        private iterable $enqueuers,
    ) {}

    public function nombre(): string
    {
        return 'enviar_mensaje_huesped';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Envía un mensaje de texto al huésped por WhatsApp, por el chat de la '
                . 'OTA, o por ambos. ENVÍA A UNA PERSONA REAL: llama primero con '
                . 'confirmado=false, enseña al operador el texto y los canales, y espera su sí '
                . 'antes de llamar con confirmado=true. Escribe el texto en el idioma del '
                . 'huésped. Ten en cuenta el canal: por WhatsApp puedes usar *negrita* y saltos '
                . 'de línea; el chat de una OTA es texto plano y se lee en una caja estrecha, '
                . 'así que conviene más corto y sin adornos. Si la respuesta trae reserva_cancelada, DÍSELO AL OPERADOR ANTES de pedirle nada: el chat de una reserva cancelada sigue funcionando y nada mas lo delata. Usa localizar_conversacion antes '
                . 'para saber qué canales están disponibles. Si la respuesta trae advertencia, '
                . 'LÉESELA al operador antes de pedirle la aprobación.',
            parametros: [
                SkillParameter::texto('conversacion_id', 'Identificador del chat, de '
                    . 'localizar_conversacion.'),
                SkillParameter::texto('texto', 'El mensaje ya redactado, tal cual lo recibirá '
                    . 'el huésped.'),
                SkillParameter::texto('canales', 'Canales separados por comas ("whatsapp_meta", '
                    . '"beds24") o "todos" para los disponibles.', requerido: false),
                SkillParameter::booleano('confirmado', 'true SÓLO tras la aprobación explícita '
                    . 'del operador. false para ver el borrador.'),
            ],
        );
    }

    /** Escribir al huésped exige permiso de mensajería, no basta con ver reservas. */
    public function rolesRequeridos(): array
    {
        return [Roles::MENSAJES_WRITE];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Escritura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $conversacionId = trim((string) ($entrada['conversacion_id'] ?? ''));
        $texto = trim((string) ($entrada['texto'] ?? ''));
        $confirmado = filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL);

        if (!Uuid::isValid($conversacionId)) {
            return SkillResult::error('El conversacion_id no es válido.');
        }

        if ($texto === '') {
            return SkillResult::error('El mensaje está vacío.');
        }

        if (mb_strlen($texto) > self::MAX_CARACTERES) {
            return SkillResult::error(sprintf(
                'El mensaje pasa de %d caracteres. Acórtalo.',
                self::MAX_CARACTERES
            ));
        }

        $conversacion = $this->em->getRepository(MessageConversation::class)->find($conversacionId);
        if ($conversacion === null) {
            return SkillResult::error('No existe ese chat.');
        }

        if ($conversacion->getStatus() === MessageConversation::STATUS_ARCHIVED) {
            return SkillResult::error('Este chat está archivado: no se puede escribir en él.');
        }

        $disponibles = $this->canalesDisponibles($conversacion);
        if ($disponibles === []) {
            return SkillResult::error(
                'No hay ningún canal disponible para escribir a este huésped. '
                . 'Consulta localizar_conversacion para ver por qué.'
            );
        }

        $pedidos = $this->canalesPedidos((string) ($entrada['canales'] ?? 'todos'), $disponibles);
        if ($pedidos === []) {
            return SkillResult::error(sprintf(
                'Ninguno de los canales indicados está disponible. Disponibles: %s.',
                implode(', ', $disponibles)
            ));
        }

        // Último punto donde se puede avisar: después de esto sale de verdad.
        $cancelada = $this->reservaCancelada($conversacion);

        $resumen = array_filter([
            'conversacion_id' => $conversacionId,
            'huesped' => $conversacion->getGuestName(),
            'idioma_huesped' => $conversacion->getIdioma()?->getId(),
            'canales' => $pedidos,
            'texto' => $texto,
            'reserva_cancelada' => $cancelada ?: null,
            'advertencia' => $cancelada
                ? 'ATENCIÓN: la reserva de este huésped está CANCELADA. Díselo al operador '
                    . 'ANTES de pedirle la aprobación y que confirme que aun así quiere '
                    . 'enviarlo.'
                : null,
        ], static fn ($v) => $v !== null);

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'enviado' => false,
                'motivo' => 'falta_confirmacion',
                'pregunta_aprobacion' => '¿Apruebas el envío?',
                'previsualizacion' => sprintf(
                    'Se enviará este mensaje a %s por %s. Es IRREVERSIBLE: una vez enviado no '
                    . 'se puede retirar. Enséñaselo al operador tal cual, palabra por palabra, '
                    . 'y espera su confirmación.',
                    $conversacion->getGuestName() ?? 'el huésped',
                    implode(' y ', $pedidos)
                ),
            ]);
        }

        $mensaje = new Message();
        $mensaje->setConversation($conversacion);
        $mensaje->setDirection(Message::DIRECTION_OUTGOING);
        // SENDER_HOST y no SENDER_SYSTEM: lo manda una persona a través del asistente, y en
        // el chat debe verse como lo que es. Además, SENDER_HOST es lo que hace callar al
        // autoresponder durante 30 minutos (guardia «humano al mando»), que es justo lo que
        // corresponde después de escribirle a mano a un huésped.
        $mensaje->setSenderType(Message::SENDER_HOST);
        $mensaje->setStatus(Message::STATUS_PENDING);
        $mensaje->setTransientChannels($pedidos);
        $mensaje->setContentLocal($texto);
        $mensaje->setContentExternal($texto);
        $mensaje->setLanguageCode($conversacion->getIdioma()?->getId() ?? 'es');
        $mensaje->addMetadata('enviado_por_agente', $actor->etiqueta());

        $conversacion->addMessage($mensaje);
        $this->em->persist($mensaje);
        $this->em->flush();

        // El estado real lo fija el listener al crear las colas: si un canal falla, queda en
        // la metadata del mensaje. Se devuelve lo que hay tras el flush, no una suposición.
        return SkillResult::ok($resumen + [
            'enviado' => true,
            'mensaje_id' => (string) $mensaje->getId(),
            'estado' => $mensaje->getStatus(),
            'colas_creadas' => count($mensaje->getAllQueues()),
        ]);
    }

    /** @return list<string> */
    private function canalesDisponibles(MessageConversation $conversacion): array
    {
        $sonda = new Message();
        $sonda->setConversation($conversacion);
        $sonda->setDirection(Message::DIRECTION_OUTGOING);
        $sonda->setSenderType(Message::SENDER_HOST);

        $disponibles = [];

        foreach ($this->em->getRepository(MessageChannel::class)->findBy(['isActive' => true]) as $canal) {
            foreach ($this->enqueuers as $enqueuer) {
                if ($enqueuer->supports($canal) && $enqueuer->isValid($sonda)) {
                    $disponibles[] = (string) $canal->getId();
                }
            }
        }

        return array_values(array_unique($disponibles));
    }

    /**
     * @param list<string> $disponibles
     * @return list<string>
     */
    private function canalesPedidos(string $peticion, array $disponibles): array
    {
        $peticion = strtolower(trim($peticion));

        if ($peticion === '' || $peticion === 'todos') {
            return $disponibles;
        }

        $pedidos = array_map('trim', explode(',', $peticion));

        // Se intersecta contra los disponibles en vez de fallar: pedir un canal que no vale
        // y que salga por el otro es mejor que no enviar nada.
        return array_values(array_intersect($pedidos, $disponibles));
    }

    /**
     * ¿La reserva de este chat está cancelada?
     *
     * La conversación apunta a su contexto por tipo e id; sólo `pms_reserva` tiene estado que
     * mirar. La regla en sí no se repite aquí: la contesta {@see PmsReserva::estaCancelada()}.
     */
    private function reservaCancelada(MessageConversation $conversacion): bool
    {
        if ($conversacion->getContextType() !== 'pms_reserva' || $conversacion->getContextId() === null) {
            return false;
        }

        return $this->em->getRepository(PmsReserva::class)
            ->find($conversacion->getContextId())?->estaCancelada() ?? false;
    }
}
