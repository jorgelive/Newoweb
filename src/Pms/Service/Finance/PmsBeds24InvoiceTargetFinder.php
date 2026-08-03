<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Exchange\Entity\Beds24Config;
use App\Pms\Entity\PmsEventoBeds24Link;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Generator;

/**
 * Finder de solo lectura para el cron de facturas: devuelve **un target por RESERVA**,
 * no uno por habitación.
 *
 * Se separa de PmsBeds24MessageTargetFinder (que sí necesita ir booking a booking, porque
 * los mensajes son de cada booking) por el comportamiento asimétrico de Beds24 descrito en
 * §11.6 de docs/PmsBeds24ReservasSync.md:
 *
 *   GET /bookings/invoices?bookingId=<master> → devuelve los invoiceItems de TODO el grupo
 *   GET /bookings/invoices?bookingId=<hija>   → devuelve sólo los suyos
 *
 * Preguntando únicamente por el master se obtiene el grupo completo en UNA llamada. Preguntar
 * por cada habitación (lo que hacía el finder de mensajes) devolvía los mismos items N veces:
 * inocuo por el índice único `uq_cargo_info_item`, pero N-1 llamadas HTTP desperdiciadas
 * contra una API con límite de peticiones.
 *
 * ⚠️ Se elige SIEMPRE el master del grupo, nunca una hija: una hija devuelve un subconjunto y
 * perderíamos los cargos de las demás habitaciones.
 */
final class PmsBeds24InvoiceTargetFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * @return Generator<array{bookId: string, config: Beds24Config}>
     */
    public function findTargetsForPeriod(DateTimeInterface $from, DateTimeInterface $to): Generator
    {
        // Misma navegación que el finder de mensajes: Link -> Evento -> Reserva -> Establecimiento -> Config
        $links = $this->em->createQueryBuilder()
            ->select('l', 'e', 'r', 'est', 'cfg')
            ->from(PmsEventoBeds24Link::class, 'l')
            ->innerJoin('l.evento', 'e')
            ->innerJoin('e.reserva', 'r')
            ->innerJoin('r.establecimiento', 'est')
            ->innerJoin('est.beds24Config', 'cfg')
            ->where('e.fin >= :from AND e.inicio <= :to')
            ->andWhere('l.esPrincipal = true')
            ->andWhere('l.status != :statusDeleted')
            ->andWhere('cfg.activo = true')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('statusDeleted', PmsEventoBeds24Link::STATUS_SYNCED_DELETED)
            ->getQuery()
            ->getResult();

        /** @var array<string, array{bookId: string, config: Beds24Config}> $porReserva */
        $porReserva = [];

        foreach ($links as $link) {
            /** @var PmsEventoBeds24Link $link */
            $reserva = $link->getEvento()?->getReserva();
            $config = $reserva?->getEstablecimiento()?->getBeds24Config();
            $bookId = $link->getBeds24BookId();

            if (!$reserva || !$config || !$bookId) {
                continue;
            }

            $reservaId = (string) $reserva->getId();
            $masterId = $reserva->getBeds24MasterId();

            // Una reserva individual sólo tiene un candidato; en un grupo nos quedamos con el
            // master. Si aún no se conoce el master (sync a medias), vale el primero que llegue.
            if (!isset($porReserva[$reservaId]) || $bookId === $masterId) {
                $porReserva[$reservaId] = ['bookId' => $bookId, 'config' => $config];
            }
        }

        foreach ($porReserva as $target) {
            yield $target;
        }
    }
}
