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
 * cambia.
 *
 * ⚠️ **Un número distinto de cero NO baja solo.** Este recuento y la propagación usan definiciones
 * distintas a propósito: aquí se cuenta lo que *parece* nuestro —el par en cualquier orden y
 * cualquier caja—, mientras que corregir sólo pisa lo que coincide **exactamente** con el nombre
 * anterior, para no borrar un título escrito a mano. Así que una copia heredada de antes de este
 * mecanismo se cuenta aquí y la propagación nunca la tocará: eso se salda con
 * `pms:titulo-cache:resincronizar`, no esperando al siguiente cambio de nombre.
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
