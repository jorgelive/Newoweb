<?php
declare(strict_types=1);

namespace App\Exchange\Service\Cron\Job;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Service\Cron\CronHorizonteInterface;
use App\Exchange\Service\Cron\CronJobInterface;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Service\Queue\Beds24BookingsPushQueueCreator;
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
class Beds24BookingsPushJob implements CronJobInterface, CronHorizonteInterface
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

    /**
     * Hasta la última salida registrada: este job barre eventos LOCALES con el mismo filtro
     * (`e.fin >= :from AND e.inicio <= :to`), así que más allá no hay nada que empujar.
     *
     * NO lleva paso adaptativo: con un paso base de 1 mes ya son pocas vueltas, y doblarlo
     * dejaría lo lejano con una granularidad de medio año a cambio de casi nada.
     */
    public function getHorizonteMaximo(): ?DateTimeImmutable
    {
        $max = $this->em->createQueryBuilder()
            ->select('MAX(e.fin)')
            ->from(PmsEventoCalendario::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();

        return $max ? new DateTimeImmutable((string) $max) : null;
    }

    public function execute(DateTimeImmutable $from, DateTimeImmutable $to, SymfonyStyle $io): void
    {
        $postEndpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'provider' => ConnectivityProvider::BEDS24,
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

                /** @var PmsEventoCalendario $evento */
                foreach ($eventos as $evento) {
                    /** @var PmsEventoBeds24Link $link */
                    foreach ($evento->getBeds24Links() as $link) {

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
                $postEndpoint = $this->em->getRepository(ExchangeEndpoint::class)->find($endpointId);

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