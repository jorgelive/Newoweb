<?php
declare(strict_types=1);

namespace App\Pms\Service\Cron\Job;

use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Service\Beds24\Queue\Beds24BookingsPushQueueCreator;
use App\Pms\Service\Cron\CronJobInterface;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Job de mantenimiento para forzar la resincronización de reservas.
 * * --- EXPLICACIÓN DEL TAG ---
 * La anotación #[AutoconfigureTag] permite que PmsRunCronJobCommand localice
 * este servicio automáticamente mediante un TaggedIterator.
 */
#[AutoconfigureTag('app.cron_job')]
class Beds24BookingsPushJob implements CronJobInterface
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly EntityManagerInterface         $em,
        private readonly Beds24BookingsPushQueueCreator $queueCreator,
        //private readonly SyncContext                    $syncContext,
    ) {}

    /**
     * @see \App\Pms\Command\PmsRunCronJobCommand::execute
     */
    public function getName(): string
    {
        return 'beds24_bookings_push';
    }

    public function getStepInterval(): DateInterval
    {
        // Avanza de mes en mes en cada ejecución del comando
        return new DateInterval('P1M');
    }

    public function execute(DateTimeImmutable $from, DateTimeImmutable $to, SymfonyStyle $io): void
    {
        $postEndpoint = $this->em->getRepository(PmsBeds24Endpoint::class)->findOneBy([
            'accion' => 'POST_BOOKINGS',
            'activo' => true
        ]);

        if (!$postEndpoint) {
            $io->error('No existe endpoint POST_BOOKINGS activo.');
            return;
        }
        $endpointId = $postEndpoint->getId();

        try {
            $io->text("1. Buscando IDs de eventos activos en el rango (basado en Checkout)...");

            /**
             * ✅ MEJORA: Filtro por fecha de FIN (fin >= :from)
             * Esto asegura que si una reserva empezó hace 3 días pero termina mañana,
             * sea incluida en el proceso de sincronización actual.
             */
            $ids = $this->em->createQueryBuilder()
                ->select('e.id')
                ->from(PmsEventoCalendario::class, 'e')
                ->where('e.fin >= :from AND e.inicio <= :to')
                ->orderBy('e.inicio', 'ASC')
                ->setParameter('from', $from)
                ->setParameter('to', $to)
                ->getQuery()
                ->getSingleColumnResult();

            $total = count($ids);
            $io->text("Eventos detectados (incluyendo en curso): $total. Procesando en lotes de " . self::BATCH_SIZE . "...");

            $updatesCount = 0;

            foreach (array_chunk($ids, self::BATCH_SIZE) as $batchIds) {

                // Carga optimizada con Joins para el lote
                $eventos = $this->em->createQueryBuilder()
                    ->select('e', 'l', 'm', 'q')
                    ->from(PmsEventoCalendario::class, 'e')
                    ->innerJoin('e.beds24Links', 'l')
                    ->leftJoin('l.unidadBeds24Map', 'm')
                    ->leftJoin('l.queues', 'q')
                    ->where('e.id IN (:ids)')
                    ->setParameter('ids', $batchIds)
                    ->getQuery()
                    ->getResult();

                foreach ($eventos as $evento) {
                    /** @var PmsEventoCalendario $evento */
                    foreach ($evento->getBeds24Links() as $link) {
                        /** @var PmsEventoBeds24Link $link */

                        if (!$link->getUnidadBeds24Map()) continue;
                        if ($link->getStatus() === PmsEventoBeds24Link::STATUS_SYNCED_DELETED) continue;

                        // Forzar cambio de hash para asegurar que el Creator genere una nueva tarea
                        foreach ($link->getQueues() as $q) {
                            $q->setPayloadHash('FORCE_SYNC_' . bin2hex(random_bytes(4)));
                        }

                        $this->queueCreator->enqueueForLink($link, $postEndpoint, null);

                        foreach ($link->getQueues() as $q) {
                            if ($q->getStatus() === 'pending') {
                                $updatesCount++;
                            }
                        }
                    }
                }

                // Persistencia y limpieza de memoria
                $this->em->flush();
                $this->em->clear();

                // Recuperar el endpoint tras el clear()
                $postEndpoint = $this->em->getRepository(PmsBeds24Endpoint::class)->find($endpointId);

                if ($io->isVerbose()) {
                    $io->write('.');
                }
            }

            $io->newLine();
            $io->success("Bookings Push finalizado. Total de colas reactivadas: $updatesCount");

        } finally {
            //No es push aun solo preparamos
            //$this->syncContext->restore();
        }
    }
}