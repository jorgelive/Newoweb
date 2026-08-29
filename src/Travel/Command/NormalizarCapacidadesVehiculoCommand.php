<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\TarifaModalidadEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Una plaza por vehículo, la misma en todo el catálogo.
 *
 * Antes de esto, la misma palabra declaraba cosas distintas según dónde se mirara: `Van` entre 3
 * y 8 plazas, `Auto` entre 2 y 6, y unas cuantas «sin medir», que es peor que un número
 * equivocado porque no se puede contrastar con nada.
 *
 * ⚠️ **Una capacidad de más vende una plaza que no existe**, y eso se descubre en el aeropuerto
 * con el grupo delante. Un precio mal se corrige en la factura; esto no.
 *
 * ## Qué entra y qué no
 *
 * Sólo las tarifas **`privado` y por grupo**: son las que significan «alquilo el vehículo
 * entero», y ahí la capacidad ES la del vehículo. En una `compartido` se vende asiento —Cruz del
 * Sur, Bus Bimodal, los pools— y ponerle la capacidad del vehículo limitaría el grupo por un
 * número que no tiene nada que ver.
 *
 * ⚠️ **`Bus` y `Minibús` quedan FUERA a propósito.** No es un olvido: hay tarifas donde al
 * Sprinter se le llama «minibús» de cara al cliente, así que los dos nombres no describen hoy el
 * mismo vehículo en todo el catálogo. Fijarles una capacidad sería congelar la confusión en un
 * número. Primero hay que acordar el vocabulario; los números vienen después.
 */
#[AsCommand(
    name: 'app:travel:normalizar-capacidades-vehiculo',
    description: 'Iguala las plazas por vehículo en las tarifas privadas por grupo.',
)]
final class NormalizarCapacidadesVehiculoCommand extends Command
{
    /**
     * Dictadas por el operador el 29/08/2026.
     *
     * @var array<string, int>
     */
    private const CAPACIDADES = [
        'Auto' => 3,
        'Van' => 5,
        'Master' => 10,
        'Sprinter' => 15,
    ];

    /** Los que esperan a que se acuerde qué son. @var list<string> */
    private const SIN_DEFINIR = ['Bus', 'Minibús', 'Minibus'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué cambiaría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $suben = [];
        $bajan = [];
        $miden = [];
        $fuera = [];

        foreach ($this->em->getRepository(TravelTarifa::class)->findAll() as $tarifa) {
            if ($tarifa->getComponente()?->getTipo() !== ComponenteTipoEnum::TRANSPORTE) {
                continue;
            }

            $nombre = trim($tarifa->getNombreInterno() ?? '');
            $vehiculo = trim(explode(' ', $nombre)[0]);

            if (in_array($vehiculo, self::SIN_DEFINIR, true)) {
                $fuera[$vehiculo] = ($fuera[$vehiculo] ?? 0) + 1;
                continue;
            }

            if (!isset(self::CAPACIDADES[$vehiculo])) {
                continue;
            }

            // «Alquilo el vehículo entero» es lo único donde la capacidad es la del vehículo.
            if ($tarifa->getModalidad() !== TarifaModalidadEnum::PRIVADO || !$tarifa->isCostoPorGrupo()) {
                $fuera['(compartido o por pax) ' . $vehiculo] = ($fuera['(compartido o por pax) ' . $vehiculo] ?? 0) + 1;
                continue;
            }

            $debe = self::CAPACIDADES[$vehiculo];
            $tiene = $tarifa->getCapacidadMaxima();

            if ($tiene === $debe) {
                continue;
            }

            $linea = sprintf('%s · %s: %s → %d', $tarifa->getComponente()->getNombreInterno() ?? '', $nombre, $tiene ?? 'sin medir', $debe);

            match (true) {
                $tiene === null => $miden[] = $linea,
                $tiene < $debe => $suben[] = $linea,
                default => $bajan[] = $linea,
            };

            if (!$simula) {
                $tarifa->setCapacidadMaxima($debe);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        // ⚠️ Separadas a propósito. Que BAJE una capacidad es inofensivo —se vende de menos—;
        // que SUBA permite meter gente donde antes no cabía, y eso hay que mirarlo una a una.
        $this->bloque($io, 'Suben — se podrá vender más grupo del que se podía', $suben, 'yellow');
        $this->bloque($io, 'Bajan — inofensivo: se vende de menos', $bajan, 'default');
        $this->bloque($io, 'Estaban «sin medir»', $miden, 'default');

        if ($fuera !== []) {
            $io->section('Fuera del alcance, a propósito');
            foreach ($fuera as $que => $cuantas) {
                $io->text(sprintf('  %-34s %d tarifas', $que, $cuantas));
            }
            $io->text('  Bus y Minibús esperan a que se acuerde qué vehículo es cada uno.');
        }

        if ($simula) {
            $io->newLine();
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /** @param list<string> $lineas */
    private function bloque(SymfonyStyle $io, string $titulo, array $lineas, string $color): void
    {
        if ($lineas === []) {
            return;
        }

        $io->section(sprintf('%s (%d)', $titulo, count($lineas)));

        foreach ($lineas as $linea) {
            $io->text($color === 'default' ? '  ' . $linea : sprintf('  <fg=%s>%s</>', $color, $linea));
        }
    }
}
