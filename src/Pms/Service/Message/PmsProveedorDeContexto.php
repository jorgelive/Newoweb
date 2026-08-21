<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\ProveedorDeContextoInterface;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El contexto de una reserva, a petición.
 *
 * Arma exactamente el mismo adaptador que `PmsReservaRecalculoService` monta en cada recálculo,
 * cabecera financiera incluida — que se pasa aparte a propósito: ver el porqué en el constructor
 * de {@see PmsReservaMessageContext}.
 *
 * ⚠️ **Los bloqueos puros no abren hilo.** Una agenda cerrada sin huésped real no es nadie a
 * quien escribir, y es la misma guarda que aplica el recálculo. Sin ella, el panel ofrecería
 * abrir conversación sobre un tramo de mantenimiento.
 */
final readonly class PmsProveedorDeContexto implements ProveedorDeContextoInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function supports(string $contextType): bool
    {
        return $contextType === PmsConversacionEnlace::CONTEXT_TYPE;
    }

    public function para(string $contextId): ?MessageContextInterface
    {
        $reserva = $this->em->getRepository(PmsReserva::class)->find($contextId);

        if ($reserva === null) {
            return null;
        }

        $contexto = new PmsReservaMessageContext(
            $reserva,
            $this->em->getRepository(PmsInformacionFinanciera::class)->findOneBy(['reserva' => $reserva])
        );

        return $contexto->isSoloBloqueo() ? null : $contexto;
    }
}
