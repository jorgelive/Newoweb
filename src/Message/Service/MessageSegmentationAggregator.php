<?php

declare(strict_types=1);

namespace App\Message\Service;

use App\Message\Contract\MessageSegmentationProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class MessageSegmentationAggregator
{
    /**
     * @param iterable<MessageSegmentationProviderInterface> $providers
     */
    public function __construct(
        // 🔥 Magia de Symfony: Recolecta TODAS las clases con esta etiqueta
        #[TaggedIterator('app.message_segmentation')]
        private iterable $providers
    ) {}

    public function getSourceChoices(): array
    {
        $allChoices = [];
        foreach ($this->providers as $provider) {
            // Fusionamos los arrays de todos los módulos que existan
            $allChoices = array_merge($allChoices, $provider->getSourceChoices());
        }

        return $allChoices;
    }

    public function getAgencyChoices(): array
    {
        $allChoices = [];
        foreach ($this->providers as $provider) {
            $allChoices = array_merge($allChoices, $provider->getAgencyChoices());
        }

        return $allChoices;
    }
}