<?php

declare(strict_types=1);

namespace App\Message\EventListener;

use App\Entity\User;
use App\Message\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Firma con quien esté conectado cada mensaje que escribe una persona del equipo.
 *
 * Aquí y no en cada sitio que crea mensajes: el chat de util, el asistente del panel, los
 * botones de Operaciones… escriben por caminos distintos, y todos pasan por el `prePersist`. Sin
 * usuario en sesión —los workers, el cron, el agente contestando por WhatsApp— no se firma nada,
 * y es lo correcto: no lo escribió nadie.
 *
 * Sólo los `SENDER_HOST` y las notas internas: lo `SENDER_SYSTEM` que sale durante una petición
 * (un aviso que dispara un botón) no lo redactó la persona que pulsó. Lo que ya trae autor no se
 * toca. Ver `Message::getAutorEtiqueta()`.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Message::class)]
final readonly class AutorDelMensajeListener
{
    public function __construct(private Security $security) {}

    public function prePersist(Message $mensaje): void
    {
        if ($mensaje->getAutor() !== null
            || !in_array($mensaje->getSenderType(), [Message::SENDER_HOST, Message::SENDER_INTERNAL], true)
        ) {
            return;
        }

        $usuario = $this->security->getUser();

        if ($usuario instanceof User) {
            $mensaje->setAutor($usuario);
        }
    }
}
