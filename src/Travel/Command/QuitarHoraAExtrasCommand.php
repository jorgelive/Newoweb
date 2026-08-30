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
 * Quita la hora del catálogo a actividades que nunca la enseñan.
 *
 * ⚠️ **Esa hora ya estaba muerta, no la estamos matando aquí.** Los componentes de tipo `extras`
 * cumplen `ComponenteTipoEnum::sinHorario()`, y `CotizacionCotcomponente::normalizarHorarioAlCrear()`
 * —un `#[ORM\PrePersist]`— pone inicio y fin a `00:00:00` en todo lo que lo cumpla. Es decir: la
 * hora del enlace de catálogo **no sobrevive a la inserción**. Comprobado en producción, donde
 * las ocho actividades de resort están a `00:00:00` en la cotización pese a tener 10:00, 16:00 o
 * 21:30 en el catálogo.
 *
 * Lo que se gana quitándola no es comportamiento: es que el catálogo deje de prometer un horario
 * que no va a aplicar. Un campo con un valor que nadie usa es peor que vacío, porque el siguiente
 * que lo lea creerá que significa algo y ajustará el número equivocado.
 *
 * ⚠️ **No vale para cualquier componente.** En un tipo CON horario la hora sí manda —coloca el
 * componente en el día, ver `docs/TravelCargaDeCatalogo.md` §5—, así que este comando se ciñe a
 * la lista de abajo y comprueba el tipo antes de escribir.
 */
#[AsCommand(
    name: 'app:travel:quitar-hora-a-extras',
    description: 'Vacía la hora de catálogo en actividades que no la muestran.',
)]
final class QuitarHoraAExtrasCommand extends Command
{
    /** @var list<string> slugs de segmento cuya hora de catálogo no se usa */
    private const SEGMENTOS = [
        'ACT-RESORT-PISCINA_PLAYA',
        'ACT-RESORT-RECREATIVAS',
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

        foreach (self::SEGMENTOS as $slug) {
            $segmento = $repo->findOneBy(['slug' => $slug]);

            if ($segmento === null) {
                $io->text(sprintf('  no existe · %s', $slug));
                continue;
            }

            foreach ($segmento->getSegmentoComponentes() as $enlace) {
                $componente = $enlace->getComponente();

                if ($componente === null) {
                    continue;
                }

                // El guarda que hace seguro este comando: si algún día el componente se retipa a
                // uno CON horario, la hora vuelve a mandar y esto no debe vaciarla.
                if (!$componente->getTipo()->sinHorario()) {
                    $io->text(sprintf(
                        '  SALTADO · %s es «%s», que SÍ usa la hora',
                        $slug,
                        $componente->getTipo()->value,
                    ));
                    continue;
                }

                $antes = $enlace->getHora()?->format('H:i') ?? '—';

                if ($enlace->getHora() === null && $enlace->getHoraFin() === null) {
                    $io->text(sprintf('  ya está · %-26s sin hora', $slug));
                    continue;
                }

                $io->text(sprintf('  %s · %-26s %s  →  sin hora', $simula ? 'cambiaría' : 'cambiado ', $slug, $antes));
                ++$tocados;

                if (!$simula) {
                    $enlace->setHora(null);
                    $enlace->setHoraFin(null);
                }
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->text('');
        $io->text(sprintf('  enlaces ajustados: %d', $tocados));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
