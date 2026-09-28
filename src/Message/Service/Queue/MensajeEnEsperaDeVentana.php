<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Lo que el operador escribe para WhatsApp con la ventana de 24 h cerrada: se guarda, se pide
 * permiso con una plantilla corta y sale solo cuando el cliente contesta.
 *
 * ── Por qué no una plantilla genérica con el texto dentro ───────────────────
 * Era lo primero que se pidió (28/09/2026, la cotización de Eduardo: tres párrafos y un enlace,
 * ventana cerrada desde el 21/09). No sirve por tres motivos: Meta revisa una plantilla por su
 * texto FIJO y una que es casi todo variable es justo la que se usa para saltarse la ventana; los
 * parámetros no admiten saltos de línea; y el mismo marco valdría para una cotización, un cobro o
 * una promoción, así que su categoría sería una lotería.
 *
 * ```
 * operador escribe → WhatsApp 🔒 → «Enviar cuando conteste»
 *        │
 *        ├─ su mensaje: EN ESPERA, sin cola (se ve en el hilo)
 *        └─ plantilla `mensaje_pendiente`: «Tengo una respuesta para ti. ¿Te la envío por aquí?»
 *                                          [ Sí, envíamela ]
 * cliente pulsa o escribe → se abre la ventana → liberar(): el mensaje sale tal cual
 * ```
 *
 * ⚠️ **Una sola plantilla por espera.** Si ya hay algo esperando en el hilo, el mensaje nuevo se
 * suma sin mandar otro aviso: dos «¿te lo envío?» seguidos es lo contrario de parecer una persona.
 */
final readonly class MensajeEnEsperaDeVentana
{
    /** La plantilla del aviso. La crea `msg:plantillas:mensaje-pendiente`. */
    public const string PLANTILLA = 'mensaje_pendiente';

    /** Lo que manda su botón. Sin regla de autorespuesta a propósito: la respuesta ES lo liberado. */
    public const string PAYLOAD_BOTON = 'CMD_ENVIAR_PENDIENTE';

    private const string CANAL = 'whatsapp_meta';

    public function __construct(
        private EntityManagerInterface $em,
        private MessageDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * Decide, al crearse un mensaje `en_espera`, si de verdad tiene que esperar.
     *
     * Lo llama `MessageEnqueuerEntityListener::prePersist()` ANTES de fabricar colas: si la
     * ventana resulta estar abierta —el cliente escribió mientras el operador tecleaba—, el
     * mensaje vuelve a `pending` y sale por el camino normal en ese mismo guardado.
     */
    public function retener(Message $mensaje): void
    {
        $hilo = $mensaje->getConversation();

        if ($hilo === null
            || $mensaje->getDirection() !== Message::DIRECTION_OUTGOING
            || $hilo->isWhatsappSessionActive()) {
            $mensaje->setStatus(Message::STATUS_PENDING);

            return;
        }

        // Sólo WhatsApp: la espera es de SU ventana. Por Beds24 o correo no hay nada que esperar,
        // y un mensaje tiene un solo estado — mezclarlos dejaría uno de los dos colgado.
        $mensaje->setTransientChannels([self::CANAL]);

        if ($this->yaHayOtroEsperando($hilo, $mensaje)) {
            $mensaje->addMetadata('en_espera', ['aviso' => 'el del mensaje anterior']);

            return;
        }

        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::PLANTILLA]);

        if (!$plantilla instanceof MessageTemplate) {
            // Sin plantilla no hay forma de pedir permiso: se dice en el propio mensaje, que se
            // queda esperando igual y saldrá si el cliente escribe por su cuenta.
            $mensaje->addMetadata('en_espera', ['aviso' => 'no enviado: falta la plantilla ' . self::PLANTILLA]);
            $this->logger->error('Mensaje en espera sin aviso: no existe la plantilla ' . self::PLANTILLA);

            return;
        }

        $aviso = (new Message())
            ->setDirection(Message::DIRECTION_OUTGOING)
            ->setSenderType(Message::SENDER_HOST)
            ->setStatus(Message::STATUS_PENDING)
            ->setTemplate($plantilla)
            ->setLanguageCode((string) $hilo->getIdioma()->getId())
            ->setTransientChannels([self::CANAL]);
        $aviso->addMetadata('aviso_de_mensaje_en_espera', (string) $mensaje->getId());

        $hilo->addMessage($aviso);
        // Dentro del `prePersist` del mensaje del operador: un `persist()` aquí dispara el
        // `prePersist` del aviso, que fabrica su cola, y los dos entran en el mismo flush.
        $this->em->persist($aviso);

        $mensaje->addMetadata('en_espera', ['aviso' => (string) $aviso->getId()]);
    }

    /**
     * Suelta lo que esperaba en el hilo. Lo llama la recepción de WhatsApp en cuanto el cliente
     * escribe o pulsa, con la ventana ya abierta. NO hace flush: lo hace quien llama.
     *
     * Las colas se piden aquí y no en el `preUpdate` del mensaje: una entidad persistida dentro
     * de `preUpdate` no entra en ese mismo flush (la misma trampa que obligó a hacerlo así al
     * revivir `sin_canal`, ver `MessageRuleEngine::syncPendingMessage()`).
     *
     * @return int Cuántos mensajes salieron.
     */
    public function liberar(MessageConversation $hilo): int
    {
        $ahora = new DateTimeImmutable();
        $liberados = 0;

        foreach ($this->esperando($hilo) as $mensaje) {
            $mensaje->setStatus(Message::STATUS_PENDING);
            $mensaje->setTransientChannels([self::CANAL]);
            // Ocurre AHORA, no cuando se escribió: en el hilo tiene que quedar debajo de la
            // respuesta del cliente, que es cuando de verdad le llegó.
            $mensaje->setScheduledAt($ahora);
            $mensaje->addMetadata('en_espera_liberado', $ahora->format(DATE_ATOM));

            foreach ($this->dispatcher->dispatch($mensaje) as $cola) {
                $mensaje->addQueue($cola);
                $this->em->persist($cola);
            }

            ++$liberados;
        }

        return $liberados;
    }

    private function yaHayOtroEsperando(MessageConversation $hilo, Message $nuevo): bool
    {
        foreach ($this->esperando($hilo) as $mensaje) {
            if ($mensaje !== $nuevo) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los que esperan en el hilo, del más viejo al más nuevo: salen en el orden en que se
     * escribieron.
     *
     * @return list<Message>
     */
    private function esperando(MessageConversation $hilo): array
    {
        /** @var list<Message> $mensajes */
        $mensajes = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.conversation = :hilo')
            ->andWhere('m.status = :espera')
            ->andWhere('m.direction = :saliente')
            ->setParameter('hilo', $hilo->getId(), UuidType::NAME)
            ->setParameter('espera', Message::STATUS_EN_ESPERA)
            ->setParameter('saliente', Message::DIRECTION_OUTGOING)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $mensajes;
    }
}
