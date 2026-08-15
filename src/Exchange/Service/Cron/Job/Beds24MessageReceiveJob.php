<?php

declare(strict_types=1);

namespace App\Exchange\Service\Cron\Job;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Service\Cron\CronHorizonteInterface;
use App\Exchange\Service\Cron\CronJobInterface;
use App\Exchange\Service\Cron\CronPasoAdaptativoInterface;
use App\Exchange\Service\Cron\PasoAdaptativo;
use App\Pms\Entity\PmsEventoCalendario;
use App\Message\Service\Queue\Beds24ReceiveEnqueuer;
use App\Pms\Service\Message\PmsBeds24MessageTargetFinder;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.cron_job')]
class Beds24MessageReceiveJob implements CronJobInterface, CronHorizonteInterface, CronPasoAdaptativoInterface
{
    private const BATCH_SIZE = 50;

    // ── Configuración del barrido ────────────────────────────────────────────
    // El paso se DUPLICA cada TRAMO_DIAS de lejanía, con techo en MULT_MAX:
    //   0-60 d → 7 d · 60-120 → 14 · 120-180 → 28 · 180-240 → 56 · 240+ → 112
    // Motivo: las reservas vivas se reparten muy desigual (medido: 24 en los próximos 60 días,
    // 2 entre 60 y 180, 1 más allá). Con paso fijo, el 87 % de las ejecuciones caía donde casi
    // no hay nada. Para ajustar el comportamiento se tocan ESTAS constantes, nada más.
    private const int PASO_BASE_DIAS = 7;
    private const int TRAMO_DIAS     = 60;
    private const int MULT_MAX       = 16;

    public function getStepIntervalDesde(DateTimeImmutable $desde): DateInterval
    {
        return PasoAdaptativo::calcular($desde, self::PASO_BASE_DIAS, self::TRAMO_DIAS, self::MULT_MAX);
    }

    /**
     * Hasta la última salida registrada. Más allá no hay estancias que consultar: el tope por
     * defecto de +18 meses hacía que el cursor recorriera cientos de días vacíos (medido: las
     * reservas terminaban a 185 días). Se autoajusta al entrar reservas más lejanas.
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


    public function __construct(
        private readonly EntityManagerInterface       $em,
        private readonly PmsBeds24MessageTargetFinder $targetFinder, // Llama al PMS
        private readonly Beds24ReceiveEnqueuer        $enqueuer     // Llama a Message
    ) {}

    public function getName(): string
    {
        return 'beds24_message_receive'; // Alias para ejecutarlo en consola
    }

    public function getStepInterval(): DateInterval
    {
        // Avanzamos de día en día. Las ventanas de mensajes son cortas y volátiles.
        return new DateInterval('P7D');
    }

    public function execute(DateTimeImmutable $from, DateTimeImmutable $to, SymfonyStyle $io): void
    {
        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'provider' => ConnectivityProvider::BEDS24,
            'accion'   => 'GET_MESSAGES', // Asegúrate de tener este slug en tu crud
            'activo'   => true
        ]);

        if (!$endpoint) {
            $io->error('No existe endpoint GET_MESSAGES activo.');
            return;
        }

        $io->text("1. Buscando reservas activas en Beds24 para el periodo...");

        $generator = $this->targetFinder->findTargetsForPeriod($from, $to);

        $count = 0;
        $totalEnqueued = 0;

        foreach ($generator as $target) {
            $bookId = $target['bookId'];
            $config = $target['config'];

            $queue = $this->enqueuer->enqueue($bookId, $config, $endpoint);

            if ($queue) {
                $totalEnqueued++;
            }

            $count++;

            // Batching para cuidar la memoria
            if ($count % self::BATCH_SIZE === 0) {
                $this->em->flush();
                $this->em->clear();
                $this->enqueuer->clearCache(); // Limpiar RAM del creator

                // Recuperar el endpoint tras el clear(): el `clear()` desvincula la entidad
                // del EntityManager, así que la referencia anterior ya no sirve.
                //
                // ⚠️ `find()` puede devolver null —si alguien borró el endpoint mientras corría
                // el lote—, y sin esta guarda la siguiente vuelta llamaba a `getId()` sobre null
                // y tumbaba el cron entero a mitad, dejando el resto del lote sin procesar.
                $refrescado = $this->em->getRepository(ExchangeEndpoint::class)->find($endpoint->getId());

                if (null === $refrescado) {
                    $io->warning('El endpoint desapareció durante el lote; se corta aquí.');
                    break;
                }

                $endpoint = $refrescado;

                if ($io->isVerbose()) {
                    $io->write('.');
                }
            }
        }

        // Flush final
        $this->em->flush();
        $this->em->clear();
        $this->enqueuer->clearCache();

        $io->newLine();
        $io->success("Message Pull finalizado. Se han encolado $totalEnqueued trabajos nuevos.");
    }
}