<?php

declare(strict_types=1);

namespace App\Exchange\Service\Cron\Job;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Service\Cron\CronJobInterface;
use App\Message\Service\Queue\Beds24ReceiveEnqueuer;
use App\Pms\Service\Message\PmsBeds24MessageTargetFinder;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.cron_job')]
class Beds24MessagePullJob implements CronJobInterface
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly EntityManagerInterface       $em,
        private readonly PmsBeds24MessageTargetFinder $targetFinder, // Llama al PMS
        private readonly Beds24ReceiveEnqueuer        $enqueuer     // Llama a Message
    ) {}

    public function getName(): string
    {
        return 'beds24_message_pull'; // Alias para ejecutarlo en consola
    }

    public function getStepInterval(): DateInterval
    {
        // Avanzamos de día en día. Las ventanas de mensajes son cortas y volátiles.
        return new DateInterval('P7D');
    }

    public function execute(DateTimeImmutable $from, DateTimeImmutable $to, SymfonyStyle $io): void
    {
        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'provider' => ConnectivityProvider::BEDS24,
            'accion'   => 'GET_MESSAGES', // Asegúrate de tener este slug en tu crud
            'activo'   => true
        ]);

        if (!$endpoint) {
            $io->error('No existe endpoint GET_MESSAGES activo.');
            return;
        }

        $io->text("1. Buscando reservas activas en Beds24 para el periodo...");

        $generator = $this->targetFinder->findTargetsForPeriod($from, $to);

        $count = 0;
        $totalEnqueued = 0;

        foreach ($generator as $target) {
            $bookId = $target['bookId'];
            $config = $target['config'];

            $queue = $this->enqueuer->enqueue($bookId, $config, $endpoint);

            if ($queue) {
                $totalEnqueued++;
            }

            $count++;

            // Batching para cuidar la memoria
            if ($count % self::BATCH_SIZE === 0) {
                $this->em->flush();
                $this->em->clear();
                $this->enqueuer->clearCache(); // Limpiar RAM del creator

                // Recuperar el endpoint tras el clear()
                $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->find($endpoint->getId());

                if ($io->isVerbose()) {
                    $io->write('.');
                }
            }
        }

        // Flush final
        $this->em->flush();
        $this->em->clear();
        $this->enqueuer->clearCache();

        $io->newLine();
        $io->success("Message Pull finalizado. Se han encolado $totalEnqueued trabajos nuevos.");
    }
}