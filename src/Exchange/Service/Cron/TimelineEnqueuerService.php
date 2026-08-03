<?php
declare(strict_types=1);

namespace App\Exchange\Service\Cron;

use App\Exchange\Entity\ExchangeCronCursor;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * TimelineEnqueuerService
 * * * --- PROPÓSITO ---
 * Este es el MOTOR GENERADOR DE COLAS a nivel de lógica de negocio.
 * Su función es recorrer la línea de tiempo (timeline) y delegar el encolado (enqueue)
 * de tareas pendientes al Job correspondiente para que el Worker las procese después.
 * * * --- EXPLICACIÓN DE LOS TAGS (app.cron_job) ---
 * Utiliza un "TaggedIterator" para inyectar automáticamente cualquier servicio etiquetado
 * con #[AutoconfigureTag('app.cron_job')] que implemente CronJobInterface.
 */
class TimelineEnqueuerService
{
    /** * @var CronJobInterface[]
     */
    private iterable $jobs;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[TaggedIterator('app.cron_job')] iterable $jobs
    ) {
        $this->jobs = $jobs;
    }

    /**
     * Ejecuta el proceso de avance de la línea de tiempo y encolado para un trabajo específico.
     *
     * ¿Por qué existe este método?
     * Centraliza la gestión del cursor de la base de datos (ExchangeCronCursor) y el cálculo
     * seguro de las ventanas de tiempo. Asegura que los trabajos de sincronización no se
     * solapen, no salten erróneamente al futuro y que el estado se recupere correctamente
     * en caso de que el Job interno limpie el EntityManager (em->clear()).
     *
     * @param string $jobName El identificador del generador de cron (ej: beds24_bookings_push).
     * @param SymfonyStyle $io Interfaz de entrada/salida para proveer feedback en tiempo real.
     * * @return bool Retorna True si el encolado finalizó correctamente, False si ocurrió un error controlado (ej. Job no encontrado).
     * * @throws \Throwable Propaga errores críticos generados por el Job Service.
     * * @example
     * // En un comando o controlador:
     * $success = $this->timelineEnqueuerService->enqueue('beds24_rates_push', $io);
     */
    public function enqueue(string $jobName, SymfonyStyle $io): bool
    {
        // 1. Localizar el generador de colas específico y recopilar disponibles por si falla
        $jobService = null;
        $availableJobs = [];

        foreach ($this->jobs as $job) {
            $availableJobs[] = $job->getName();
            if ($job->getName() === $jobName) {
                $jobService = $job;
            }
        }

        if (!$jobService) {
            $io->error("No se encontró el generador 'app.cron_job' para: $jobName");
            $io->listing($availableJobs);
            return false;
        }

        $repo = $this->em->getRepository(ExchangeCronCursor::class);

        // 2. Punto de control (Cursor): ¿Desde dónde empezamos a encolar?
        $cursor = $repo->find($jobName);

        if (!$cursor) {
            $io->info("Inicializando cursor nuevo para $jobName (desde ayer).");
            $cursor = new ExchangeCronCursor($jobName);
            $cursor->setCursorDate(new DateTimeImmutable('yesterday'));
            $this->em->persist($cursor);
            $this->em->flush();
        }

        $startDate = DateTimeImmutable::createFromInterface($cursor->getCursorDate());
        $endDate = $startDate->add($this->resolverPaso($jobService, $startDate));

        // Seguridad: evitar que el cursor avance a fechas irreales.
        //
        // El tope por defecto (+18 meses) es arbitrario y suele quedar MUY por encima del
        // horizonte real de los datos, así que el cursor se pasaba la mayor parte del ciclo
        // barriendo calendario garantizadamente vacío. Un job que sepa hasta dónde llegan sus
        // datos lo declara con CronHorizonteInterface y el ciclo se ajusta solo.
        $limitDate = $this->resolverLimite($jobService);

        if ($startDate > $limitDate) {
            $io->note('Fin del horizonte de datos: se reinicia el cursor.');
            $startDate = new DateTimeImmutable('yesterday');
            $endDate = $startDate->add($this->resolverPaso($jobService, $startDate));
        }

        $io->title("Timeline Enqueuer: $jobName");
        $io->note("Rango de generación: {$startDate->format('Y-m-d')} -> {$endDate->format('Y-m-d')}");

        // El Job Service busca los datos y crea las filas en las tablas _queue
        // Propagamos la excepción hacia arriba si algo crítico ocurre
        $jobService->execute($startDate, $endDate, $io);

        // 3. RECUPERACIÓN POST-CLEAR (Importante si el Job usó em->clear() durante el procesamiento)
        $cursor = $repo->find($jobName);
        if (!$cursor) {
            $cursor = new ExchangeCronCursor($jobName);
            $this->em->persist($cursor);
        }

        // 4. Actualizar el cursor para la siguiente vuelta
        $cursor->setCursorDate($endDate);
        $cursor->setLastRunAt(new DateTimeImmutable());
        $this->em->flush();

        $io->success("Encolado finalizado. Timeline avanzado a {$endDate->format('Y-m-d')}.");

        return true;
    }

    /** Paso del cursor: adaptativo si el job lo implementa, fijo si no. */
    private function resolverPaso(CronJobInterface $job, DateTimeImmutable $desde): DateInterval
    {
        return $job instanceof CronPasoAdaptativoInterface
            ? $job->getStepIntervalDesde($desde)
            : $job->getStepInterval();
    }

    /**
     * Hasta dónde puede avanzar el cursor antes de reiniciarse.
     *
     * Nunca por debajo de hoy: un horizonte en el pasado (o sin datos) dejaría el cursor
     * reiniciándose en cada ejecución sin llegar a barrer nada.
     */
    private function resolverLimite(CronJobInterface $job): DateTimeImmutable
    {
        $porDefecto = (new DateTimeImmutable())->modify('+18 months');

        if (!$job instanceof CronHorizonteInterface) {
            return $porDefecto;
        }

        $horizonte = $job->getHorizonteMaximo();
        if ($horizonte === null) {
            return $porDefecto;
        }

        $hoy = new DateTimeImmutable('today');

        return $horizonte < $hoy ? $hoy : $horizonte;
    }
}