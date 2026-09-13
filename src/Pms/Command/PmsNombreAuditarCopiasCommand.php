<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Contract\Nombre\PropagadorDeNombre;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cuántas copias del nombre de un huésped están hoy diciendo el nombre viejo.
 *
 * 🔥 **Existe porque la pregunta «¿en cuántos sitios se copia esto?» no tenía respuesta.** El
 * nombre se corrige en la reserva y se queda viejo en las copias; averiguar cuáles había costó
 * una auditoría a mano del código entero. Con el contrato puesto, cada sitio contesta por sí
 * mismo y esto sólo suma.
 *
 * Sólo LEE. Corregir es trabajo de {@see PropagadorDeNombre}, que se dispara solo cuando el nombre
 * cambia — si aquí sale un número distinto de cero, es que algo se escapó antes de que existiera
 * el mecanismo, no que la propagación esté fallando hoy.
 */
#[AsCommand(
    name: 'pms:nombre:auditar-copias',
    description: 'Cuántas copias del nombre del huésped están desincronizadas, por sitio.',
)]
final class PmsNombreAuditarCopiasCommand extends Command
{
    public function __construct(private readonly PropagadorDeNombre $propagador)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $informe = $this->propagador->auditar();

        if ($informe === []) {
            $io->warning('Nadie implementa CopiaDelNombre. O no hay copias, o no están declaradas.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Copia', 'Desincronizadas'],
            array_map(
                static fn (string $que, int $n): array => [$que, $n === 0 ? '—' : (string) $n],
                array_keys($informe),
                $informe,
            ),
        );

        $total = array_sum($informe);

        if ($total > 0) {
            $io->warning(sprintf('%d copias dicen un nombre que ya no es el de su reserva.', $total));

            return Command::FAILURE;
        }

        $io->success('Todas las copias declaradas dicen lo mismo que su reserva.');

        return Command::SUCCESS;
    }
}
