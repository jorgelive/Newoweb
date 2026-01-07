<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Sync\Push;

use App\Pms\Entity\PmsTarifaQueueDelivery;
use DateTimeImmutable;

final class Beds24RatesPushPayloadBuilder
{
    /**
     * @param PmsTarifaQueueDelivery[] $deliveries
     *
     * @return array{
     *   payload: array<int, array{roomId:int, calendar: array<int, array<string, mixed>>}>,
     *   skipped: array<int, array{reason:string, meta: array<string, mixed>}>
     * }
     */
    public function buildWithSkipped(array $deliveries, DateTimeImmutable $beds24Now): array
    {
        $rooms = [];
        $skipped = [];

        $today = $beds24Now->setTime(0, 0, 0);

        foreach ($deliveries as $delivery) {
            $deliveryId = (int) ($delivery->getId() ?? 0);

            $queue = $delivery->getQueue();
            $map   = $delivery->getUnidadBeds24Map();

            if ($queue === null || $map === null) {
                if ($deliveryId > 0) {
                    $skipped[$deliveryId] = [
                        'reason' => 'missing_queue_or_map',
                        'meta' => [],
                    ];
                }
                continue;
            }

            $roomId = $map->getBeds24RoomId();
            if ($roomId === null) {
                if ($deliveryId > 0) {
                    $skipped[$deliveryId] = [
                        'reason' => 'missing_room_id',
                        'meta' => [],
                    ];
                }
                continue;
            }

            $from = $queue->getFechaInicio();
            $to   = $queue->getFechaFin();

            if ($from === null || $to === null) {
                if ($deliveryId > 0) {
                    $skipped[$deliveryId] = [
                        'reason' => 'missing_dates',
                        'meta' => ['roomId' => (int) $roomId],
                    ];
                }
                continue;
            }

            $fromDay = DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);
            $toDay   = DateTimeImmutable::createFromInterface($to)->setTime(0, 0, 0)->modify('-1 day'); // inclusivo

            // Totalmente pasado para Beds24 => NO enviar
            if ($toDay < $today) {
                if ($deliveryId > 0) {
                    $skipped[$deliveryId] = [
                        'reason' => 'range_fully_in_past_for_beds24',
                        'meta' => [
                            'roomId' => (int) $roomId,
                            'from' => $fromDay->format('Y-m-d'),
                            'to' => $toDay->format('Y-m-d'),
                            'beds24Today' => $today->format('Y-m-d'),
                        ],
                    ];
                }
                continue;
            }

            // Recorte de inicio si cae antes del "today" Beds24
            $cropped = false;
            $originalFrom = $fromDay;

            if ($fromDay < $today) {
                $fromDay = $today;
                $cropped = true;
            }

            // Si queda inválido después del recorte
            if ($toDay < $fromDay) {
                if ($deliveryId > 0) {
                    $skipped[$deliveryId] = [
                        'reason' => 'range_invalid_after_crop',
                        'meta' => [
                            'roomId' => (int) $roomId,
                            'fromOriginal' => $originalFrom->format('Y-m-d'),
                            'fromCropped' => $fromDay->format('Y-m-d'),
                            'to' => $toDay->format('Y-m-d'),
                            'beds24Today' => $today->format('Y-m-d'),
                        ],
                    ];
                }
                continue;
            }

            $item = [
                'from' => $fromDay->format('Y-m-d'),
                'to'   => $toDay->format('Y-m-d'),
            ];

            if ($queue->getPrecio() !== null && $queue->getPrecio() !== '') {
                $item['price1'] = number_format((float) $queue->getPrecio(), 2, '.', '');
            }

            if ($queue->getMinStay() !== null) {
                $item['minStay'] = (int) $queue->getMinStay();
            }

            // Si no hay cambios reales, no enviar
            if (count($item) <= 2) {
                if ($deliveryId > 0) {
                    $skipped[$deliveryId] = [
                        'reason' => 'noop_no_fields_to_push',
                        'meta' => [
                            'roomId' => (int) $roomId,
                            'from' => $item['from'],
                            'to' => $item['to'],
                            'cropped' => $cropped,
                        ],
                    ];
                }
                continue;
            }

            if (!isset($rooms[$roomId])) {
                $rooms[$roomId] = [
                    'roomId'   => (int) $roomId,
                    'calendar' => [],
                ];
            }

            $rooms[$roomId]['calendar'][] = $item;

            // Opcional: si quieres auditar que hubo recorte, lo marcas como skipped-info aparte
            // (pero normalmente no hace falta; el requestJson ya lo refleja).
        }

        // Deduplicación exacta por room
        foreach ($rooms as &$room) {
            $uniq = [];
            $out  = [];

            foreach ($room['calendar'] as $entry) {
                $key = md5(json_encode($entry));
                if (isset($uniq[$key])) {
                    continue;
                }
                $uniq[$key] = true;
                $out[] = $entry;
            }
            $room['calendar'] = $out;
        }

        return [
            'payload' => array_values($rooms),
            'skipped' => $skipped,
        ];
    }
}