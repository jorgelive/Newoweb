<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelOrganizacionServicio;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enlaza cada tarifa de tren con la CLASE de servicio que le corresponde.
 *
 * ── Por qué en la tarifa y no en el componente ──────────────────────────────
 * La tarifa maestra ya lleva `prestador` y `comprador`, y el editor los copia al componente al
 * elegirla (`cotizacionEditorStore`, la construcción del snapshot de tarifa). Lo que faltaba era
 * el tercer dato, `prestadorServicio` — la clase—, que es justo lo que el pasajero quiere saber:
 * no es lo mismo un Expedition que un Hiram Bingham.
 *
 * Puesto aquí, se propaga solo: quien cotice un tren no tiene que acordarse de nada.
 *
 * ── El mapeo ────────────────────────────────────────────────────────────────
 * Por prefijo del nombre interno, que es la convención que ya usaba el catálogo: `PR` = PeruRail,
 * `IR` = IncaRail. Se comprueba además que el prestador de la tarifa coincida con la empresa
 * dueña de la clase: si alguien renombra una tarifa mal, se salta en vez de enlazar cualquier cosa.
 *
 * ⚠️ **No inventa prestador ni comprador.** Una tarifa sin prestador no se toca: `Local`, `Guía` y
 * `No necesario` no llevan ninguno, y decidir de quién son es criterio comercial, no de un script.
 *
 * Idempotente: sólo rellena lo que está vacío.
 */
#[AsCommand(
    name: 'app:travel:vincular-servicio-ferroviario',
    description: 'Pone la clase de tren (Expedition, Vistadome…) en las tarifas que la tienen vacía.',
)]
final class VincularServicioFerroviarioEnTarifasCommand extends Command
{
    /**
     * Prefijo del nombre de la tarifa → [empresa, clase]. El orden importa: los más largos
     * primero, porque «PR Vistadome Observatory» empieza por «PR Vistadome».
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const MAPA = [
        ['PR Observatory',    'PeruRail', 'Vistadome Observatory'],
        ['PR Hiram Bingham',  'PeruRail', 'Hiram Bingham'],
        ['PR Expedition',     'PeruRail', 'Expedition'],
        ['PR Vistadome',      'PeruRail', 'Vistadome'],
        ['PR Local',          'PeruRail', 'Local'],
        // Dos grafías para la misma clase, heredadas del catálogo: «IR 360» e «IR T360».
        ['IR First Class',    'IncaRail', 'First Class'],
        ['IR Voyager',        'IncaRail', 'Voyager'],
        ['IR Prime',          'IncaRail', 'Prime'],
        ['IR T360',           'IncaRail', 'The 360°'],
        ['IR 360',            'IncaRail', 'The 360°'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        /** @var array<string, TravelOrganizacionServicio> $clases */
        $clases = [];

        foreach ($this->em->getRepository(TravelOrganizacionServicio::class)->findAll() as $s) {
            $empresa = $s->getOrganizacion()?->getNombreComercial();

            if ($empresa !== null) {
                $clases[$empresa . '|' . $s->getNombre()] = $s;
            }
        }

        $tarifas = $this->em->getRepository(TravelTarifa::class)->findAll();

        $puestas = 0;
        $yaEstab = 0;
        $sinMapa = [];

        foreach ($tarifas as $tarifa) {
            if ($tarifa->getComponente()?->getTipo()?->value !== 'tren') {
                continue;
            }

            $nombre = (string) $tarifa->getNombreInterno();

            if ($tarifa->getPrestadorServicio() !== null) {
                ++$yaEstab;
                continue;
            }

            $empresaTarifa = $tarifa->getPrestador()?->getNombreComercial();

            if ($empresaTarifa === null) {
                $sinMapa[$nombre] = 'sin prestador';
                continue;
            }

            $encontrado = null;

            foreach (self::MAPA as [$prefijo, $empresa, $clase]) {
                if (str_starts_with($nombre, $prefijo)) {
                    // La tarifa manda: si dice PeruRail, no se le cuelga una clase de IncaRail.
                    if ($empresa !== $empresaTarifa) {
                        $sinMapa[$nombre] = sprintf('el prefijo dice %s y la tarifa %s', $empresa, $empresaTarifa);
                        break;
                    }

                    $encontrado = $clases[$empresa . '|' . $clase] ?? null;

                    if ($encontrado === null) {
                        $sinMapa[$nombre] = sprintf('falta la clase «%s» de %s', $clase, $empresa);
                    }

                    break;
                }
            }

            if ($encontrado === null) {
                $sinMapa[$nombre] ??= 'sin prefijo reconocible';
                continue;
            }

            $io->text(sprintf('  %s · %-34s → %s', $simula ? 'pondría' : 'puesta ', mb_substr($nombre, 0, 34), $encontrado->getNombre()));
            ++$puestas;

            if (!$simula) {
                $tarifa->setPrestadorServicio($encontrado);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('%s: %d · ya tenían clase: %d', $simula ? 'Se pondrían' : 'Puestas', $puestas, $yaEstab));

        if ($sinMapa !== []) {
            $io->section('Sin enlazar (decisión comercial, no de un script)');

            foreach ($sinMapa as $nombre => $motivo) {
                $io->text(sprintf('  %-34s %s', mb_substr($nombre, 0, 34), $motivo));
            }
        }

        if ($simula) {
            $io->warning('SIMULACRO: no se escribió nada.');
        }

        return Command::SUCCESS;
    }
}
