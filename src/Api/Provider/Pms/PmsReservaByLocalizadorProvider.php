<?php

declare(strict_types=1);

namespace App\Api\Provider\Pms;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provider público Pax:
 * Resuelve una PmsReserva usando el localizador (token público).
 *
 * GET /api/pax/pms/pms_reserva/{localizador}
 */
final class PmsReservaByLocalizadorProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        $localizador = $uriVariables['localizador'] ?? null;

        if (!\is_string($localizador) || $localizador === '') {
            throw new NotFoundHttpException('Localizador inválido.');
        }

        $localizador = strtoupper($localizador);

        $reserva = $this->entityManager
            ->getRepository(PmsReserva::class)
            ->findOneBy(['localizador' => $localizador]);

        if (!$reserva) {
            throw new NotFoundHttpException(sprintf(
                'No existe una reserva con el localizador %s.',
                $localizador
            ));
        }

        return $reserva;
    }
}