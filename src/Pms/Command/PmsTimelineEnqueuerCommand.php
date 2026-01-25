<?php
declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsCronCursor;
use App\Pms\Service\Cron\CronJobInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * PmsTimelineEnqueuerCommand
 * * --- PROPÓSITO ---
 * Este es el MOTOR GENERADOR DE COLAS. Su función es recorrer la línea de tiempo (timeline)
 * y encolar (enqueue) tareas pendientes para que el Worker las procese después.
 * * --- EXPLICACIÓN DE LOS TAGS (app.cron_job) ---
 * Utiliza un "TaggedIterator" para inyectar automáticamente cualquier servicio etiquetado
 * con #[AutoconfigureTag('app.cron_job')] que implemente CronJobInterface.
 * * --- GENERADORES DISPONIBLES ---
 * @example php bin/console pms:timeline:enqueue beds24_bookings_pull_arrival
 * @example php bin/console pms:timeline:enqueue beds24_bookings_push
 * @example php bin/console pms:timeline:enqueue beds24_rates_push
 */
#[AsCommand(
    name: 'pms:timeline:enqueue', // Nombre altamente semántico: Acción + Contexto
    description: 'Recorre el timeline y genera (encola) las tareas de sincronización pendientes.'
)]
class PmsTimelineEnqueuerCommand extends Command
{
    /** * @var CronJobInterface[]
     */
    private iterable $jobs;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[TaggedIterator('app.cron_job')] iterable $jobs
    ) {
        parent::__construct();
        $this->jobs = $jobs;
    }

    protected function configure(): void
    {
        $this->addArgument(
            'job',
            InputArgument::REQUIRED,
            'Identificador del generador (ej: beds24_bookings_push)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobName = $input->getArgument('job');

        // 1. Localizar el generador de colas específico
        $jobService = null;
        foreach ($this->jobs as $job) {
            if ($job->getName() === $jobName) {
                $jobService = $job;
                break;
            }
        }

        if (!$jobService) {
            $io->error("No se encontró el generador 'app.cron_job' para: $jobName");
            $availableJobs = array_map(fn($j) => $j->getName(), iterator_to_array($this->jobs));
            $io->listing($availableJobs);
            return Command::FAILURE;
        }

        $repo = $this->em->getRepository(PmsCronCursor::class);

        // 2. Punto de control (Cursor): ¿Desde dónde empezamos a encolar?
        $cursor = $repo->find($jobName);

        if (!$cursor) {
            $io->info("Inicializando cursor nuevo para $jobName (desde ayer).");
            $cursor = new PmsCronCursor($jobName);
            $cursor->setCursorDate(new DateTimeImmutable('yesterday'));
            $this->em->persist($cursor);
            $this->em->flush();
        }

        $startDate = DateTimeImmutable::createFromInterface($cursor->getCursorDate());
        $endDate = $startDate->add($jobService->getStepInterval());

        // Seguridad: Evitar que el cursor avance a fechas irreales
        $limitDate = (new DateTimeImmutable())->modify('+18 months');
        if ($startDate > $limitDate) {
            $io->warning("Reseteando cursor: demasiado avanzado en el futuro.");
            $startDate = new DateTimeImmutable('yesterday');
            $endDate = $startDate->add($jobService->getStepInterval());
        }

        $io->title("Timeline Enqueuer: $jobName");
        $io->note("Rango de generación: {$startDate->format('Y-m-d')} -> {$endDate->format('Y-m-d')}");

        try {
            // El Job Service busca los datos y crea las filas en las tablas _queue
            $jobService->execute($startDate, $endDate, $io);
        } catch (\Throwable $e) {
            $io->error("Error crítico en la generación de colas: " . $e->getMessage());
            return Command::FAILURE;
        }

        // 3. RECUPERACIÓN POST-CLEAR (Importante si el Job usó em->clear())
        $cursor = $repo->find($jobName);
        if (!$cursor) {
            $cursor = new PmsCronCursor($jobName);
            $this->em->persist($cursor);
        }

        // 4. Actualizar el cursor para la siguiente vuelta
        $cursor->setCursorDate($endDate);
        $cursor->setLastRunAt(new DateTimeImmutable());
        $this->em->flush();

        $io->success("Encolado finalizado. Timeline avanzado a {$endDate->format('Y-m-d')}.");

        return Command::SUCCESS;
    }
}