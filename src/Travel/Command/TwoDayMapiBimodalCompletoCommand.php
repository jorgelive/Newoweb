<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Enum\ComponenteModoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * El Two Day MAPI bimodal empieza en Cusco, como su hermano el directo (23/10/2026).
 *
 * La plantilla se llamaba «OLLA MAPI OLLA CUZ» y su día 1 arrancaba en el tren de Ollantaytambo:
 * faltaban el traslado del hotel a Wanchaq y el bus bimodal hasta la estación, que el Full Day
 * bimodal sí tiene. Jorge: «es mejor que esté completo, es más fácil sacarlo que ponerlo».
 *
 * Los dos tramos se copian del Full Day bimodal —mismo segmento, mismo componente, mismo modo—.
 * La primera pasada corrió sus horas al tren de las 16:36 que tenía la plantilla; Jorge prefirió el
 * de las 07:45–09:15 (`Version20261023233000`), y con ese tren el día 1 es el del Full Day:
 *
 *   04:20–04:40  hotel → Wanchaq / Av. El Sol
 *   05:10–07:10  bus bimodal → Ollantaytambo
 *   07:45–09:15  tren a Machu Picchu Pueblo
 *
 * Los nombres —la plantilla pasa a «CUZ OLLA MAPI OLLA CUZ (bimodal)», el componente del bus deja
 * de decir «Ollantatyambo»— van por `Version20261023230000`, que corre antes en el despliegue.
 *
 * Idempotente: un tramo que ya está en la plantilla no se vuelve a poner.
 */
#[AsCommand(
    name: 'app:travel:two-day-mapi-bimodal-completo',
    description: 'Completa el día 1 del Two Day MAPI bimodal con el traslado y el bus desde Cusco.',
    hidden: true,
)]
final class TwoDayMapiBimodalCompletoCommand extends Command
{
    private const string PLANTILLA = '2D MAPI: CUZ OLLA MAPI OLLA CUZ (BM)';

    /** @var list<array{segmento: string, componente: string, hora: string, fin: string}> en el orden del día */
    private const array TRAMOS = [
        ['segmento' => 'TRANS_BIM_IDA-MAPI-HTL_WAN_OLL', 'componente' => 'Transporte Wanchaq/Sol ↔ Hotel (ida o vuelta)', 'hora' => '04:20', 'fin' => '04:40'],
        ['segmento' => 'TRANS_BIM_IDA-MAPI-CUZ_OLL', 'componente' => 'Bus Bimodal Cusco ↔ Ollantaytambo (ida o vuelta)', 'hora' => '05:10', 'fin' => '07:10'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        $plantilla = $this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => self::PLANTILLA]);
        if (!$plantilla instanceof TravelItinerario) {
            $io->error('No encuentro ' . self::PLANTILLA . ': ¿corrió la migración que la renombra?');

            return Command::FAILURE;
        }

        $yaEstan = [];
        foreach ($plantilla->getItinerarioSegmentos() as $rel) {
            $yaEstan[$rel->getSegmento()?->getSlug() ?? ''] = true;
        }

        $nuevos = array_values(array_filter(self::TRAMOS, static fn (array $t): bool => !isset($yaEstan[$t['segmento']])));
        if ($nuevos === []) {
            $io->text('· Día 1 — ya empieza en Cusco');
        } else {
            // Lo que ya había en el día 1 se corre detrás de los tramos nuevos.
            foreach ($plantilla->getItinerarioSegmentos() as $rel) {
                if ($rel->getDia() === 1) {
                    $io->text(sprintf('  %s: orden %d → %d', $rel->getSegmento()?->getSlug(), $rel->getOrden(), $rel->getOrden() + \count($nuevos)));
                    if (!$seco) {
                        $rel->setOrden($rel->getOrden() + \count($nuevos));
                    }
                }
            }

            foreach ($nuevos as $pos => $t) {
                $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $t['segmento']]);
                $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $t['componente']]);
                if (!$segmento instanceof TravelSegmento || !$componente instanceof TravelComponente) {
                    $io->error(sprintf('Falta el segmento %s o el componente «%s».', $t['segmento'], $t['componente']));

                    return Command::FAILURE;
                }

                $io->text(sprintf('· Día 1, orden %d — %s, %s–%s', $pos + 1, $t['segmento'], $t['hora'], $t['fin']));
                if ($seco) {
                    continue;
                }

                $this->em->persist((new TravelItinerarioSegmentoRel())
                    ->setItinerario($plantilla)->setSegmento($segmento)
                    ->setDia(1)->setOrden($pos + 1));

                $this->em->persist((new TravelSegmentoComponente())
                    ->setSegmento($segmento)->setComponente($componente)
                    ->setItinerarioContexto($plantilla)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setDia(1)->setOrden($pos + 1)
                    ->setHora(new \DateTimeImmutable($t['hora']))
                    ->setHoraFin(new \DateTimeImmutable($t['fin'])));
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
