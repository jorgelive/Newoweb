<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\MessageSegmentationProviderInterface;
use App\Pms\Entity\PmsChannel;
use Doctrine\ORM\EntityManagerInterface;

class PmsSegmentationProvider implements MessageSegmentationProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function getSourceChoices(): array
    {
        $canales = $this->entityManager->getRepository(PmsChannel::class)->findBy([], ['orden' => 'ASC']);

        $choices = [];
        foreach ($canales as $canal) {
            // Le ponemos un emoji o prefijo visual para distinguirlo en el futuro de los Tours
            $label = '🏨 ' . $canal->getNombre();
            $choices[$label] = $canal->getId();
        }

        return $choices;
    }

    public function getAgencyChoices(): array
    {
        // 🚀 Listo para cuando crees la entidad PmsAgencia
        return [];
    }
}