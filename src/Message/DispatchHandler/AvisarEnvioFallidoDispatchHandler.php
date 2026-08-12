<?php

declare(strict_types=1);

namespace App\Message\DispatchHandler;

use App\Message\Dispatch\AvisarEnvioFallidoDispatch;
use App\Message\Entity\Message;
use App\Message\Service\Push\NotificadorPushConversacion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AvisarEnvioFallidoDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificadorPushConversacion $notificador
    ) {}

    public function __invoke(AvisarEnvioFallidoDispatch $dispatch): void
    {
        $mensaje = $this->em->getRepository(Message::class)->find($dispatch->messageId);

        if (!$mensaje instanceof Message) {
            return;
        }

        // El worker es de vida larga y pudo cachear el mensaje de un trabajo anterior; además
        // entre la detección y este momento pudo reintentarse y salir.
        $this->em->refresh($mensaje);

        if ($mensaje->getStatus() !== Message::STATUS_FAILED) {
            return;
        }

        $this->notificador->avisarEnvioFallido($mensaje);
    }
}
