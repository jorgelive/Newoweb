<?php

declare(strict_types=1);

namespace App\Message\EventListener;

use App\Message\Dispatch\GenerarResumenDispatch;
use App\Message\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Encola la regeneración del resumen IA cuando entra un mensaje del huésped.
 *
 * Va con RETRASO, y no por comodidad: un huésped no escribe una pregunta, escribe tres
 * trozos seguidos. Sin espera se encolarían tres trabajos que llegarían al modelo antes
 * de que el siguiente trozo existiera, y se pagarían tres llamadas para resumir lo
 * mismo. Con la espera, el primero en ejecutarse ya ve la ráfaga entera y los demás se
 * encuentran el trabajo hecho —de eso se encarga `resumenIaHasta` en
 * {@see \App\Message\Service\Resumen\ResumenConversacionService::actualizar()}—.
 *
 * Es el mismo patrón que {@see MessageAutoResponderListener}, con su propia espera
 * porque aquí se puede ser más agresivo: nadie está esperando una respuesta.
 */
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Message::class)]
readonly class MessageResumenListener
{
    public function __construct(
        private MessageBusInterface $bus,
        #[Autowire('%env(int:AGENT_IA_RESUMEN_ESPERA)%')]
        private int $espera,
    ) {}

    public function postPersist(Message $message, PostPersistEventArgs $event): void
    {
        // Solo lo que escribe el huésped cambia «qué está pendiente». Una respuesta del
        // equipo también lo cambia —lo vacía—, pero de eso se encarga el propio servicio
        // la próxima vez que entre algo.
        if ($message->getDirection() !== Message::DIRECTION_INCOMING) {
            return;
        }

        $conversacion = $message->getConversation();
        if ($conversacion === null) {
            return;
        }

        $this->bus->dispatch(
            new GenerarResumenDispatch((string) $conversacion->getId()),
            $this->espera > 0 ? [new DelayStamp($this->espera * 1000)] : []
        );
    }
}
