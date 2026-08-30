<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelSegmento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pone la franja horaria real de las comidas del resort en el catálogo.
 *
 * ⚠️ **El problema no era la hora de inicio: era que NO había hora de fin.** Las cuatro comidas
 * de resort tenían `hora` y `hora_fin` a null, así que cada vez que se insertaban en una
 * cotización nacían con `fin = inicio` —duración cero— y había que corregirlas a mano en cada
 * día. En el expediente de Punta Cana pasó dos días seguidos.
 *
 * Un componente de duración cero no rompe nada visible: se ordena por su hora igual que
 * cualquier otro y sale en la propuesta. Sólo miente sobre cuánto dura, que es justo el dato por
 * el que pregunta el pasajero cuando lee «desayuno 07:00».
 *
 * Las horas son las que publica el resort, no una estimación nuestra.
 */
#[AsCommand(
    name: 'app:travel:horario-comidas-resort',
    description: 'Escribe inicio y fin de las comidas de resort en el catálogo.',
)]
final class HorarioComidasResortCommand extends Command
{
    /** @var array<string, array{0: string, 1: string}> slug del segmento → [inicio, fin] */
    private const HORARIOS = [
        'DES-RESORT-BUFFET' => ['07:00', '10:30'],
        'ALM-RESORT-BUFFET' => ['12:30', '15:00'],
        'CEN-RESORT-BUFFET' => ['18:30', '22:00'],
        // ⚠️ El temático hereda la franja de cena del resort. Si en realidad tiene turno con
        // reserva —que es lo habitual en un restaurante temático—, esta franja es demasiado
        // ancha y hay que estrecharla aquí, no en cada cotización.
        'CEN-RESORT-TEMATICO' => ['18:30', '22:00'],
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
        $repo = $this->em->getRepository(TravelSegmento::class);
        $tocados = 0;

        foreach (self::HORARIOS as $slug => [$inicio, $fin]) {
            $segmento = $repo->findOneBy(['slug' => $slug]);

            if ($segmento === null) {
                $io->text(sprintf('  no existe · %s', $slug));
                continue;
            }

            // Un segmento puede tener el componente enlazado más de una vez (días distintos del
            // itinerario). Se ajustan todos: la franja del comedor no cambia según el día.
            foreach ($segmento->getSegmentoComponentes() as $enlace) {
                $componente = $enlace->getComponente();

                if ($componente === null || !str_starts_with($componente->getTipo()->value, 'alimentacion')) {
                    continue;
                }

                $antesInicio = $enlace->getHora()?->format('H:i') ?? '—';
                $antesFin = $enlace->getHoraFin()?->format('H:i') ?? '—';

                if ($antesInicio === $inicio && $antesFin === $fin) {
                    $io->text(sprintf('  ya está · %-22s %s a %s', $slug, $inicio, $fin));
                    continue;
                }

                $io->text(sprintf(
                    '  %s · %-22s %s a %s  →  %s a %s',
                    $simula ? 'cambiaría' : 'cambiado ',
                    $slug,
                    $antesInicio,
                    $antesFin,
                    $inicio,
                    $fin,
                ));
                ++$tocados;

                if (!$simula) {
                    $enlace->setHora(new \DateTimeImmutable($inicio));
                    $enlace->setHoraFin(new \DateTimeImmutable($fin));
                }
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->text('');
        $io->text(sprintf('  enlaces ajustados: %d', $tocados));

        // Las cotizaciones ya hechas llevan la hora congelada en su snapshot: esto NO las toca.
        $io->note('Sólo cambia el catálogo. Lo ya cotizado conserva la hora del día que se insertó.');

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
