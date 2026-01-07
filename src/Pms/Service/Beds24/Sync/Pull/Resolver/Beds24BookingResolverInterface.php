<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Sync\Pull\Resolver;

use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\Beds24Config;

interface Beds24BookingResolverInterface
{
    /**
     * Persiste/actualiza el booking en el PMS.
     */
    public function upsert(Beds24Config $config, Beds24BookingDto $booking): void;
}
