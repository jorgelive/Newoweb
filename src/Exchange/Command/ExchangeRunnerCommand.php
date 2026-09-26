<?php
declare(strict_types=1);

namespace App\Exchange\Command;

use App\Command\EntradaDeConsola;
use App\Exchange\Service\Engine\ExchangeOrchestrator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ExchangeRunnerCommand
 * * --- PROPÓSITO ---
 * Este es el MOTOR DE EJECUCIÓN (Runner). Su función es consumir las colas generadas
 * por el TimelineEnqueuer y realizar las llamadas reales a la API de Beds24.
 * * --- RELACIÓN CON EL ENQUEUER ---
 * 1. TimelineEnqueuerCommand: Encuentra cambios y los mete en la tabla '_queue'.
 * 2. ExchangeRunnerCommand (Este): Lee la tabla '_queue' y envía los datos.
 * * --- EJEMPLOS DE USO ---
 * @example php bin/console exchange:run bookings_pull              -> Ejecuta importación de reservas pendientes.
 * @example php bin/console exchange:run bookings_push              -> Envía cambios de reservas a Beds24.
 * @example php bin/console exchange:run rates_push                 -> Envía actualizaciones de precios a Beds24.
 * @example php bin/console exchange:run rates_push --limit=100     -> Procesa un lote más grande.
 * @example php bin/console exchange:run beds24_message_send        -> Envía los mensajes a Beds24.
 * @example php bin/console exchange:run beds24_message_receive     -> Recibe los mensajes a Beds24.
 * @example php bin/console exchange:run whatsapp_meta_message_send -> Envia los mensajes a Whatsapp.
 */
#[AsCommand(
    name: 'exchange:run', // Nombre semántico: Acción + Ejecución
    description: 'Ejecuta el motor de intercambio para procesar los ítems pendientes en una cola específica.',
)]
class ExchangeRunnerCommand extends Command
{
    public function __construct(
        private readonly ExchangeOrchestrator $orchestrator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<EOT
Este comando dispara el motor de sincronización real (consumidor de colas).
Busca registros con estado 'pending' o 'failed' (que tengan reintentos) y los procesa.

Ejemplos:
  <info>php bin/console exchange:run bookings_pull</info>
  <info>php bin/console exchange:run bookings_push --limit=10</info>
EOT
        );
    }

    /**
     * El argumento y la opción los tipa el framework (`#[Argument]`, `#[Option]`): antes se leían
     * con `getArgument()`/`getOption()`, que devuelven `mixed`, y se convertían a mano.
     */
    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Argument('Nombre de la tarea/cola: [bookings_pull | bookings_push | rates_push]')]
        string $task,
        #[Option('Cantidad máxima de ítems a procesar en esta ejecución', shortcut: 'l')]
        string $limit = '50',
    ): int {
        // Texto y no `int` en la firma: con `int`, un `--limit=abc` revienta con un `TypeError` de
        // la reflexión que no nombra la opción. Así falla como el resto de comandos.
        $limit = EntradaDeConsola::entero($limit, 'limit');
        $io->title("Iniciando Runner de Intercambio: <comment>$task</comment>");
        $io->note("Buscando hasta $limit ítems pendientes para procesar.");

        try {
            // El Orchestrator se encarga del loop, el bloqueo de filas (Locking),
            // las transacciones y el registro de resultados/errores en la BD.
            $this->orchestrator->run($task, $limit);

            $io->success("Procesamiento de '$task' finalizado.");
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
