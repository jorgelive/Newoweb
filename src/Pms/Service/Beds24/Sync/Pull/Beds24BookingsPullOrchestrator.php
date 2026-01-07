<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Sync\Pull;

use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsPullQueueJob;
use App\Pms\Repository\PmsPullQueueJobRepository;
use App\Pms\Repository\PmsUnidadBeds24MapRepository;
use App\Pms\Service\Beds24\Sync\Pull\Resolver\Beds24BookingResolverInterface;
use App\Pms\Service\Beds24\Sync\SyncContext;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

final class Beds24BookingsPullOrchestrator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Beds24BookingsPullService $pullService,
        private readonly Beds24BookingResolverInterface $resolver,
        private readonly SyncContext $syncContext,
        private readonly PmsUnidadBeds24MapRepository $mapRepository,
        private readonly PmsPullQueueJobRepository $jobRepository,
    ) {}

    /**
     * Ejecuta un callback dentro de un source (PULL) y restaura al finalizar.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function runInSource(string $source, callable $fn): mixed
    {
        $previous = $this->syncContext->getSource();
        $this->syncContext->setSource($source);

        try {
            return $fn();
        } finally {
            $this->syncContext->setSource($previous);
        }
    }

    /**
     * Worker simple, 100% Beds24:
     * - Claimea 1 job (lock atómico en repo)
     * - Ejecuta runJob()
     * - Marca DONE / FAILED y persiste auditoría
     *
     * Nota:
     * - El claim debe ser atómico para evitar doble ejecución entre workers.
     * - Este método no hace loop infinito; el loop lo decide el Command/cron.
     *
     * @return int 1 si ejecutó 1 job, 0 si no había jobs elegibles.
     */
    public function workOnce(string $workerId, ?DateTimeImmutable $now = null, int $processingTtlSeconds = 90): int
    {
        $now ??= new DateTimeImmutable('now');

        $job = $this->jobRepository->claimNextRunnable(
            type: PmsPullQueueJob::TYPE_BEDS24_BOOKINGS_ARRIVAL_RANGE,
            workerId: $workerId,
            now: $now,
            processingTtlSeconds: $processingTtlSeconds,
        );

        if (!$job instanceof PmsPullQueueJob) {
            return 0;
        }

        try {
            $processed = $this->runJob($job);

            $job->setStatus(PmsPullQueueJob::STATUS_DONE);
            $job->setLastError(null);

            // No pisamos la meta que ya setea runJob(); solo agregamos/actualizamos processed.
            $job->setResponseMeta(array_merge(
                (array) ($job->getResponseMeta() ?? []),
                ['processed' => $processed]
            ));
        } catch (\Throwable $e) {
            $job->setStatus(PmsPullQueueJob::STATUS_FAILED);
            $job->setLastError(mb_substr($e->getMessage(), 0, 2000));
        }

        $this->em->flush();

        return 1;
    }

    /**
     * Ejecuta el job completo (lógica Beds24).
     *
     * Responsabilidad:
     * - Validar config/fechas
     * - Resolver roomIds desde maps (config + unidades opcionales)
     * - Guardar payloadComputed (auditoría técnica del job)
     * - Pull + upsert + flush (en 1 boundary)
     * - Guardar responseMeta (resultado técnico)
     *
     * Nota:
     * - NO maneja locks/attempts/status (eso lo hace el claim del repo + workOnce()).
     */
    public function runJob(PmsPullQueueJob $job): int
    {
        $config = $job->getBeds24Config();
        $from = $job->getArrivalFrom();
        $to = $job->getArrivalTo();

        if (!$config instanceof Beds24Config || !$from instanceof DateTimeInterface || !$to instanceof DateTimeInterface) {
            throw new \RuntimeException('Job mal formado: falta config o rango de arrival.');
        }

        $unidades = $job->getUnidades()->toArray();

        // rooms = filtro real para Beds24
        $roomIds = $this->mapRepository->findRoomIdsForPull($config, $unidades);

        $job->setPayloadComputed([
            'roomIds' => $roomIds,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'unidadesCount' => count($unidades),
        ]);

        if ($roomIds === []) {
            $job->setResponseMeta([
                'processed' => 0,
                'roomCount' => 0,
                'reason' => 'no-room-ids',
            ]);

            return 0;
        }

        $processed = $this->syncByArrivalRangeByRoomIds($config, $roomIds, $from, $to);

        $job->setResponseMeta([
            'processed' => $processed,
            'roomCount' => count($roomIds),
        ]);

        $job->setLockedAt(null);
        $job->setLockedBy(null);

        return $processed;
    }

    /**
     * Pull → Upsert por rango de llegada filtrando por roomId.
     *
     * @param int[] $roomIds
     */
    private function syncByArrivalRangeByRoomIds(
        Beds24Config $config,
        array $roomIds,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): int {
        return $this->runInSource(SyncContext::SOURCE_PULL_BEDS24, function () use ($config, $roomIds, $from, $to): int {
            $bookings = $this->pullService->pullByArrivalRangeByRooms($config, $roomIds, $from, $to);

            if ($bookings === []) {
                return 0;
            }

            $conn = $this->em->getConnection();
            $conn->beginTransaction();

            try {
                $processed = 0;

                foreach ($bookings as $booking) {
                    $dto = Beds24BookingDto::fromArray($booking);
                    $this->resolver->upsert($config, $dto);
                    $processed++;
                }

                $this->em->flush();
                $conn->commit();

                return $processed;
            } catch (\Throwable $e) {
                $conn->rollBack();

                // limpiamos el EM para evitar estados sucios si el mismo proceso sigue vivo
                try {
                    $this->em->clear();
                } catch (\Throwable) {
                    // ignore
                }

                throw $e;
            }
        });
    }
}