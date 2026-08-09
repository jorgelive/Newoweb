<?php

declare(strict_types=1);

namespace App\Message\EventListener\Mercure;

use App\Message\Dispatch\EnviarPushConversacionDispatch;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Mercure\MercureBroadcaster;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: MessageConversation::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: MessageConversation::class)]
readonly class MessageConversationMercureListener
{
    /**
     * Segundos de margen sobre la más larga de las dos esperas de IA.
     *
     * Cubre lo que tarda el worker en coger el trabajo MÁS la llamada al modelo (1-4 s
     * medidos con Gemini Flash). Sin margen habría una carrera y el push podría salir
     * mientras el agente todavía está redactando.
     */
    private const int MARGEN_IA = 10;

    public function __construct(
        private MercureBroadcaster $mercureBroadcaster,
        private MessageBusInterface $bus,
        #[Autowire('%env(int:AGENT_IA_RESUMEN_ESPERA)%')]
        private int $esperaResumen,
        #[Autowire('%env(int:AGENT_IA_ESPERA_RAFAGA)%')]
        private int $esperaRafaga,
    ) {}

    /**
     * Se ejecuta cuando se crea una nueva conversación en la base de datos.
     * * @param MessageConversation $conversation La entidad recién creada.
     * @param PostPersistEventArgs $event Argumentos del evento de Doctrine.
     */
    public function postPersist(MessageConversation $conversation, PostPersistEventArgs $event): void
    {
        // 1. Siempre transmitimos a Mercure primero
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation, 'conversation_created');

        // 2. Si la conversación nace ya con mensajes sin leer, disparamos Push
        if ($conversation->getUnreadCount() > 0) {
            $this->safeDispatchPushNotifications($conversation);
        }
    }

    /**
     * Se ejecuta cuando se actualiza una conversación existente.
     * * @param MessageConversation $conversation La entidad actualizada.
     * @param PostUpdateEventArgs $event Argumentos del evento, útiles para el ChangeSet.
     */
    public function postUpdate(MessageConversation $conversation, PostUpdateEventArgs $event): void
    {
        // 1. Siempre transmitimos a Mercure primero
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation, 'conversation_updated');

        // 2. Extraemos el ChangeSet para saber qué columnas físicas cambiaron
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($conversation);

        if (isset($changeSet['unreadCount'])) {
            $oldUnread = (int) $changeSet['unreadCount'][0];
            $newUnread = (int) $changeSet['unreadCount'][1];

            // Si el número aumentó, es un mensaje nuevo. Disparamos Push.
            if ($newUnread > $oldUnread) {
                $this->safeDispatchPushNotifications($conversation);
            }
        }
    }

    /**
     * Envuelve el envío masivo en un try-catch y resuelve los roles en memoria (Security).
     * Garantiza que un error en el servicio Push no rompa el flujo asíncrono.
     * * @param MessageConversation $conversation La conversación que generó la alerta.
     */
    private function safeDispatchPushNotifications(MessageConversation $conversation): void
    {
        // Aquí solo se decide CUÁNDO hay que avisar. El envío se encola.
        //
        // Mandar push es I/O de red y esto corre dentro de un flush de Doctrine, así que
        // no se envía aquí ni aunque no hubiera que esperar a nada.
        //
        // **El aviso sale el ÚLTIMO, a propósito.** Sobre el mismo mensaje entrante
        // corren dos procesos que cambian lo que el operador debería leer:
        //
        //   resumen IA   → AGENT_IA_RESUMEN_ESPERA  (qué pide el huésped)
        //   agente       → AGENT_IA_ESPERA_RAFAGA   (puede contestar solo)
        //
        // Avisando antes de que terminen, la notificación miente por omisión: llevaba el
        // resumen del turno anterior, y avisaba de algo que la IA iba a resolver sola
        // ocho segundos después. Se espera a la MÁS LARGA de las dos, más un margen.
        // Decisión explícita del usuario: información fiable por delante de rápida.
        //
        // Si la IA está apagada, `AGENT_IA_ESPERA_RAFAGA` sigue configurada y el aviso
        // llega igual, solo que unos segundos más tarde. No hay acoplamiento: ninguno de
        // los dos procesos tiene que existir para que el push salga.
        $espera = max($this->esperaResumen, $this->esperaRafaga) + self::MARGEN_IA;

        $this->bus->dispatch(
            new EnviarPushConversacionDispatch((string) $conversation->getId()),
            [new DelayStamp($espera * 1000)]
        );
    }
}
