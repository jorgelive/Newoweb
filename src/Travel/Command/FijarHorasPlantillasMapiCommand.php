<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Horas de las plantillas de Machu Picchu en dos días que no cuadraban con su propio tren.
 *
 * ── Lo que salió en una cotización real ─────────────────────────────────────
 *
 * Revisando la propuesta EG95UF, que nació de «2D MAPI … (DIR)»:
 *
 * ```
 * 07:00  Ascenso en bus al Santuario
 * 08:00  Encuentro con nuestro personal en tu hotel     ← el bus ya salió
 * 14:30  Tren de retorno, llega 16:30
 * 18:20  Traslado a Cusco                               ← casi dos horas esperando
 * ```
 *
 * Las dos venían de la plantilla. El contacto en el hotel **no tenía hora** —ni el pivote
 * global ni el de la plantilla— y la cotización le puso la que le tocó. El retorno se quedó
 * con su hora global, 18:20, que sirve al tren de las 16:22 del full day y no al de las 14:30
 * de éste. Al crearla se escribió que bastaba con que fuera posterior al tren; no basta: tiene
 * que salir cuando el tren llega.
 *
 * Y la bimodal de dos días tenía el mismo hueco en el contacto, más un bus de vuelta a las
 * 16:20 con un tren que llega a las 16:30.
 *
 * ── La regla ────────────────────────────────────────────────────────────────
 *
 * El contacto, media hora antes del bus de subida (07:00). El retorno, a la llegada del tren
 * de la plantilla (16:30). Si un día cambia el tren de la plantilla, cambian las dos.
 *
 * Las horas viven en el pivote de CONTEXTO, que es como el catálogo dice «aquí esto ocurre a
 * esta otra hora». Si la plantilla no lo tiene, se crea copiando el global —componente, modo,
 * tarifa y orden— y poniendo sólo la hora.
 */
#[AsCommand(
    name: 'app:travel:fijar-horas-plantillas-mapi',
    description: 'Alinea el contacto y el retorno de las plantillas de MAPI en dos días con su tren.',
    hidden: true,
)]
final class FijarHorasPlantillasMapiCommand extends Command
{
    /**
     * plantilla → segmento → hora.
     *
     * @var array<string, array<string, string>>
     */
    private const HORAS = [
        '2D MAPI: CUZ OLLA MAPI OLLA CUZ (DIR)' => [
            'CONTACT-MAPI-HTL' => '06:30',
            'TRANS_DIRECT_RET-MAPI-OLL_CUZ' => '16:30',
        ],
        '2D MAPI: OLLA MAPI OLLA CUZ (BM)' => [
            'CONTACT-MAPI-HTL' => '06:30',
            'TRANS_BIM_RET-MAPI-OLL_CUZ' => '16:30',
        ],
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
        $simula = (bool) $input->getOption('dry-run');

        $io->title('Horas de las plantillas de MAPI en dos días');
        $tocados = 0;

        foreach (self::HORAS as $slugPlantilla => $horas) {
            $io->section($slugPlantilla);

            $itinerario = $this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => $slugPlantilla]);

            if ($itinerario === null) {
                $io->error(sprintf('No existe la plantilla «%s».', $slugPlantilla));

                return Command::FAILURE;
            }

            foreach ($horas as $slugSegmento => $hora) {
                $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slugSegmento]);

                if ($segmento === null) {
                    $io->error(sprintf('No existe el segmento «%s».', $slugSegmento));

                    return Command::FAILURE;
                }

                /** @var list<TravelSegmentoComponente> $globales */
                $globales = $this->em->getRepository(TravelSegmentoComponente::class)
                    ->findBy(['segmento' => $segmento, 'itinerarioContexto' => null]);

                foreach ($globales as $global) {
                    $componente = $global->getComponente();

                    $deContexto = $this->em->getRepository(TravelSegmentoComponente::class)->findOneBy([
                        'segmento' => $segmento,
                        'componente' => $componente,
                        'itinerarioContexto' => $itinerario,
                    ]);

                    $actual = $deContexto?->getHora()?->format('H:i');

                    if ($actual === $hora) {
                        $io->text(sprintf('  ya está  · %-32s %s', $slugSegmento, $hora));
                        continue;
                    }

                    ++$tocados;
                    $io->text(sprintf(
                        '  %s · %-32s %s → %s%s',
                        $simula ? 'haría ' : 'hecho ',
                        $slugSegmento,
                        $actual ?? 'sin hora',
                        $hora,
                        $deContexto === null ? '  (crea el pivote de plantilla)' : '',
                    ));

                    if ($simula) {
                        continue;
                    }

                    if ($deContexto === null) {
                        $deContexto = (new TravelSegmentoComponente())
                            ->setSegmento($segmento)
                            ->setComponente($componente)
                            ->setItinerarioContexto($itinerario)
                            ->setTarifaPredeterminada($global->getTarifaPredeterminada())
                            ->setModo($global->getModo())
                            ->setOrden($global->getOrden());
                        $this->em->persist($deContexto);
                    }

                    $deContexto->setHora(new \DateTimeImmutable($hora));
                }
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf('%s %d hora(s).', $simula ? 'Se fijarían' : 'Fijadas', $tocados));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
