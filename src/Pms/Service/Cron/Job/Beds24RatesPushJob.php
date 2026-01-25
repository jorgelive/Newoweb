<?php

namespace App\Pms\Service\Cron\Job;

use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Beds24\Queue\Beds24RatesPushQueueCreator;
use App\Pms\Service\Cron\CronJobInterface;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.cron_job')]
class Beds24RatesPushJob implements CronJobInterface
{
    /**
     * Tamaño del lote para liberar memoria.
     * Las tarifas son pesadas (calculan día x día), así que mantenemos el lote pequeño.
     */
    private const BATCH_SIZE = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Beds24RatesPushQueueCreator $queueCreator, // ✅ Reutilizamos el servicio central
    ) {}

    public function getName(): string
    {
        // Este nombre se usa en el comando: php bin/console pms:cron:run beds24_rates_push
        return 'beds24_rates_push';
    }

    public function getStepInterval(): DateInterval
    {
        // Avanzamos 2 semanas por ejecución.
        // Calcular tarifas es costoso computacionalmente; 1 mes podría ser demasiado pesado
        // si hay muchas unidades. 2 semanas es un balance seguro.
        return new DateInterval('P2W');
    }

    public function execute(DateTimeImmutable $from, DateTimeImmutable $to, SymfonyStyle $io): void
    {
        // 1. Obtener IDs de todas las unidades activas
        // Usamos solo IDs para no cargar objetos innecesarios en memoria todavía.
        $ids = $this->em->createQueryBuilder()
            ->select('u.id')
            ->from(PmsUnidad::class, 'u')
            ->where('u.activo = true')
            // Opcional: Filtrar solo unidades que tienen mapeo a Beds24 para optimizar
            // ->innerJoin('u.beds24Maps', 'm')
            ->getQuery()
            ->getSingleColumnResult();

        $total = count($ids);
        $io->text(sprintf('Procesando tarifas para %d unidades (Rango: %s a %s)', $total, $from->format('Y-m-d'), $to->format('Y-m-d')));
        $io->progressStart($total);

        // 2. Iterar por lotes (Chunks)
        foreach (array_chunk($ids, self::BATCH_SIZE) as $batchIds) {

            // Cargar las unidades del lote actual
            $unidades = $this->em->getRepository(PmsUnidad::class)->findBy(['id' => $batchIds]);

            foreach ($unidades as $unidad) {
                /** @var PmsUnidad $unidad */

                // ==================================================================
                // LLAMADA AL CREATOR (MODO CRON)
                // ==================================================================
                // 1. dirtyRango = null (No estamos editando un rango específico)
                // 2. isDelete = false
                // 3. uow = NULL (Vital: Doctrine gestionará el persist/flush normal)
                // ==================================================================
                $this->queueCreator->enqueueForInterval(
                    $unidad,
                    $from,
                    $to,
                    null,
                    false,
                    null
                );

                $io->progressAdvance();
            }

            // 3. Gestión de Memoria y Persistencia
            // Guardamos los cambios de este lote (INSERT/UPDATE en pms_tarifa_queue)
            $this->em->flush();

            // Liberamos memoria. Esto "desconecta" las entidades cargadas.
            $this->em->clear();
        }

        $io->progressFinish();
        $io->success("Tarifas recalculadas y encoladas correctamente.");
    }
}