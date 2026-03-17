<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\Message;
use App\Pms\Service\Reserva\PmsReservaRecalculoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/*
 * uso: php bin/console app:message:rebuild-context
 */
#[AsCommand(
    name: 'app:message:rebuild-context',
    description: 'Sincroniza el contexto PMS, repara cronología y archiva chats antiguos.'
)]
class RebuildConversationContextCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PmsReservaRecalculoService $recalculoService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Reconstrucción de Contexto de Mensajería');

        $conversations = $this->entityManager->getRepository(MessageConversation::class)
            ->findBy(['contextType' => 'pms_reserva']);

        $total = count($conversations);
        if ($total === 0) {
            $io->warning('No hay conversaciones para procesar.');
            return Command::SUCCESS;
        }

        $io->note(sprintf('Sincronizando %d conversaciones...', $total));
        $reservaIds = array_map(fn($c) => $c->getContextId(), $conversations);

        // 1. LLAMADA AL SERVICIO PMS (Unidades, Hitos, Montos)
        $this->recalculoService->recalcularDesdeEventos(
            reservaIds: array_unique(array_filter($reservaIds)),
            entityManager: $this->entityManager,
            flush: true
        );

        $io->progressStart($total);
        $now = new \DateTime();

        // 2. LÓGICA DE SANIDAD DE MENSAJERÍA
        foreach ($conversations as $conversation) {
            /** @var MessageConversation $conversation */

            // 🔥 NUEVA LÓGICA: Encontrar el último mensaje REAL (ignorando programados a futuro)
            // Iteramos sobre la colección en lugar de hacer una consulta directa para garantizar
            // que usamos la misma lógica estricta de la entidad.
            $lastValidMessage = null;
            $lastValidDate = null;

            foreach ($conversation->getMessages() as $msg) {
                if (!$msg->getIsScheduledForFuture()) {
                    $msgDate = $msg->getEffectiveDateTime() ?? clone $msg->getCreatedAt();

                    if ($lastValidDate === null || $msgDate > $lastValidDate) {
                        $lastValidDate = clone $msgDate;
                        $lastValidMessage = $msg;
                    }
                }
            }

            if ($lastValidMessage) {
                $conversation->setLastMessageAt($lastValidDate);

                // Si el último mensaje es OUTGOING, el chat está atendido
                if ($lastValidMessage->getDirection() === Message::DIRECTION_OUTGOING) {
                    $conversation->setUnreadCount(0);
                }
            }

            // --- LÓGICA DE ARCHIVADO AUTOMÁTICO ---
            // Si la estancia terminó hace más de 7 días, lo movemos a Archivados
            $milestones = $conversation->getContextMilestones();
            if (isset($milestones['end'])) {
                $endDate = new \DateTime($milestones['end']);
                $diff = $now->diff($endDate)->days;

                if ($endDate < $now && $diff > 7 && $conversation->getStatus() === MessageConversation::STATUS_OPEN) {
                    $conversation->setStatus(MessageConversation::STATUS_ARCHIVED);
                }
            }

            $io->progressAdvance();
        }

        $this->entityManager->flush();
        $io->progressFinish();

        $io->success('Proceso completado: Contexto PMS sincronizado, cronología reparada y chats antiguos archivados.');

        return Command::SUCCESS;
    }
}