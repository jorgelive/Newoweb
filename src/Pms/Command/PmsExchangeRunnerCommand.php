<?php
declare(strict_types=1);

namespace App\Pms\Command;

use App\Exchange\Service\Engine\ExchangeOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * PmsExchangeRunnerCommand
 * * --- PROPÓSITO ---
 * Este es el MOTOR DE EJECUCIÓN (Runner). Su función es consumir las colas generadas
 * por el TimelineEnqueuer y realizar las llamadas reales a la API de Beds24.
 * * --- RELACIÓN CON EL ENQUEUER ---
 * 1. PmsTimelineEnqueuerCommand: Encuentra cambios y los mete en la tabla '_queue'.
 * 2. PmsExchangeRunnerCommand (Este): Lee la tabla '_queue' y envía los datos.
 * * --- EJEMPLOS DE USO ---
 * @example php bin/console pms:exchange:run bookings_pull   -> Ejecuta importación de reservas pendientes.
 * @example php bin/console pms:exchange:run bookings_push   -> Envía cambios de reservas a Beds24.
 * @example php bin/console pms:exchange:run rates_push      -> Envía actualizaciones de precios a Beds24.
 * @example php bin/console pms:exchange:run rates_push --limit=100 -> Procesa un lote más grande.
 */
#[AsCommand(
    name: 'pms:exchange:run', // Nombre semántico: Acción + Ejecución
    description: 'Ejecuta el motor de intercambio para procesar los ítems pendientes en una cola específica.',
)]
class PmsExchangeRunnerCommand extends Command
{
    public function __construct(
        private readonly ExchangeOrchestrator $orchestrator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'task',
                InputArgument::REQUIRED,
                'Nombre de la tarea/cola: [bookings_pull | bookings_push | rates_push]'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Cantidad máxima de ítems a procesar en esta ejecución',
                50
            )
            ->setHelp(<<<EOT
Este comando dispara el motor de sincronización real (consumidor de colas).
Busca registros con estado 'pending' o 'failed' (que tengan reintentos) y los procesa.

Ejemplos:
  <info>php bin/console pms:exchange:run bookings_pull</info>
  <info>php bin/console pms:exchange:run bookings_push --limit=10</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $taskName = $input->getArgument('task');
        $limit = (int) $input->getOption('limit');

        $io->title("Iniciando Runner de Intercambio: <comment>$taskName</comment>");
        $io->note("Buscando hasta $limit ítems pendientes para procesar.");

        try {
            // El Orchestrator se encarga del loop, el bloqueo de filas (Locking),
            // las transacciones y el registro de resultados/errores en la BD.
            $this->orchestrator->run($taskName, $limit);

            $io->success("Procesamiento de '$taskName' finalizado.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error("Error fatal en el motor de intercambio: " . $e->getMessage());
            // Opcional: imprimir el rastro en modo verbose
            if ($output->isVeryVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}