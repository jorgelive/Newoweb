<?php

declare(strict_types=1);

namespace App\Message\DispatchHandler;

use App\Message\Dispatch\GenerarResumenDispatch;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Resumen\ResumenConversacionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerarResumenDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ResumenConversacionService $resumen
    ) {}

    public function __invoke(GenerarResumenDispatch $dispatch): void
    {
        $conversacion = $this->em->getRepository(MessageConversation::class)->find($dispatch->conversationId);

        if (!$conversacion instanceof MessageConversation) {
            return;
        }

        // El worker es de vida larga y pudo cachear la conversación de un trabajo
        // anterior: sin refresh, `resumenIaHasta` vendría desactualizado y la guarda de
        // idempotencia dejaría pasar una llamada al modelo que no hacía falta.
        $this->em->refresh($conversacion);

        $this->resumen->actualizar($conversacion);
    }
}
