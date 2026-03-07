<?php
declare(strict_types=1);

namespace App\Exchange\Command;

use App\Exchange\Service\Cron\TimelineEnqueuerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * TimelineEnqueuerCommand
 * * * --- PROPÓSITO ---
 * Actúa como la interfaz de línea de comandos para ejecutar el motor de colas.
 * Delega toda la lógica de negocio al servicio especializado TimelineEnqueuerService.
 * * * --- GENERADORES DISPONIBLES ---
 * @example php bin/console exchange:timeline:enqueue beds24_bookings_pull_arrival
 * @example php bin/console exchange:timeline:enqueue beds24_bookings_push
 * @example php bin/console exchange:timeline:enqueue beds24_rates_push
 * @example php bin/console exchange:timeline:enqueue beds24_message_pull
 */
#[AsCommand(
    name: 'exchange:timeline:enqueue', // Nombre altamente semántico: Acción + Contexto
    description: 'Recorre el timeline y genera (encola) las tareas de sincronización pendientes.'
)]
class TimelineEnqueuerCommand extends Command
{
    public function __construct(
        private readonly TimelineEnqueuerService $timelineEnqueuerService
    ) {
        parent::__construct();
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

        try {
            $isSuccess = $this->timelineEnqueuerService->enqueue($jobName, $io);

            return $isSuccess ? Command::SUCCESS : Command::FAILURE;

        } catch (\Throwable $e) {
            $io->error("Error crítico en la generación de colas: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}