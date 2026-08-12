<?php

declare(strict_types=1);

namespace App\Message\EventListener;

use App\Agent\Dispatch\ProcessInboundIntentDispatch;
use App\Message\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Escucha modificaciones en los mensajes de chat e inyecta la orden al Agent asíncronamente.
 */
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Message::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: Message::class)]
final readonly class MessageAutoResponderListener
{
    public function __construct(
        private MessageBusInterface $bus,
        #[Autowire('%env(int:AGENT_IA_ESPERA_CORTA)%')]
        private int $esperaCorta,
    ) {}

    public function postPersist(Message $message, PostPersistEventArgs $event): void
    {
        $this->processIntent($message);
    }

    /**
     * Red de seguridad: un intent que aparece en un flush POSTERIOR al de creación.
     *
     * ⚠️ Sólo si cambió `metadata`, que es donde vive el intent. Antes se re-despachaba ante
     * CUALQUIER actualización, y la más frecuente de todas no tiene nada que ver con contestar:
     * **marcar el mensaje como leído**. Basta con que un operador abra la conversación en el
     * panel mientras el bot espera —que es justo lo que hace al ver el aviso— para que salga un
     * segundo trabajo del mismo mensaje.
     *
     * Y eso no es teórico: el 10/08/2026 a las 14:21:28 salieron DOS respuestas casi idénticas
     * en el mismo segundo, y una tercera tres segundos después. Los guardias de
     * `AiConversationProcessor` no lo pararon porque preguntan «¿ya hay respuesta?» y ninguno
     * de los dos trabajos la había escrito todavía.
     *
     * Este filtro ataca la causa —el trabajo de más— y el bloqueo del handler cubre lo que se
     * cuele igual. Las dos capas hacen falta: ésta no puede evitar que dos entren a la vez por
     * caminos distintos.
     */
    public function postUpdate(Message $message, PostUpdateEventArgs $event): void
    {
        $cambios = $event->getObjectManager()->getUnitOfWork()->getEntityChangeSet($message);

        if (!array_key_exists('metadata', $cambios)) {
            return;
        }

        // ⚠️ NO basta con «¿cambió metadata?». Marcar como leído desde el panel TAMBIÉN escribe
        // ahí —`setWhatsappMetaReadAt()` y `addWhatsappMetaMetadata('read_by_system')`, y cada
        // `add*` apila además su `_debug_trace`—, y ése es justo el disparador medido del
        // incidente del 10/08. Filtrar por la columna entera dejaba pasar exactamente el caso
        // que se quería cortar.
        //
        // Lo que decide es si cambió EL INTENT, que es lo único que este listener atiende.
        [$antes, $despues] = $cambios['metadata'];

        if (($antes['inbound_intent'] ?? null) === ($despues['inbound_intent'] ?? null)) {
            return;
        }

        $this->processIntent($message);
    }

    private function processIntent(Message $message): void
    {
        $intent = $message->getInboundIntent();

        // Si hay una intención y su flag 'resolved' es falso, despachamos la orden
        if (!$intent || ($intent['resolved'] ?? true) !== false) {
            return;
        }

        // LA ESPERA DE RÁFAGA, y sólo para texto libre.
        //
        // Un huésped no escribe una pregunta: escribe cuatro trozos seguidos —«hola» / «soy
        // Jorge Gómez» / «reservé para el 20 de enero» / «¿cuánto cuesta?»—. Atendiendo cada
        // uno al vuelo salen cuatro respuestas a medias (un saludo al «hola», otro al nombre)
        // y cuatro llamadas al modelo pagando el prompt entero cada vez. Esperando a que
        // termine, el modelo ve la ráfaga completa y contesta UNA vez, bien.
        //
        // Los botones y las alertas del sistema NO se retrasan: ahí quien pulsa espera
        // respuesta inmediata y no hay ráfaga que agrupar.
        //
        // El descarte de los trozos intermedios lo hace AiConversationProcessor, no esto:
        // aquí se encolan los cuatro trabajos y allí sobrevive el último. Ver
        // docs/Mensajeria.md §9.
        // Antes se esperaba SIEMPRE la ventana completa. Ahora se despacha con una espera
        // corta y es el pre-router —ya en el worker— quien decide si el mensaje está
        // terminado o hay que darle la ventana entera. Medido sobre mensajes reales, el
        // 81 % no se continúa nunca: esperaban 20 s para nada.
        //
        // El modelo NO puede consultarse aquí: esto corre dentro de un flush de Doctrine y
        // sería una petición de red a mitad de transacción. Por eso solo se fija el retraso
        // mínimo y la decisión viaja al handler. Ver PreRouterRafaga.
        $espera = ($intent['category'] ?? null) === 'free_text' ? $this->esperaCorta : 0;

        $this->bus->dispatch(
            new ProcessInboundIntentDispatch((string) $message->getId()),
            $espera > 0 ? [new DelayStamp($espera * 1000)] : []
        );
    }
}