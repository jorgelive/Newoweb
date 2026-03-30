<?php

declare(strict_types=1);

namespace App\Agent\DispatchHandler;

use App\Agent\Dispatch\ProcessInboundIntentDispatch;
use App\Agent\Router\IntentRouter;
use App\Message\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessInboundIntentDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private IntentRouter $intentRouter
    ) {}

    public function __invoke(ProcessInboundIntentDispatch $dispatch): void
    {
        $msg = $this->em->getRepository(Message::class)->find($dispatch->messageId);

        // 1. Doble validación de seguridad (por si el worker se retrasó y ya se resolvió)
        if (!$msg instanceof Message || !$msg->getInboundIntent() || $msg->getInboundIntent()['resolved']) {
            return;
        }

        // 2. Le pasamos el control a tu nuevo Router Determinista/IA
        $this->intentRouter->routeIntent($msg);
    }
}