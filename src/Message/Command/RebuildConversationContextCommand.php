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

        $repository = $this->entityManager->getRepository(MessageConversation::class);

        // Obtenemos los objetos puros inicialmente para poder extraer sus IDs limpios
        $conversations = $repository->findBy(['contextType' => 'pms_reserva']);
        $total = count($conversations);

        if ($total === 0) {
            $io->warning('No hay conversaciones para procesar.');
            return Command::SUCCESS;
        }

        $io->note(sprintf('Sincronizando %d conversaciones...', $total));

        // 🔥 OPTIMIZACIÓN: Extraemos los IDs como texto (String) y luego limpiamos la RAM
        $reservaIds = [];
        $conversationIds = [];
        foreach ($conversations as $c) {
            $conversationIds[] = (string) $c->getId();
            $reservaIds[] = $c->getContextId();
        }

        // Vaciamos la memoria de Doctrine para que no colapse en procesamientos masivos
        $this->entityManager->clear();

        // 1. LLAMADA AL SERVICIO PMS (Unidades, Hitos, Montos)
        $this->recalculoService->recalcularDesdeEventos(
            reservaIds: array_unique(array_filter($reservaIds)),
            entityManager: $this->entityManager,
            flush: true
        );

        $io->progressStart($total);
        $now = new \DateTime();
        $countSynced = 0;

        // 2. LÓGICA DE SANIDAD DE MENSAJERÍA
        foreach ($conversationIds as $id) {
            /** @var MessageConversation|null $conversation */
            // Ahora Doctrine recibe un String válido y encuentra la conversación sin problema
            $conversation = $repository->find($id);

            if (!$conversation) {
                $io->progressAdvance();
                continue;
            }

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

                if ($lastValidMessage->getDirection() === Message::DIRECTION_OUTGOING) {
                    $conversation->setUnreadCount(0);
                }
            } else {
                // 🔥 RESCATE: Si NO hay mensajes reales (solo futuros), le damos la fecha de creación del chat
                // para que no pierda su lugar cronológico base en Vue.
                $conversation->setLastMessageAt($conversation->getCreatedAt());
            }

            // --- LÓGICA DE ARCHIVADO AUTOMÁTICO ---
            $milestones = $conversation->getContextMilestones();
            if (isset($milestones['end'])) {
                $endDate = new \DateTime($milestones['end']);
                $diff = $now->diff($endDate)->days;

                if ($endDate < $now && $diff > 7 && $conversation->getStatus() === MessageConversation::STATUS_OPEN) {
                    $conversation->setStatus(MessageConversation::STATUS_CLOSED);
                }
            }

            $countSynced++;
            $io->progressAdvance();

            // 🔥 Liberar memoria de forma segura
            if ($countSynced % 50 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        $io->progressFinish();

        $io->success('Proceso completado: Contexto PMS sincronizado, cronología reparada y chats antiguos cerrados.');

        return Command::SUCCESS;
    }
}