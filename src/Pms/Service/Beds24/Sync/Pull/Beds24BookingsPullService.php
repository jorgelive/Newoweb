<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Sync\Pull;

use App\Pms\Entity\Beds24Config;
use App\Pms\Service\Beds24\Client\Beds24BookingsGetClient;
use DateTimeInterface;

final class Beds24BookingsPullService
{
    public function __construct(
        private readonly Beds24BookingsGetClient $client,
    ) {}

    /**
     * Pull bookings por rango de llegada filtrando por roomId.
     *
     * Beds24 acepta parámetros repetidos: roomId=1111&roomId=2222
     *
     * @param int[] $roomIds
     * @return array<int,array<string,mixed>>
     */
    public function pullByArrivalRangeByRooms(Beds24Config $config, array $roomIds, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $all = [];

        $query = [
            'roomId' => array_values(array_unique($roomIds)),
            'arrivalFrom' => $from->format('Y-m-d'),
            'arrivalTo' => $to->format('Y-m-d'),
        ];

        $page = 1;

        while (true) {
            $payload = $this->client->getBookings($config, $query + ['page' => $page]);

            $items = $payload['data'] ?? [];
            if (is_array($items)) {
                foreach ($items as $b) {
                    if (is_array($b)) {
                        $all[] = $b;
                    }
                }
            }

            $nextExists = (bool) ($payload['pages']['nextPageExists'] ?? false);
            if (!$nextExists) {
                break;
            }

            $page++;
        }

        return $all;
    }
}
