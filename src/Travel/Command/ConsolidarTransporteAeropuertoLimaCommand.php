<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\TarifaModalidadEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deja UN componente para el traslado aeropuerto de Lima ↔ Miraflores.
 *
 * Había tres haciendo lo mismo, y uno lo creé yo:
 *
 * - `Transporte Aeropuerto Lima - Hotel Lima` — precios reales, día y noche, 1 enlace.
 * - `Transporte Hotel Lima - Aeropuerto de Lima` — los mismos precios, 1 enlace.
 * - `Transporte Aeropuerto Lima ↔ Miraflores` — con Minibús y Bus para 131 pax, **todo a cero**,
 *   y con los 3 enlaces.
 *
 * Los dos primeros no se fusionaron en la fase 1 porque sus nombres **no se reflejan**:
 * «Aeropuerto Lima» contra «Aeropuerto **de** Lima». Una preposición basta para que el par pase
 * desapercibido, y es la razón de que la detección automática no sustituya a mirar la lista.
 *
 * ## El cuadro
 *
 * Gana el nombre bidireccional —es el que tiene los enlaces y el que ya avisa de que sirve en los
 * dos sentidos— y se queda con los precios de verdad. Minibús y Bus entran a **0 a propósito**:
 * hacen falta para poder cotizar un grupo grande, y el precio se pone cuando se negocie.
 *
 * ⚠️ La **Van baja de 8 a 6 plazas**. Divergían entre los dos sentidos y la regla es la menor:
 * vender una plaza que no existe se descubre en el aeropuerto.
 *
 * ⚠️ La noche es **+20% exacto** en los cuatro vehículos con precio (70→84, 110→132, 200→240,
 * 280→336). No se calcula aquí —un patrón observado no es una regla de negocio— pero si mañana
 * se ponen los precios del Bus, ése es el número que hay que confirmar.
 */
#[AsCommand(
    name: 'app:travel:consolidar-transporte-aeropuerto-lima',
    description: 'Funde los tres traslados aeropuerto Lima ↔ Miraflores en uno, con su cuadro.',
)]
final class ConsolidarTransporteAeropuertoLimaCommand extends Command
{
    private const CANONICO = 'Transporte Aeropuerto Lima ↔ Miraflores (ida o vuelta)';

    /** @var list<string> */
    private const ABSORBIDOS = [
        'Transporte Aeropuerto Lima - Hotel Lima',
        'Transporte Hotel Lima - Aeropuerto de Lima',
    ];

    /**
     * La foto final. Cualquier tarifa de los tres componentes que no esté aquí, se va.
     *
     * @var list<array{nombre: string, monto: string, cap: int}>
     */
    private const CUADRO = [
        ['nombre' => 'Auto Día', 'monto' => '70.00', 'cap' => 4],
        ['nombre' => 'Auto Noche', 'monto' => '84.00', 'cap' => 4],
        ['nombre' => 'Van Día', 'monto' => '110.00', 'cap' => 6],
        ['nombre' => 'Van Noche', 'monto' => '132.00', 'cap' => 6],
        ['nombre' => 'Master Día', 'monto' => '200.00', 'cap' => 14],
        ['nombre' => 'Master Noche', 'monto' => '240.00', 'cap' => 14],
        ['nombre' => 'Sprinter Día', 'monto' => '280.00', 'cap' => 14],
        ['nombre' => 'Sprinter Noche', 'monto' => '336.00', 'cap' => 14],
        ['nombre' => 'Minibús Día', 'monto' => '0.00', 'cap' => 25],
        ['nombre' => 'Minibús Noche', 'monto' => '0.00', 'cap' => 25],
        ['nombre' => 'Bus Día', 'monto' => '0.00', 'cap' => 45],
        ['nombre' => 'Bus Noche', 'monto' => '0.00', 'cap' => 45],
    ];

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

        $canonico = $this->componente(self::CANONICO);

        if ($canonico === null) {
            $io->error(sprintf('No existe «%s»: ya se consolidó, o cambió de nombre.', self::CANONICO));

            return Command::SUCCESS;
        }

        $io->title('Aeropuerto de Lima ↔ Miraflores');

        $moneda = $this->monedaDe($canonico);

        // 1 · El cuadro primero: hace falta que exista el destino antes de mover nada hacia él.
        $io->section('El cuadro');
        /** @var array<string, TravelTarifa> $destino */
        $destino = [];

        // ⚠️ El conjunto de nombres se llena SIEMPRE, también en ensayo. Con sólo el mapa de
        // entidades, el ensayo —que no crea nada— informaba «sin equivalente» en las 24 tarifas
        // y daba a entender que el mapeo estaba roto justo cuando está bien. Un preview que
        // miente es peor que no tenerlo: se usa para decidir si aplicar.
        /** @var array<string, true> $nombresDestino */
        $nombresDestino = [];

        foreach (self::CUADRO as $fila) {
            $nombresDestino[$fila['nombre']] = true;
        }

