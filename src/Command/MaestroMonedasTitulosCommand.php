<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Maestro\MaestroMoneda;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fija a mano el nombre de cada moneda en los siete idiomas (`MaestroMoneda::$titulo`).
 *
 * A mano y no con el traductor: una palabra suelta sale mal —ya pasó con «Siguiente», que dio
 * «Following»—, y el sol peruano no se traduce: «Soles» en todos los idiomas, como lo escriben los
 * bancos. Por el ORM porque el campo lleva `#[AutoTranslate]` (CLAUDE.md), con el listener apagado
 * en estas filas y cada traducción marcada `manual` para que ningún guardado posterior la rehaga.
 *
 * Idempotente: sólo escribe lo que difiere. Una moneda que no está aquí conserva lo que tenga.
 *
 *   php bin/console app:maestro:monedas:titulos --dry-run
 *   php bin/console app:maestro:monedas:titulos
 */
#[AsCommand(
    name: 'app:maestro:monedas:titulos',
    description: 'Fija a mano el nombre de las monedas en los siete idiomas. Idempotente.',
)]
final class MaestroMonedasTitulosCommand extends Command
{
    /** @var array<string, array<string, string>> */
    private const array TITULOS = [
        MaestroMoneda::DB_ID_SOL => [
            'es' => 'Soles', 'en' => 'Soles', 'pt' => 'Soles', 'fr' => 'Soles', 'it' => 'Soles', 'de' => 'Soles', 'nl' => 'Soles',
        ],
        MaestroMoneda::DB_ID_USD => [
            'es' => 'Dólares', 'en' => 'Dollars', 'pt' => 'Dólares', 'fr' => 'Dollars', 'it' => 'Dollari', 'de' => 'Dollar', 'nl' => 'Dollars',
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');
        $cambios = [];

        foreach (self::TITULOS as $id => $porIdioma) {
            $moneda = $this->em->find(MaestroMoneda::class, $id);
            if (!$moneda instanceof MaestroMoneda) {
                $io->warning(sprintf('No existe la moneda %s: se salta.', $id));
                continue;
            }

            // ⚠️ `origenHash: 'manual'` en cada traducción: una fila SIN hash se rehace en el
            // siguiente guardado (docs/Autotraduccion.md §2), y el primer «guardar» en el panel
            // habría devuelto «Following» donde se escribió «Next». El español es el origen: no lleva.
            $titulo = [];
            foreach ($porIdioma as $idioma => $texto) {
                $titulo[] = $idioma === 'es'
                    ? ['language' => $idioma, 'content' => $texto]
                    : ['language' => $idioma, 'content' => $texto, 'origenHash' => 'manual'];
            }

            if ($moneda->getTitulo() === $titulo) {
                continue;
            }

            $cambios[] = [$id, implode(' · ', $porIdioma)];

            if (!$simular) {
                $moneda->setEjecutarTraduccion(false);
                $moneda->setTitulo($titulo);
            }
        }

        if ($cambios === []) {
            $io->success('Los nombres ya estaban al día.');

            return Command::SUCCESS;
        }

        $io->table(['moneda', 'nombres'], $cambios);

        if ($simular) {
            $io->note('--dry-run: no se escribió nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d moneda(s) al día.', count($cambios)));

        return Command::SUCCESS;
    }
}
