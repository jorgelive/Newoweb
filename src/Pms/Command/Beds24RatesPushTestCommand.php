<?php
declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Service\Beds24\Sync\Push\Beds24RatesPushOrchestrator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pms:beds24:rates:push:test',
    description: 'Ejecuta PUSH de tarifas Beds24 (CALENDAR_POST) desde la cola'
)]
final class Beds24RatesPushTestCommand extends Command
{
    public function __construct(
        private readonly Beds24RatesPushOrchestrator $orchestrator,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Cantidad máxima de deliveries a procesar',
                20
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'No ejecuta requests a Beds24 (solo simula el flujo)'
            )
            ->addOption(
                'worker-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Identificador del worker (útil para debug)',
                null
            )
            ->addOption(
                'ttl',
                null,
                InputOption::VALUE_OPTIONAL,
                'TTL watchdog (segundos)',
                90
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit  = (int) $input->getOption('limit');
        $dryRun = (bool) $input->getOption('dry-run');
        $ttl    = (int) $input->getOption('ttl');

        $workerId = $input->getOption('worker-id')
            ?: 'cli-rates-' . substr(sha1((string) microtime(true)), 0, 8);

        $now = new DateTimeImmutable();

        $output->writeln('');
        $output->writeln('<info>Beds24 RATES PUSH TEST</info>');
        $output->writeln('Worker: ' . $workerId);
        $output->writeln('Limit: ' . $limit);
        $output->writeln('TTL: ' . $ttl . 's');
        $output->writeln('Dry-run: ' . ($dryRun ? 'YES' : 'NO'));
        $output->writeln('');

        if ($dryRun) {
            $output->writeln('<comment>⚠ DRY-RUN activo</comment>');
            $output->writeln('Se ejecuta el claim + build, pero NO se envía nada.');
            $output->writeln('');
        }

        // ------------------------------------------------------------
        // En modo dry-run:
        // - Ejecutamos el orchestrator
        // - ROLLBACK al final (no deja locks ni estados tocados)
        // ------------------------------------------------------------
        if ($dryRun) {
            $this->em->beginTransaction();
        }

        try {
            $processed = $this->orchestrator->run(
                $limit,
                $workerId,
                $now,
                $ttl
            );

            if ($dryRun) {
                $this->em->rollback();
                $output->writeln('');
                $output->writeln('<comment>Dry-run finalizado (rollback aplicado)</comment>');
            } else {
                $output->writeln('');
                $output->writeln(sprintf(
                    '<info>Procesados %d delivery(s)</info>',
                    $processed
                ));
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            if ($dryRun && $this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }

            $output->writeln('');
            $output->writeln('<error>Error ejecutando rates push</error>');
            $output->writeln($e->getMessage());

            return Command::FAILURE;
        }
    }
}