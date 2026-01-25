<?php
declare(strict_types=1);

namespace App\Pms\Factory;

use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Repository\PmsBeds24EndpointRepository;

class PmsBookingsPullQueueFactory
{
    private ?PmsBeds24Endpoint $endpointGetBookings = null;

    public function __construct(
        private readonly PmsBeds24EndpointRepository $endpointRepository
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

    private function getGetBookingsEndpoint(): ?PmsBeds24Endpoint
    {
        if ($this->endpointGetBookings === null) {
            // Usamos el nuevo método del repositorio PMS
            $this->endpointGetBookings = $this->endpointRepository
                ->findActiveByAccion('GET_BOOKINGS');
        }

        return $this->endpointGetBookings;
    }
}