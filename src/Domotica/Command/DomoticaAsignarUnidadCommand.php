<?php

declare(strict_types=1);

namespace App\Domotica\Command;

use App\Domotica\Repository\DomoticaDispositivoRepository;
use App\Pms\Entity\PmsUnidad;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ata un aparato a su casita.
 *
 * ── Por qué es un paso aparte, y por qué no lo adivina nadie ────────────────
 *
 * Es la asignación de la que cuelga TODO lo que ve el huésped: `pax` resuelve qué aparatos le
 * tocan yendo de su reserva a los eventos, de ahí a la unidad y de la unidad al dispositivo
 * (`docs/Domotica.md` §12.2). Sin unidad, un aparato existe pero es invisible — y con la unidad
 * equivocada le enseña a alguien el consumo de otra casa, que es una fuga de privacidad y una
 * factura mal emitida a la vez.
 *
 * Por eso NO se infiere del nombre. Los nombres de Tuya llevan ordinales («E 6to Matrimonial J»)
 * que se parecen mucho a un número de casita, y parecerse no basta cuando el error se paga en
 * dinero ajeno: los pone una persona, de uno en uno, y el comando enseña lo que va a hacer antes
 * de hacerlo.
 *
 *   app:domotica:asignar-unidad                                      → qué hay y qué falta
 *   app:domotica:asignar-unidad --aparato=Matrimonial --unidad="Casita 6" --dry-run
 */
#[AsCommand(
    name: 'app:domotica:asignar-unidad',
    description: 'Ata un aparato de Tuya a una casita, o lista lo que falta por atar.'
)]
final class DomoticaAsignarUnidadCommand extends Command
{
    public function __construct(
        private readonly DomoticaDispositivoRepository $dispositivos,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('aparato', null, InputOption::VALUE_REQUIRED, 'Id de Tuya, o parte del nombre.')
            ->addOption('unidad', null, InputOption::VALUE_REQUIRED, 'Nombre de la casita. «-» la desata.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dice qué haría, sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $aparatoBuscado = $input->getOption('aparato');
        $unidadBuscada = $input->getOption('unidad');
        $seco = (bool) $input->getOption('dry-run');

        // Sin argumentos: la foto. Es lo primero que se quiere ver.
        if (!is_string($aparatoBuscado) || $aparatoBuscado === '') {
            return $this->listar($io);
        }

        $candidatos = [];

        foreach ($this->dispositivos->findAll() as $d) {
            if ($d->getTuyaDeviceId() === $aparatoBuscado
                || mb_stripos($d->getNombre(), $aparatoBuscado) !== false) {
                $candidatos[] = $d;
            }
        }

        if ($candidatos === []) {
            $io->error(sprintf('Ningún aparato casa con «%s».', $aparatoBuscado));

            return Command::FAILURE;
        }

        // Ambiguo NO se resuelve por orden alfabético ni cogiendo el primero: se pregunta. Elegir
        // por el aparato es justo lo que este comando existe para no hacer.
        if (count($candidatos) > 1) {
            $io->error(sprintf('«%s» casa con %d aparatos. Concreta más:', $aparatoBuscado, count($candidatos)));

            foreach ($candidatos as $c) {
                $io->text(sprintf('  · %s  (%s)', $c->getNombre(), $c->getTuyaDeviceId()));
            }

            return Command::FAILURE;
        }

        $dispositivo = $candidatos[0];

        if (!is_string($unidadBuscada) || $unidadBuscada === '') {
            $io->error('Falta --unidad (o «-» para desatar).');

            return Command::FAILURE;
        }

        if ($unidadBuscada === '-') {
            $antes = $dispositivo->getUnidad()?->getNombre() ?? '(ninguna)';

            if (!$seco) {
                $dispositivo->setUnidad(null);
                $this->em->flush();
            }

            $io->success(sprintf('%s: %s → (ninguna).%s', $dispositivo->getNombre(), $antes, $seco ? ' [dry-run]' : ''));

            return Command::SUCCESS;
        }

        $unidad = null;

        foreach ($this->unidades() as $u) {
            if (mb_strtolower($u->getNombre() ?? '') === mb_strtolower($unidadBuscada)) {
                $unidad = $u;
                break;
            }
        }

        if ($unidad === null) {
            $io->error(sprintf('No hay ninguna unidad llamada «%s».', $unidadBuscada));
            $io->text('Las que hay:');

            foreach ($this->unidades() as $u) {
                $io->text('  · ' . ($u->getNombre() ?? '?'));
            }

            return Command::FAILURE;
        }

        $antes = $dispositivo->getUnidad()?->getNombre() ?? '(ninguna)';

        if (!$seco) {
            $dispositivo->setUnidad($unidad);
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s: %s → %s.%s',
            $dispositivo->getNombre(),
            $antes,
            $unidad->getNombre() ?? '?',
            $seco ? ' [dry-run]' : ''
        ));

        return Command::SUCCESS;
    }

    /**
     * Las casitas. Por el gestor y no por un repositorio propio, porque `PmsUnidad` no tiene uno:
     * inventarlo para dos `findAll()` sería añadir una clase para no usarla.
     *
     * @return list<PmsUnidad>
     */
    private function unidades(): array
    {
        return $this->em->getRepository(PmsUnidad::class)->findBy([], ['nombre' => 'ASC']);
    }

    private function listar(SymfonyStyle $io): int
    {
        $filas = [];
        $sinAtar = 0;

        foreach ($this->dispositivos->findAll() as $d) {
            $unidad = $d->getUnidad()?->getNombre();

            if ($unidad === null) {
                $sinAtar++;
            }

            $filas[] = [
                $d->getNombre(),
                $d->mideConsumo() ? 'mide' : '',
                $d->isConmutable() ? 'conmuta' : '',
                $unidad ?? '— SIN ATAR —',
            ];
        }

        $io->table(['Aparato', '', '', 'Casita'], $filas);

        if ($sinAtar > 0) {
            $io->warning(sprintf(
                '%d aparato(s) sin casita. Mientras siga así, `pax` no puede enseñarlos: la reserva '
                . 'llega al aparato por la unidad del evento, y sin unidad no hay camino.',
                $sinAtar
            ));
        } else {
            $io->success('Todos atados.');
        }

        return Command::SUCCESS;
    }
}
