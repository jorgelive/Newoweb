<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelSegmentoComponente;
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
 * componente en el día, ver `docs/TravelCargaDeCatalogo.md` §5—, así que se comprueba el tipo
 * antes de escribir.
 *
 * ── De lista de slugs a REGLA (01/09/2026) ──────────────────────────────────
 * Nació con dos slugs escritos a mano. La auditoría del 01/09 encontró **nueve** filas en el
 * mismo estado, así que una lista deja de servir: hay que mantenerla y siempre va por detrás de
 * los datos. Ahora recorre **todos los enlaces cuyo tipo declare `sinHorario()`** y el propio
 * predicado del enum decide, que es lo que lo vuelve auto-mantenible.
 *
 * ⚠️ **Se corre DESPUÉS de retipar, nunca antes.** Una actividad con franja real —el espectáculo
 * de las 21:30— se arregla cambiándole el tipo a `ACTIVIDAD_HORARIO_FIJO`, no vaciándole la hora.
 * Si este comando pasa primero, borra el dato bueno y ya no hay de dónde recuperarlo. Retipar
 * primero además lo saca solo de esta pasada: deja de cumplir `sinHorario()`.
 */
#[AsCommand(
    name: 'app:travel:quitar-hora-a-extras',
    description: 'Vacía la hora de catálogo en actividades que no la muestran.',
)]
final class QuitarHoraAExtrasCommand extends Command
{
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
        $tocados = 0;

        /** @var list<TravelSegmentoComponente> $enlaces */
        $enlaces = $this->em->getRepository(TravelSegmentoComponente::class)->findAll();

        foreach ($enlaces as $enlace) {
            $componente = $enlace->getComponente();
            $tipo = $componente?->getTipo();

            // El guarda que hace seguro este comando: si el componente es de un tipo CON horario
            // la hora manda, y esto no debe vaciarla. Retipar antes es lo que saca de aquí a las
            // actividades con franja real.
            if ($tipo === null || !$tipo->sinHorario()) {
                continue;
            }

            if ($enlace->getHora() === null && $enlace->getHoraFin() === null) {
                continue;   // el caso normal: no se anuncia para que la salida sea legible
            }

            $slug = $enlace->getSegmento()?->getSlug() ?? '(sin segmento)';
            $antes = sprintf(
                '%s-%s',
                $enlace->getHora()?->format('H:i') ?? '—',
                $enlace->getHoraFin()?->format('H:i') ?? '—',
            );

            $io->text(sprintf(
                '  %s · %-34s %-16s %-12s → sin hora',
                $simula ? 'cambiaría' : 'cambiado ',
                $slug,
                $tipo->value,
                $antes,
            ));
            ++$tocados;

            if (!$simula) {
                $enlace->setHora(null);
                $enlace->setHoraFin(null);
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
