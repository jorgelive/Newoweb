<?php

declare(strict_types=1);

namespace App\Message\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Cualquier clase que implemente esto será recolectada automáticamente
 * por el MessageSegmentationAggregator.
 */
#[AutoconfigureTag('app.message_segmentation')]
interface MessageSegmentationProviderInterface
{
    /**
     * @return array<string, string> Ej: ['Booking.com' => 'booking']
     */
    public function getSourceChoices(): array;

    /**
     * @return array<string, string> Ej: ['Agencia VIP' => '15']
     */
    public function getAgencyChoices(): array;
}