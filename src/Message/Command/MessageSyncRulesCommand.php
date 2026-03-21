<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageConversation;
use App\Message\Service\MessageRuleEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Uso:
 * php bin/console app:message:sync-rules 019cea14-bdd4-769e-bd63-8abac315738c
 * php bin/console app:message:sync-rules --all
 */
#[AsCommand(
    name: 'app:message:sync-rules',
    description: 'Evalúa y programa mensajes automáticos basados en reglas para las conversaciones.',
)]
class MessageSyncRulesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageRuleEngine $ruleEngine
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('conversation_id', InputArgument::OPTIONAL, 'UUID de una conversación específica a sincronizar')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Sincroniza todas las conversaciones con estado OPEN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conversationId = $input->getArgument('conversation_id');
        $syncAll = $input->getOption('all');

        if (!$conversationId && !$syncAll) {
            $io->error('Debes proporcionar un UUID de conversación o usar la opción --all');
            return Command::FAILURE;
        }

        $repository = $this->em->getRepository(MessageConversation::class);
        $conversationIds = [];

        // 🔥 CORRECCIÓN: Solo extraemos los IDs (strings) de la base de datos, no los objetos completos
        if ($conversationId) {
            $conversationIds[] = $conversationId;
        } elseif ($syncAll) {
            $results = $repository->createQueryBuilder('c')
                ->select('c.id')
                ->where('c.status = :status')
                ->setParameter('status', MessageConversation::STATUS_OPEN)
                ->getQuery()
                ->getArrayResult();

            $conversationIds = array_column($results, 'id');
            $io->info(sprintf('Se encontraron %d conversaciones abiertas para evaluar.', count($conversationIds)));
        }

        $io->progressStart(count($conversationIds));

        $countSynced = 0;
        foreach ($conversationIds as $id) {
            try {
                // 🔥 Cargamos la entidad "fresca" en cada iteración
                $conversation = $repository->find($id);

                if ($conversation) {
                    $this->ruleEngine->syncConversationRules($conversation);
                    $countSynced++;
                }
            } catch (\Throwable $e) {
                $io->warning(sprintf('Error al procesar la conversación %s: %s', $id, $e->getMessage()));
            }

            $io->progressAdvance();

            // Liberar memoria de forma segura sin romper los objetos pendientes
            if ($syncAll && $countSynced % 50 === 0) {
                $this->em->clear();
            }
        }

        $io->progressFinish();
        $io->success(sprintf('Sincronización completada. Se evaluaron %d conversaciones.', $countSynced));

        return Command::SUCCESS;
    }
}