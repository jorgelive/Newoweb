<?php
declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsBeds24LinkQueue;
use App\Pms\Repository\PmsBeds24LinkQueueRepository;
use App\Pms\Service\Beds24\Queue\Beds24LinkQueueProcessor;
use App\Pms\Service\Beds24\Sync\Push\Beds24BookingsPushPayloadBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pms:beds24:push:test',
    description: 'Ejecuta PUSH de Beds24 desde la cola (modo test / dry-run opcional)'
)]
final class Beds24PushTestCommand extends Command
{
    public function __construct(
        private readonly PmsBeds24LinkQueueRepository     $queueRepo,
        private readonly Beds24LinkQueueProcessor         $processor,
        private readonly EntityManagerInterface           $em,
        private readonly Beds24BookingsPushPayloadBuilder $payloadBuilder,
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
                'Cantidad máxima de colas a procesar',
                20
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'No ejecuta requests a Beds24, solo muestra qué se procesaría'
            )
            ->addOption(
                'worker-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Identificador del worker (útil para debug)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit    = (int) $input->getOption('limit');
        $dryRun   = (bool) $input->getOption('dry-run');
        $workerId = $input->getOption('worker-id')
            ?: 'cli-test-' . substr(sha1((string) microtime(true)), 0, 8);

        $now = new DateTimeImmutable();

        $output->writeln('');
        $output->writeln('<info>Beds24 PUSH TEST</info>');
        $output->writeln('Worker: ' . $workerId);
        $output->writeln('Limit: ' . $limit);
        $output->writeln('Dry-run: ' . ($dryRun ? 'YES' : 'NO'));
        $output->writeln('');

        // ------------------------------------------------------------------
        // 1) Claim de colas (lock real, incluso en dry-run)
        //
        // Importante (lección aprendida):
        // - NO debemos "claim-earear" 2 veces.
        // - Si hacemos claim aquí, luego NO llamamos al Orchestrator->runOnce()
        //   porque ese método vuelve a claimRunnable() y ya no encuentra nada.
        //
        // Por eso: en modo real, llamamos directamente al processor->processBatch()
        // con las colas ya bloqueadas/hidratadas.
        // ------------------------------------------------------------------
        $queues = $this->queueRepo->claimRunnable($limit, $workerId, $now);

        if ($queues === []) {
            $output->writeln('<comment>No hay colas ejecutables.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>%d cola(s) reclamadas</info>',
            count($queues)
        ));

        // ------------------------------------------------------------------
        // 2) Dry-run: solo mostrar y liberar locks
        // ------------------------------------------------------------------
        if ($dryRun) {
            foreach ($queues as $q) {
                $output->writeln(sprintf(
                    '- Queue #%d | %s | endpoint=%s | config=%s',
                    $q->getId(),
                    $q->getStatus(),
                    $q->getEndpoint()?->getAccion() ?? '¿?',
                    $q->getBeds24Config()?->__toString() ?? '¿?'
                ));

                // Payload solo en dry-run (útil para copiar/pegar y validar con curl)
                $action = $q->getEndpoint()?->getAccion();
                if ($action === 'POST_BOOKINGS' || $action === 'DELETE_BOOKINGS') {
                    try {
                        $payload = ($action === 'POST_BOOKINGS')
                            ? $this->payloadBuilder->buildPostPayload($q)
                            : $this->payloadBuilder->buildDeletePayload($q);

                        $jsonPayload = json_encode(
                            $payload,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        );

                        if ($jsonPayload !== false) {
                            $output->writeln('  Payload:');
                            foreach (explode("\n", $jsonPayload) as $line) {
                                $output->writeln('  ' . $line);
                            }
                        }
                    } catch (\Throwable $e) {
                        $output->writeln('  <comment>Warning: failed to build payload: ' . $e->getMessage() . '</comment>');
                    }
                }

                // Importante:
                // liberamos el lock manualmente porque NO se ejecutará processor
                //
                // Nota watchdog:
                // - también limpiamos processingStartedAt para no dejar "zombis"
                // - y devolvemos el status a pending para que pueda re-ejecutarse
                $q->setLockedAt(null);
                $q->setLockedBy(null);

                if (method_exists($q, 'setProcessingStartedAt')) {
                    $q->setProcessingStartedAt(null);
                }

                $q->setStatus(PmsBeds24LinkQueue::STATUS_PENDING);
            }

            $this->em->flush();

            $output->writeln('');
            $output->writeln('<comment>Dry-run finalizado. No se envió nada a Beds24.</comment>');

            return Command::SUCCESS;
        }

        // ------------------------------------------------------------------
        // 3) Ejecución REAL
        //
        // Importante:
        // - NO llamamos al Orchestrator->runOnce() porque eso haría un 2do claim.
        // - En su lugar, ejecutamos el processor sobre las colas ya reclamadas.
        // ------------------------------------------------------------------
        $processed = $this->processor->processBatch($queues, $now, $workerId);

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Procesadas %d cola(s)</info>',
            $processed
        ));

        return Command::SUCCESS;
    }
}