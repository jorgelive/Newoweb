<?php
declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Service\Beds24\Sync\Pull\Beds24BookingsPullOrchestrator;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pms:beds24:pull-queue:work',
    description: 'Worker Beds24: claimea y procesa jobs de pms_pull_queue_job.'
)]
final class Beds24PullQueueWorkCommand extends Command
{
    public function __construct(
        private readonly Beds24BookingsPullOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('worker-id', null, InputOption::VALUE_OPTIONAL, 'ID del worker (para lockedBy)', gethostname() ?: 'beds24-worker')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Procesa solo 1 job y sale')
            ->addOption('sleep', null, InputOption::VALUE_OPTIONAL, 'Segundos a dormir cuando no hay jobs (solo si no usas --once)', 3)
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Máximo de jobs a procesar (0 = infinito si no usas --once)', 0)
            ->addOption('ttl', null, InputOption::VALUE_OPTIONAL, 'TTL segundos para watchdog/locks (running viejo => pending)', 90)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = (string) $input->getOption('worker-id');
        $once = (bool) $input->getOption('once');
        $sleep = max(1, (int) $input->getOption('sleep'));
        $limit = max(0, (int) $input->getOption('limit'));
        $ttl = max(30, (int) $input->getOption('ttl'));

        $output->writeln('<info>▶ Beds24 PullQueue worker</info>');

        $processed = 0;

        while (true) {
            $ran = $this->orchestrator->workOnce($workerId, new DateTimeImmutable('now'), $ttl);

            if ($ran === 0) {
                $output->writeln('<comment>No hay jobs elegibles.</comment>');

                if ($once) {
                    break;
                }

                sleep($sleep);
                continue;
            }

            $processed += $ran;
            $output->writeln('<info>✔ Job procesado</info>');

            if ($once) {
                break;
            }

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $output->writeln(sprintf('<info>✔ Fin. Jobs procesados: %d</info>', $processed));

        return Command::SUCCESS;
    }
}