        foreach (self::CUADRO as $fila) {
            $tarifa = $this->tarifaPorNombre($canonico, $fila['nombre']);

            if ($tarifa === null) {
                $io->text(sprintf('  %s · %-15s %s / cap %d', $simula ? 'crearía ' : 'creada  ', $fila['nombre'], $fila['monto'], $fila['cap']));

                if (!$simula) {
                    $tarifa = new TravelTarifa();
                    $tarifa->setComponente($canonico);
                    $tarifa->setNombreInterno($fila['nombre']);
                    $tarifa->setTitulo([['language' => 'es', 'content' => $fila['nombre']]]);
                    $tarifa->setMoneda($moneda);
                    $tarifa->setMonto($fila['monto']);
                    $tarifa->setModalidad(TarifaModalidadEnum::PRIVADO);
                    $tarifa->setCostoPorGrupo(true);
                    $tarifa->setCapacidadMaxima($fila['cap']);
                    $this->em->persist($tarifa);
                }
            } else {
                $io->text(sprintf('  ajusta   · %-15s %s / cap %d', $fila['nombre'], $fila['monto'], $fila['cap']));

                if (!$simula) {
                    $tarifa->setMonto($fila['monto']);
                    $tarifa->setCapacidadMaxima($fila['cap']);
                }
            }

            if ($tarifa !== null) {
                $destino[$fila['nombre']] = $tarifa;
            }
        }

        // 2 · Lo viejo cede y se va.
        $io->section('Lo que se retira');

        foreach ([$canonico, ...array_filter(array_map($this->componente(...), self::ABSORBIDOS))] as $fuente) {
            foreach ($fuente->getTarifas() as $vieja) {
                $nombre = $vieja->getNombreInterno() ?? '';

                if (isset($nombresDestino[$nombre])) {
                    continue;
                }

                $nombreEquivalente = $this->equivalente($nombre);
                $existe = isset($nombresDestino[$nombreEquivalente]);

                $io->text(sprintf(
                    '  retira · %-58s → %s',
                    $nombre,
                    $existe ? $nombreEquivalente : '<fg=red>SIN EQUIVALENTE — se queda</>',
                ));

                if (!$simula && $existe) {
                    $this->ceder($vieja, $destino[$nombreEquivalente]);
                    $this->em->remove($vieja);
                }
            }
        }

        // 3 · Enlaces y citas de los absorbidos.
        $io->section('Lo que se muda');

        foreach (self::ABSORBIDOS as $nombre) {
            $sobra = $this->componente($nombre);

            if ($sobra === null) {
                continue;
            }

            foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $sobra]) as $rel) {
                $io->text(sprintf('  segmento · %s', $rel->getSegmento()?->getSlug() ?? '?'));

                if (!$simula) {
                    $rel->setComponente($canonico);
                }
            }

            $citas = $this->em->getRepository(CotizacionCotcomponente::class)
                ->findBy(['componenteMaestroId' => (string) $sobra->getId()]);

            if ($citas !== []) {
                $io->text(sprintf('  %d líneas de cotización reapuntadas desde «%s»', count($citas), $nombre));

                if (!$simula) {
                    foreach ($citas as $cita) {
                        // El snapshot NO se toca: dice lo que se vendió. Sólo el puntero al maestro.
                        $cita->setComponenteMaestroId((string) $canonico->getId());
                    }
                }
            }

            if (!$simula) {
                foreach ($sobra->getServicios() as $servicio) {
                    $servicio->removeComponente($sobra);
                }
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->warning('Minibús y Bus quedan a 0 a propósito: existen para poder cotizar un grupo grande, el precio se pone al negociarlo.');

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * De un nombre viejo al del cuadro: vehículo + turno.
     *
     * «Auto a Miraflores Noche» → «Auto Noche». «Van · Transporte del aeropuerto…» → «Van Día»,
     * porque las que creé sin turno eran de día por defecto.
     */
    private function equivalente(string $nombreViejo): string
    {
        $vehiculo = trim(explode('·', $nombreViejo)[0]);
        $vehiculo = trim(explode(' ', $vehiculo)[0]);
        $turno = str_contains(mb_strtolower($nombreViejo), 'noche') ? 'Noche' : 'Día';

        return sprintf('%s %s', $vehiculo, $turno);
    }

    private function ceder(TravelTarifa $muere, TravelTarifa $sobrevive): void
    {
        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['tarifaPredeterminada' => $muere]) as $rel) {
            $rel->setTarifaPredeterminada($sobrevive);
        }

        foreach ($this->em->getRepository(CotizacionCottarifa::class)->findBy(['tarifaMaestraId' => (string) $muere->getId()]) as $cita) {
            $cita->setTarifaMaestraId((string) $sobrevive->getId());
        }
    }

    private function tarifaPorNombre(TravelComponente $componente, string $nombre): ?TravelTarifa
    {
        foreach ($componente->getTarifas() as $t) {
            if ($t->getNombreInterno() === $nombre) {
                return $t;
            }
        }

        return null;
    }

    private function componente(string $nombreInterno): ?TravelComponente
    {
        return $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombreInterno]);
    }

    private function monedaDe(TravelComponente $componente): ?\App\Entity\Maestro\MaestroMoneda
    {
        foreach ($componente->getTarifas() as $t) {
            if ($t->getMoneda() !== null) {
                return $t->getMoneda();
            }
        }

        return null;
    }
}
