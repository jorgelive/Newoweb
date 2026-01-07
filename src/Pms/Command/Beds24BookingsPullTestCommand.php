<?php
declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Service\Beds24\Sync\Pull\Beds24BookingsPullOrchestrator;
use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsPullQueueJob;
use App\Pms\Entity\PmsUnidad;
use Doctrine\ORM\EntityManagerInterface;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pms:beds24:bookings:pull-test',
    description: 'Sincroniza bookings Beds24 por rango de llegada (test, filtrado por roomIds vía maps).'
)]
final class Beds24BookingsPullTestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Beds24BookingsPullOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('job-id', null, InputOption::VALUE_OPTIONAL, 'ID de PmsPullQueueJob (si se envía, se ejecuta ese job)')
            ->addOption('unidades', null, InputOption::VALUE_OPTIONAL, 'IDs de PmsUnidad separados por coma (ej: 1,2,3)')
            ->addOption('config-id', null, InputOption::VALUE_OPTIONAL, 'ID de Beds24Config (si se omite, usa la primera activa)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'arrivalFrom YYYY-MM-DD', (new DateTimeImmutable('-30 days'))->format('Y-m-d'))
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'arrivalTo YYYY-MM-DD', (new DateTimeImmutable('+30 days'))->format('Y-m-d'))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobIdOpt = $input->getOption('job-id');

        // 1) Si viene job-id, probamos el flujo real del orchestrator (runJob)
        if ($jobIdOpt !== null) {
            $jobId = (int) $jobIdOpt;

            /** @var PmsPullQueueJob|null $job */
            $job = $this->em->getRepository(PmsPullQueueJob::class)->find($jobId);

            if (!$job instanceof PmsPullQueueJob) {
                $output->writeln('<error>No existe PmsPullQueueJob con id: ' . $jobId . '</error>');
                return Command::FAILURE;
            }

            $processed = $this->orchestrator->runJob($job);

            $output->writeln(sprintf(
                '<info>OK. Job #%d procesado. Procesados: %d</info>',
                (int) ($job->getId() ?? 0),
                $processed
            ));

            return Command::SUCCESS;
        }

        // 2) Si NO viene job-id, armamos un job efímero (no persistido) para testear el orchestrator
        $configIdOpt = $input->getOption('config-id');
        $configId = $configIdOpt !== null ? (int) $configIdOpt : null;
        $from = new DateTimeImmutable((string) $input->getOption('from'));
        $to = new DateTimeImmutable((string) $input->getOption('to'));

        $config = $this->resolveConfig($configId);

        $job = new PmsPullQueueJob();
        $job->setBeds24Config($config);
        $job->setArrivalFrom($from);
        $job->setArrivalTo($to);
        $job->setRunAt(new DateTimeImmutable('now'));

        $unidadesOpt = (string) ($input->getOption('unidades') ?? '');
        $unidadesOpt = trim($unidadesOpt);

        if ($unidadesOpt !== '') {
            $ids = array_filter(array_map('trim', explode(',', $unidadesOpt)), static fn ($v) => $v !== '');

            foreach ($ids as $idRaw) {
                $id = (int) $idRaw;
                if ($id <= 0) {
                    continue;
                }

                /** @var PmsUnidad|null $u */
                $u = $this->em->getRepository(PmsUnidad::class)->find($id);
                if ($u instanceof PmsUnidad) {
                    $job->addUnidad($u);
                }
            }
        }

        $processed = $this->orchestrator->runJob($job);

        if ($processed === 0) {
            $output->writeln('<comment>No hay roomIds activos (maps) o no hay bookings para sincronizar.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>OK. Procesados: ' . $processed . '</info>');
        return Command::SUCCESS;
    }

    private function resolveConfig(?int $configId): Beds24Config
    {
        $repo = $this->em->getRepository(Beds24Config::class);

        if ($configId) {
            $config = $repo->find($configId);
            if ($config instanceof Beds24Config) {
                return $config;
            }
        }

        // Preferimos una config activa si existe
        $qb = $repo->createQueryBuilder('c');
        $qb->orderBy('c.id', 'ASC')->setMaxResults(1);

        try {
            $qb->andWhere('c.activo = :activo')->setParameter('activo', true);
        } catch (\Throwable) {
            // ignore
        }

        $config = $qb->getQuery()->getOneOrNullResult();

        if (!$config instanceof Beds24Config) {
            throw new \RuntimeException('No se encontró ninguna Beds24Config para sincronizar.');
        }

        return $config;
    }
}
