<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\Message;
use App\Pms\Service\Reserva\PmsReservaRecalculoService;
use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Uso: php bin/console app:message:rebuild-context
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

        $conversations = $repository->findBy(['contextType' => 'pms_reserva']);
        $total         = count($conversations);

        if ($total === 0) {
            $io->warning('No hay conversaciones para procesar.');
            return Command::SUCCESS;
        }

        $io->note(sprintf('Sincronizando %d conversaciones...', $total));

        $reservaIds      = [];
        $conversationIds = []; // array de objetos Uuid — Doctrine los convierte solo

        foreach ($conversations as $c) {
            $conversationIds[] = $c->getId();
            $reservaIds[]      = $c->getContextId();
        }

        // Vaciamos la memoria de Doctrine para que no colapse en procesamientos masivos
        $this->entityManager->clear();

        // 1. SINCRONIZACIÓN PMS
        $this->recalculoService->recalcularDesdeEventos(
            reservaIds: array_unique(array_filter($reservaIds)),
            entityManager: $this->entityManager,
            flush: true
        );

        // 2. PRE-CALCULAMOS lastMessageAt en una sola query — sin lazy-load
        $lastMessageData = $this->entityManager->getConnection()->executeQuery('
                SELECT 
                    BIN_TO_UUID(m.conversation_id) AS convId,
                    MAX(
                        CASE 
                            -- 1. Ignorar explícitamente los mensajes cancelados
                            WHEN m.status = :statusCancelled THEN NULL
                            
                            -- 2. Ignorar los que están programados para el FUTURO
                            WHEN COALESCE(m.scheduled_at, m.created_at) > NOW() THEN NULL
                            
                            -- 3. ACEPTAR TODOS LOS DEMÁS (sent, received, read, y también queued/pending/failed actuales)
                            ELSE COALESCE(m.scheduled_at, m.created_at)
                        END
                    ) AS lastReal,
                    MAX(
                        CASE 
                            WHEN m.status = :statusCancelled THEN NULL
                            WHEN COALESCE(m.scheduled_at, m.created_at) > NOW() THEN NULL
                            WHEN m.direction = :outgoing THEN COALESCE(m.scheduled_at, m.created_at)
                            ELSE NULL 
                        END
                    ) AS lastOutgoing
                FROM msg_message m
                WHERE m.conversation_id IN (
                    SELECT UUID_TO_BIN(u.id) FROM (
                        SELECT BIN_TO_UUID(c.id) AS id
                        FROM msg_conversation c
                        WHERE c.id IN (:binaryIds)
                    ) u
                )
                GROUP BY m.conversation_id
            ',
            [
                'statusCancelled' => Message::STATUS_CANCELLED,
                'outgoing'        => Message::DIRECTION_OUTGOING,
                'binaryIds'       => array_map(fn($uuid) => $uuid->toBinary(), $conversationIds),
            ],
            [
                'binaryIds'       => ArrayParameterType::BINARY,
            ]
        )->fetchAllAssociative();

        // Indexamos por string canónico para lookup O(1)
        // $row['convId'] ya viene como "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
        $lastMessageMap = [];
        foreach ($lastMessageData as $row) {
            $lastMessageMap[$row['convId']] = $row;
        }

        $io->progressStart($total);
        $now         = new DateTime();
        $countSynced = 0;

        // 3. LOOP DE SANIDAD
        foreach ($conversationIds as $uuid) {
            $conversation = $repository->find($uuid);

            if (!$conversation) {
                $io->progressAdvance();
                continue;
            }

            // (string) sobre un UuidV7 de Symfony siempre da el canónico con guiones
            $key = (string) $uuid;

            if (isset($lastMessageMap[$key]) && $lastMessageMap[$key]['lastReal'] !== null) {
                $conversation->setLastMessageAt(new DateTime($lastMessageMap[$key]['lastReal']));

                // Si el último mensaje real fue saliente, reseteamos no leídos
                if ($lastMessageMap[$key]['lastOutgoing'] === $lastMessageMap[$key]['lastReal']) {
                    $conversation->setUnreadCount(0);
                }
            } else {
                // Sin mensajes reales: preservamos createdAt como ancla cronológica
                $conversation->setLastMessageAt($conversation->getCreatedAt());
            }

            // ARCHIVADO AUTOMÁTICO
            $milestones = $conversation->getContextMilestones();
            if (isset($milestones['end'])) {
                $endDate = new DateTime($milestones['end']);

                if ($endDate < $now
                    && $now->diff($endDate)->days > 7
                    && $conversation->getStatus() === MessageConversation::STATUS_OPEN
                ) {
                    $conversation->setStatus(MessageConversation::STATUS_ARCHIVED);
                }
            }

            $countSynced++;
            $io->progressAdvance();

            // $lastMessageMap es array plano — sobrevive al clear() sin problema
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