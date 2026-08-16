<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Service\InclusionComponenteIdBackfill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Uso: php bin/console app:cotizacion:backfill-componente-id [--dry-run]
 *
 * Enlaza las líneas de inclusión ya guardadas con su componente.
 *
 * ⚠️ **En un despliegue normal no hace falta correrlo**: lo ejecuta sola la migración
 * `Version20260816230000`. Este comando existe para poder repetirlo cuando haga falta —
 * tras importar datos, o para ver con `--dry-run` qué quedaría sin enlazar— sin tener que
 * inventar una migración nueva.
 *
 * La lógica vive en {@see InclusionComponenteIdBackfill}, compartida con la migración: dos
 * copias de un emparejador delicado se separan solas.
 */
#[AsCommand(
    name: 'app:cotizacion:backfill-componente-id',
    description: 'Enlaza las líneas de inclusión ya guardadas con su componente.',
)]
final class CotizacionBackfillComponenteIdCommand extends Command
{
    public function __construct(private readonly InclusionComponenteIdBackfill $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'No escribe: sólo informa.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('DRY-RUN: no se escribe nada.');
        }

        $r = $this->backfill->ejecutar($dryRun);

        if ($r['ambiguas'] > 0) {
            $io->warning(sprintf(
                '%d clave(s) ambigua(s): hay componentes que comparten servicio, nombre y fecha. '
                . 'Sus líneas se dejan sin enlazar a propósito.',
                $r['ambiguas']
            ));
        }

        $io->success(sprintf(
            '%d cotización(es) %s · %d línea(s) enlazadas · %d sin pareja',
            $r['cotizaciones'],
            $dryRun ? 'se actualizarían' : 'actualizadas',
            $r['lineas'],
            $r['huerfanas'],
        ));

        if ($r['huerfanas'] > 0) {
            $io->warning(sprintf(
                '%d línea(s) no se pudieron emparejar sin ambigüedad y quedaron SIN enlazar. '
                . 'Su proveedor no se mostrará hasta re-guardar esa propuesta en el editor.',
                $r['huerfanas']
            ));
        }

        return Command::SUCCESS;
    }
}
