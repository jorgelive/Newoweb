<?php

declare(strict_types=1);

namespace App\Travel\Service\Message;

use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\ProveedorDeContextoInterface;
use App\Travel\Entity\TravelOrganizacion;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El contexto de una organización proveedora, a petición.
 *
 * Es el único de los tres que **no** tiene un listener detrás, y a propósito: una organización
 * se guarda muchas veces —se le corrige la dirección, se le sube una foto— y ninguna de esas
 * veces significa «quiero hablar con ella». El hilo nace cuando alguien decide escribirle.
 */
final readonly class TravelProveedorDeContexto implements ProveedorDeContextoInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function supports(string $contextType): bool
    {
        return $contextType === TravelOrganizacionMessageContext::CONTEXT_TYPE;
    }

    public function para(string $contextId): ?MessageContextInterface
    {
        $organizacion = $this->em->getRepository(TravelOrganizacion::class)->find($contextId);

        return $organizacion !== null ? new TravelOrganizacionMessageContext($organizacion) : null;
    }
}
