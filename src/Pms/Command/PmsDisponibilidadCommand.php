<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Service\Reserva\PmsDisponibilidadService;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Uso:
 *   php bin/console app:pms:disponibilidad 2026-03-12 2026-03-15
 *   php bin/console app:pms:disponibilidad 2026-03-12 2026-03-15 --pax=3
 *
 * Es la vía rápida para comprobar `PmsDisponibilidadService` contra datos reales sin pasar
 * por el panel — el proyecto no tiene tests, así que este comando ES la prueba de la regla
 * de solape. La comprobación que importa: una casita ocupada tiene que DESAPARECER del
 * listado, y reaparecer el día en que su huésped se va.
 */
#[AsCommand(
    name: 'app:pms:disponibilidad',
    description: 'Lista las casitas libres en un rango de fechas.',
)]
final class PmsDisponibilidadCommand extends Command
{
    public function __construct(
        private readonly PmsDisponibilidadService $disponibilidad
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('desde', InputArgument::REQUIRED, 'Fecha de entrada (YYYY-MM-DD)')
            ->addArgument('hasta', InputArgument::REQUIRED, 'Fecha de salida (YYYY-MM-DD)')
            ->addOption('pax', null, InputOption::VALUE_REQUIRED, 'Número de personas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pax = $input->getOption('pax');

        try {
            $libres = $this->disponibilidad->buscar(
                new DateTimeImmutable((string) $input->getArgument('desde')),
                new DateTimeImmutable((string) $input->getArgument('hasta')),
                $pax !== null ? (int) $pax : null,
            );
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        } catch (Throwable $e) {
            $io->error('No se pudo consultar la disponibilidad: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($libres === []) {
            $io->warning('Sin disponibilidad en ese rango.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Casita', 'Establecimiento', 'Cap.', 'Tarifa base'],
            array_map(
                static fn ($u) => [
                    $u->nombre,
                    $u->establecimiento,
                    $u->capacidad ?? '—',
                    trim($u->tarifaBase . ' ' . ($u->moneda ?? '')),
                ],
                $libres
            )
        );

        $io->success(sprintf('%d casita(s) libre(s).', count($libres)));

        return Command::SUCCESS;
    }
}
