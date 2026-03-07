<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Exchange\Entity\Beds24Config;
use App\Pms\Entity\PmsEventoBeds24Link;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Servicio de solo lectura (Finder) que pertenece al dominio PMS.
 * Extrae las reservas que están activas en un rango de fechas y expone sus IDs de Beds24.
 */
final class PmsBeds24MessageTargetFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * @return \Generator<array{bookId: string, config: Beds24Config}>
     */
    public function findTargetsForPeriod(DateTimeInterface $from, DateTimeInterface $to): \Generator
    {
        // Navegamos: Link -> Evento -> Reserva -> Establecimiento -> Config
        $qb = $this->em->createQueryBuilder()
            ->select('l', 'e', 'r', 'est', 'cfg')
            ->from(PmsEventoBeds24Link::class, 'l')
            ->innerJoin('l.evento', 'e')

            // 🔥 AHORA NAVEGAMOS HACIA LA RESERVA PARA LLEGAR AL ESTABLECIMIENTO
            ->innerJoin('e.reserva', 'r')
            ->innerJoin('r.establecimiento', 'est')
            ->innerJoin('est.beds24Config', 'cfg')

            ->where('e.fin >= :from AND e.inicio <= :to')
            ->andWhere('l.esPrincipal = true') // Solo la reserva Master, evita duplicar llamadas
            ->andWhere('l.status != :statusDeleted')
            ->andWhere('cfg.activo = true')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('statusDeleted', PmsEventoBeds24Link::STATUS_SYNCED_DELETED);

        $links = $qb->getQuery()->getResult();

        foreach ($links as $link) {
            /** @var PmsEventoBeds24Link $link */

            // Extraemos la configuración a través de la nueva jerarquía
            $evento = $link->getEvento();
            $config = $evento?->getReserva()?->getEstablecimiento()?->getBeds24Config();
            $bookId = $link->getBeds24BookId();

            if ($config && $bookId) {
                yield [
                    'bookId' => $bookId,
                    'config' => $config
                ];
            }
        }
    }
}