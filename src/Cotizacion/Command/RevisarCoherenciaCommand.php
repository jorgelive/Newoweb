<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Service\CoherenciaCatalogoChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Revisa —y opcionalmente repara— las configuraciones a medias del catálogo y las cotizaciones.
 *
 * Sin opciones **sólo mira**. Con `--reparar` arregla lo que tiene una única respuesta posible y
 * deja intacto lo que es una decisión. El porqué de ese corte está en
 * {@see CoherenciaCatalogoChecker}.
 *
 * Pensado para correrse a mano después de una carga de catálogo, y para dejarlo en el cron si
 * alguna vez interesa vigilarlo solo. Devuelve código 1 si encuentra algo, para que un cron pueda
 * avisar sin leer la salida.
 */
#[AsCommand(
    name: 'app:cotizacion:revisar-coherencia',
    description: 'Encuentra ids sin nombre, servicios sin título y demás configuraciones a medias.',
)]
final class RevisarCoherenciaCommand extends Command
{
    public function __construct(private readonly CoherenciaCatalogoChecker $checker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reparar', null, InputOption::VALUE_NONE, 'Arregla lo que no admite interpretación.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $reparar = (bool) $input->getOption('reparar');

        $hallazgos = $this->checker->revisar($reparar);

        if ($hallazgos === []) {
            $io->success('Todo coherente: ningún id sin su nombre, ningún servicio sin título.');

            return Command::SUCCESS;
        }

        $reparados = array_filter($hallazgos, static fn (array $h): bool => $h['reparable']);
        $avisos    = array_filter($hallazgos, static fn (array $h): bool => !$h['reparable']);

        if ($reparados !== []) {
            $io->section($reparar ? 'Reparado' : 'Reparable (falta --reparar)');

            foreach ($reparados as $h) {
                $io->text(sprintf('  %4d · %s', $h['filas'], $h['titulo']));
                $io->text(sprintf('         %s', $h['detalle']));
            }
        }

        if ($avisos !== []) {
            $io->section('Para mirar a mano — es una decisión, no un descuido');

            foreach ($avisos as $h) {
                $io->text(sprintf('  %4d · %s', $h['filas'], $h['titulo']));
                $io->text(sprintf('         %s', $h['detalle']));
            }
        }

        if (!$reparar && $reparados !== []) {
            $io->newLine();
            $io->text('Repetir con <info>--reparar</info> para arreglar el primer bloque.');
        }

        // Código 1 si queda algo por mirar: sirve para encadenarlo en un cron.
        return $avisos !== [] || (!$reparar && $reparados !== []) ? Command::FAILURE : Command::SUCCESS;
    }
}
