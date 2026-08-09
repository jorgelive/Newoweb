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
     * Segundos que se esperan TRAS la ventana de ráfaga del resumen.
     *
     * El trabajo del resumen se encola con `AGENT_IA_RESUMEN_ESPERA`; este margen cubre
     * lo que tarde el worker en cogerlo y escribir. Sin él habría una carrera y el push
     * podría volver a leer el resumen viejo.
     */
    private const int MARGEN_TRAS_RESUMEN = 4;

    public function __construct(
        private MercureBroadcaster $mercureBroadcaster,
        private MessageBusInterface $bus,
        #[Autowire('%env(int:AGENT_IA_RESUMEN_ESPERA)%')]
        private int $esperaResumen,
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
        // Dos motivos, y el segundo es el que se veía:
        //
        // 1. Mandar push es I/O de red, y esto corre dentro de un flush de Doctrine.
        // 2. El resumen IA se calcula unos segundos después (espera de ráfaga). Enviando
        //    al instante, el cuerpo llevaba el resumen del turno ANTERIOR — el operador
        //    veía en la notificación lo que le pidieron la vez pasada.
        //
        // El retraso es el de la ráfaga MÁS un margen, para que el trabajo del resumen
        // haya terminado de escribir antes de que este lea. Si el resumen falla o está
        // apagado, el push sale igual con el texto del último mensaje.
        $this->bus->dispatch(
            new EnviarPushConversacionDispatch((string) $conversation->getId()),
            [new DelayStamp(($this->esperaResumen + self::MARGEN_TRAS_RESUMEN) * 1000)]
        );
    }
}
