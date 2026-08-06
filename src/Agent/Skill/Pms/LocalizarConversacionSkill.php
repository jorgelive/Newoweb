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
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * El chat de una reserva y **por qué canales se le puede escribir ahora mismo**.
 *
 * 🔗 Eslabón previo a cualquier envío. Su valor no es encontrar la conversación —eso es un
 * `findOneBy`— sino responder la pregunta que de verdad importa: *¿llegará el mensaje?*
 *
 * ### No reimplementa las reglas de canal: pregunta a quien las tiene
 *
 * Recorre los `ChannelEnqueuerInterface` y llama a su `isValid()` con un mensaje **en
 * memoria, sin persistir**. Es el mismo juez que decidirá en el envío real, así que lo que
 * diga aquí es lo que pasará después. Copiar aquí «Beds24 no admite reservas directas» o «la
 * ventana de 24 h» habría creado una segunda verdad que se desincroniza a la primera.
 *
 * El motivo por el que un canal no vale se explica **para el operador**, no con el nombre
 * técnico de la regla.
 */
final readonly class LocalizarConversacionSkill implements SkillInterface
{
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
        return 'localizar_conversacion';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Encuentra el chat de una reserva y dice por qué canales se le puede '
                . 'escribir al huésped ahora mismo (WhatsApp, Beds24…), con el motivo de los '
                . 'que no estén disponibles. Úsala SIEMPRE antes de enviar_mensaje_huesped, y '
                . 'cuando pregunten si se puede contactar a alguien o por dónde. Necesita el '
                . 'reserva_id.',
            parametros: [
                SkillParameter::texto('reserva_id', 'Identificador de la reserva.'),
            ],
        );
    }

    public function rolesRequeridos(): array
    {
        return [Roles::MENSAJES_SHOW, Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $reservaId = trim((string) ($entrada['reserva_id'] ?? ''));

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error('El reserva_id no es válido.');
        }

        $conversacion = $this->em->getRepository(MessageConversation::class)
            ->findOneBy(['contextType' => 'pms_reserva', 'contextId' => $reservaId]);

        if ($conversacion === null) {
            return SkillResult::error(
                'Esta reserva todavía no tiene chat abierto. Se crea solo en cuanto hay un '
                . 'mensaje o el motor de reglas programa el primero.'
            );
        }

        return SkillResult::ok([
            'conversacion_id' => (string) $conversacion->getId(),
            'huesped' => $conversacion->getGuestName(),
            'idioma_huesped' => $conversacion->getIdioma()?->getId(),
            'estado_chat' => $conversacion->getStatus(),
            'canales' => $this->canales($conversacion),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function canales(MessageConversation $conversacion): array
    {
        // Mensaje de mentira, sólo para preguntar. Nunca se persiste: sirve de contexto a
        // `isValid()`, que necesita la conversación para decidir.
        $sonda = new Message();
        $sonda->setConversation($conversacion);
        $sonda->setDirection(Message::DIRECTION_OUTGOING);
        $sonda->setSenderType(Message::SENDER_HOST);

        $salida = [];

        $canales = $this->em->getRepository(MessageChannel::class)->findBy(['isActive' => true]);

        foreach ($canales as $canal) {
            foreach ($this->enqueuers as $enqueuer) {
                if (!$enqueuer->supports($canal)) {
                    continue;
                }

                $disponible = $enqueuer->isValid($sonda);

                $salida[] = array_filter([
                    'canal' => (string) $canal->getId(),
                    'nombre' => $canal->getName(),
                    'disponible' => $disponible,
                    'motivo' => $disponible ? null : $this->motivo((string) $canal->getId(), $conversacion),
                ], static fn ($v) => $v !== null);

                break;
            }
        }

        return $salida;
    }

    /**
     * Traduce el «no» del enqueuer a algo que el operador pueda accionar.
     *
     * `isValid()` sólo devuelve un booleano, así que el motivo se reconstruye mirando las
     * mismas condiciones. Es una duplicación consciente y acotada a explicar, no a decidir:
     * quien decide sigue siendo el enqueuer, y si aquí se acierta a medias el peor efecto es
     * un mensaje impreciso, no un envío indebido.
     */
    private function motivo(string $canal, MessageConversation $conversacion): string
    {
        if ($canal === 'whatsapp_meta') {
            if ($conversacion->isWhatsappDisabled()) {
                return 'WhatsApp está desactivado en este chat: '
                    . ($conversacion->getWhatsappDisabledReason() ?? 'el número rebotó');
            }

            if (empty($conversacion->getGuestPhone())) {
                return 'No hay teléfono guardado para este huésped.';
            }

            return 'No disponible ahora mismo.';
        }

        if ($canal === 'beds24') {
            return 'Es una reserva directa o sin vínculo con el canal, así que no hay chat de '
                . 'OTA por el que escribir.';
        }

        return 'No disponible ahora mismo.';
    }
}
