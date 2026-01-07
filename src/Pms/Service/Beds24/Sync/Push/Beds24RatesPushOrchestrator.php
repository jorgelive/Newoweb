<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Sync\Push;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsTarifaQueue;
use App\Pms\Entity\PmsTarifaQueueDelivery;
use App\Pms\Repository\PmsTarifaQueueDeliveryRepository;
use App\Pms\Service\Beds24\Client\Beds24RatesCalendarPostClient;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestrator:
 * - Claimea deliveries (lock atómico via repo).
 * - Agrupa por Beds24Config (1 request por cuenta).
 * - Construye payload (sin keys vacías, y `to = fechaFin - 1 day` ya en builder).
 * - Ejecuta CALENDAR_POST.
 * - Marca deliveries success/fail + guarda auditoría (request/response/httpCode).
 * - Refresca estado agregado del Queue.
 *
 * Nota: este orquestador asume que el repo ya hace:
 * - watchdog TTL
 * - claim/lock atómico
 * - orden por beds24_config_id (para batching y fairness)
 */
final class Beds24RatesPushOrchestrator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsTarifaQueueDeliveryRepository $deliveryRepo,
        private readonly Beds24RatesPushPayloadBuilder $payloadBuilder,
        private readonly Beds24RatesCalendarPostClient $client,
    ) {}

    /**
     * @return int Cantidad de deliveries procesados (success + failed + noop)
     */
    public function run(
        int $limit,
        string $workerId,
        DateTimeImmutable $now,
        int $processingTtlSeconds = 90
    ): int {
        $deliveries = $this->deliveryRepo->claimRunnableForRatesPush(
            $limit,
            $workerId,
            $now,
            $processingTtlSeconds
        );

        if ($deliveries === []) {
            return 0;
        }

        // 1) Agrupar por config (una request por cuenta Beds24).
        $byConfig = [];
        foreach ($deliveries as $d) {
            if (!$d instanceof PmsTarifaQueueDelivery) {
                continue;
            }

            $cfg = $d->getBeds24Config();
            if (!$cfg instanceof Beds24Config || $cfg->getId() === null) {
                // Si algo está roto en datos, fallamos rápido con retry (sin romper el worker).
                $this->markFailure($d, $now, null, 'beds24_config_missing', 'Beds24Config missing en delivery.');
                continue;
            }

            $byConfig[(int) $cfg->getId()][] = $d;
        }

        $processed = 0;

        foreach ($byConfig as $cfgId => $cfgDeliveries) {
            $cfg = $cfgDeliveries[0]->getBeds24Config();
            if (!$cfg instanceof Beds24Config) {
                continue;
            }

            // 2) Filtrar solo deliveries cuyo queue.endpoint sea CALENDAR_POST (defensa).
            $calendarDeliveries = [];
            foreach ($cfgDeliveries as $d) {
                $q = $d->getQueue();
                $accion = $q?->getEndpoint()?->getAccion();

                if ($accion === 'CALENDAR_POST') {
                    $calendarDeliveries[] = $d;
                } else {
                    $this->markFailure(
                        $d,
                        $now,
                        null,
                        'wrong_endpoint',
                        'Delivery apunta a endpoint distinto de CALENDAR_POST.'
                    );
                    $processed++;
                }
            }

            if ($calendarDeliveries === []) {
                continue;
            }

            // 3) Build payload (reglas: no keys vacías, to = fechaFin-1 ya aplicado).
            //
            // Opción B:
            // - el builder devuelve:
            //   - payload: lo que sí se enviará
            //   - skipped: deliveries que NO se envían (p.ej. rango completamente pasado para Beds24)
            // - los skipped se cierran como SUCCESS/NOOP con mensaje, para que no reintenten infinito.
            $beds24Now = $now->setTimezone(new DateTimeZone('America/Lima')); // o un timezone configurable por cuenta
            $result = $this->payloadBuilder->buildWithSkipped($calendarDeliveries, $beds24Now);

            /** @var array $payload */
            $payload = $result['payload'] ?? [];
            /** @var array<int, array{reason:string, meta: array<string, mixed>}> $skipped */
            $skipped = $result['skipped'] ?? [];

            // 3.A) Marcar skipped como SUCCESS/NOOP (no se envían, pero se cierran)
            if ($skipped !== []) {
                foreach ($calendarDeliveries as $d) {
                    $id = (int) ($d->getId() ?? 0);
                    if ($id === 0 || !isset($skipped[$id])) {
                        continue;
                    }

                    $info = $skipped[$id];
                    $reason = (string) ($info['reason'] ?? 'skipped');
                    $meta = (array) ($info['meta'] ?? []);

                    $msg = 'noop: ' . $reason;
                    if ($meta !== []) {
                        $msg .= ' | ' . (json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
                    }

                    $d->setLastRequestJson(null);
                    $d->setLastResponseJson(null);
                    $d->setLastHttpCode(null);
                    $d->setPayloadHash(null);
                    $d->setLastMessage(mb_substr($msg, 0, 240));
                    $d->markSuccess($now);
                    $processed++;
                }
            }

            // 3.B) Payload vacío: nada que enviar (los skipped ya fueron cerrados arriba).
            if ($payload === []) {
                $this->refreshQueuesFromDeliveries($calendarDeliveries);
                $this->em->flush();
                continue;
            }

            $requestJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
            $payloadHash = $requestJson !== null ? hash('sha256', $requestJson) : null;

            try {
                // 4) Push a Beds24 usando el cliente (que resuelve CALENDAR_POST desde BD).
                $meta = $this->client->calendarPostWithMeta($cfg, $payload);

                $httpCode = (int) ($meta['httpCode'] ?? 0);
                $data = $meta['data'] ?? [];
                $responseJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;

                // 5) Éxito global (en rates, normalmente es global; si mañana hay per-item, se ajusta aquí).
                foreach ($calendarDeliveries as $d) {
                    $id = (int) ($d->getId() ?? 0);
                    if ($id !== 0 && isset($skipped[$id])) {
                        continue; // ya fue cerrado como noop-success
                    }

                    $d->setLastRequestJson($requestJson);
                    $d->setPayloadHash($payloadHash);
                    $d->setLastHttpCode($httpCode);
                    $d->setLastResponseJson($responseJson);
                    $d->markSuccess($now);
                    $processed++;
                }
            } catch (\Throwable $e) {
                // 6) Error global -> failure con retry/backoff.
                $httpCode = $this->client->getLastHttpCode();
                $raw = $this->client->getLastRawBody();

                foreach ($calendarDeliveries as $d) {
                    $id = (int) ($d->getId() ?? 0);
                    if ($id !== 0 && isset($skipped[$id])) {
                        continue; // ya fue cerrado como noop-success
                    }

                    $d->setLastRequestJson($requestJson);
                    $d->setPayloadHash($payloadHash);
                    $d->setLastHttpCode($httpCode);
                    $d->setLastResponseJson($raw);

                    $this->markFailure(
                        $d,
                        $now,
                        $httpCode,
                        'beds24_error',
                        $e->getMessage()
                    );

                    $processed++;
                }
            }

            // 7) Refrescar estado agregado del queue y persistir.
            $this->refreshQueuesFromDeliveries($calendarDeliveries);
            $this->em->flush();
        }

        return $processed;
    }

    private function refreshQueuesFromDeliveries(array $deliveries): void
    {
        $touched = [];

        foreach ($deliveries as $d) {
            if (!$d instanceof PmsTarifaQueueDelivery) {
                continue;
            }

            $q = $d->getQueue();
            if (!$q instanceof PmsTarifaQueue || $q->getId() === null) {
                continue;
            }

            $touched[(int) $q->getId()] = $q;
        }

        foreach ($touched as $q) {
            $q->refreshAggregateStatusFromDeliveries();
        }
    }

    private function markFailure(
        PmsTarifaQueueDelivery $delivery,
        DateTimeImmutable $now,
        ?int $httpCode,
        ?string $failedReason,
        string $message
    ): void {
        // Backoff simple y estable:
        // retryCount inicia en 0, y markFailure lo incrementa.
        $retry = (int) ($delivery->getRetryCount() ?? 0);

        // 30s, 60s, 120s, 240s... cap 30 min
        $delaySeconds = min(1800, (int) (30 * (2 ** max(0, $retry))));
        $nextRetryAt = $now->add(new DateInterval('PT' . max(30, $delaySeconds) . 'S'));

        $delivery->markFailure(
            mb_substr($message, 0, 240),
            $httpCode,
            $nextRetryAt,
            $failedReason
        );
    }
}