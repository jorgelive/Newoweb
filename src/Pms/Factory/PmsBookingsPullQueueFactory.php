<?php
declare(strict_types=1);

namespace App\Pms\Factory;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Repository\ExchangeEndpointRepository;
use App\Pms\Entity\PmsBookingsPullQueue;

class PmsBookingsPullQueueFactory
{
    private ?ExchangeEndpoint $endpointGetBookings = null;

    public function __construct(
        private readonly ExchangeEndpointRepository $endpointRepository
    ) {}

    public function create(): PmsBookingsPullQueue
    {
        $job = new PmsBookingsPullQueue();

        $endpoint = $this->getGetBookingsEndpoint();
        if ($endpoint) {
            $job->setEndpoint($endpoint);
        }

        $job->setStatus(PmsBookingsPullQueue::STATUS_PENDING);

        return $job;
    }

    private function getGetBookingsEndpoint(): ?ExchangeEndpoint
    {
        if ($this->endpointGetBookings === null) {
            // Usamos el nuevo método del repositorio PMS
            $this->endpointGetBookings = $this->endpointRepository
                ->findOneBy([
                    'provider' => ConnectivityProvider::BEDS24,
                    'accion' => 'GET_BOOKINGS',
                    'activo' => true
                ]);
        }

        return $this->endpointGetBookings;
    }
}