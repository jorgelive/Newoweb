<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renombra un componente del catálogo, nombre interno y título público a la vez.
 *
 * ## ⚠️ Por qué esto NO puede ser una migración
 *
 * `TravelComponente::$titulo` lleva `#[AutoTranslate]`, y el listener se engancha a `preUpdate`.
 * Un `UPDATE` en SQL se lo salta: dejaría el español con el nombre nuevo y los otros seis idiomas
 * con el viejo, que es peor que no traducir —el dato queda **vivo y mintiendo**, y nada falla—.
 * Pasando por el ORM se retraducen los siete.
 *
 * Ver el reparto migración vs. comando en CLAUDE.md.
 *
 * ## Genérico a propósito
 *
 * Nace de renombrar «Ticket aereo» a «Vuelo», pero un comando de un solo uso es un archivo que
 * queda para siempre sirviendo para nada. Con los dos nombres por argumento vale para el
 * siguiente, que en un catálogo que se está ordenando va a haberlo.
 */
#[AsCommand(
    name: 'app:travel:renombrar-componente',
    description: 'Renombra un componente (nombre interno y título) pasando por el ORM, para que se retraduzca.',
)]
final class RenombrarComponenteCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('actual', InputArgument::REQUIRED, 'Nombre interno de hoy.');
        $this->addArgument('nuevo', InputArgument::REQUIRED, 'Nombre interno nuevo.');
        $this->addOption('solo-interno', null, InputOption::VALUE_NONE, 'No toca el título público.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');
        $actual = (string) $input->getArgument('actual');
        $nuevo = (string) $input->getArgument('nuevo');

        $repo = $this->em->getRepository(TravelComponente::class);
        $componente = $repo->findOneBy(['nombreInterno' => $actual]);

        if ($componente === null) {
            $io->error(sprintf('No existe ningún componente llamado «%s».', $actual));

            return Command::FAILURE;
        }

        // El nombre interno es con lo que se busca y se ordena: dos iguales son indistinguibles
        // en el panel, y ya pasó con las tarifas de check-in del resort.
        if ($repo->findOneBy(['nombreInterno' => $nuevo]) !== null) {
            $io->error(sprintf('Ya hay otro componente llamado «%s». No se renombra.', $nuevo));

            return Command::FAILURE;
        }

        $io->text(sprintf('  %s · «%s» → «%s»', $simula ? 'renombraría' : 'renombrado ', $actual, $nuevo));

        $tocaTitulo = !$input->getOption('solo-interno');

        if ($tocaTitulo) {
            $io->text(sprintf('  título público: %d idioma(s) se retraducen', count($componente->getTitulo())));
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');

            return Command::SUCCESS;
        }

        $componente->setNombreInterno($nuevo);

        if ($tocaTitulo) {
            // Sólo el español: `#[AutoTranslate]` rellena los demás al guardar. Escribir aquí los
            // siete sería adelantarse al listener y quedarse con la traducción de uno mismo.
            $componente->setTitulo([['language' => 'es', 'content' => $nuevo]]);
        }

        $this->em->flush();

        $io->success('Renombrado.');

        return Command::SUCCESS;
    }
}
