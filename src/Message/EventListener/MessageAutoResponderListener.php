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

    public function postUpdate(Message $message, PostUpdateEventArgs $event): void
    {
        // En PostUpdate no tenemos hasChangedField, pero evaluamos la regla de negocio directamente.
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