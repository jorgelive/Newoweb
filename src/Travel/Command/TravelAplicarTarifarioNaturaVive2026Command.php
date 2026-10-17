<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cuadra Skylodge y Starlodge con los tarifarios 2026 de Natura Vive.
 *
 * Revisados contra los PDF que mandó Jorge el 17/10/2026 («TARIFARIO 2026 SKYLODGE ADVENTURE
 * SUITES» y «… STARLODGE …»). Lo cargado coincidía casi entero; esto arregla lo que no:
 *
 *  - **Tres importes** de la tarifa confidencial: Skylodge FULL por persona 1 375,50 → 1 375,00,
 *    Starlodge Zip Line por cápsula 5 335,50 → 5 337,50 y Starlodge FULL por cápsula
 *    5 635,50 → 5 635,00.
 *  - **Edad mínima de Skylodge: 15 años** (estaba en 12, que es la de Starlodge).
 *  - **Capacidad de la cápsula: 4 personas**, en las tarifas «por capsula».
 *  - **Prestador: Natura Vive**, en las tarifas de la cápsula y de sus actividades.
 *  - **Starlodge cotizaba sin precio**: su cápsula usaba el genérico «Alojamiento en
 *    Ollantaytambo», y sus tarifas están en «Alojamiento en Skylodge Starlodge».
 *  - **Tarifa predeterminada de la cápsula**: la FULL por persona, porque las dos plantillas son
 *    «con actividades». Sin ella, quien cotiza la elige a mano cada vez.
 *  - **Dos horas** del itinerario oficial: el retorno de Skylodge sale a las 11:30 (no a las 11:00)
 *    y las actividades de Starlodge empiezan a las 15:00 (las 14:30 son la charla).
 *
 * La comisión de las tarifas por cápsula se queda en la estándar (Jorge, 17/10/2026), aunque el
 * tarifario las llame «no comisionables». No se modela el +25 % del 24 y 31 de diciembre ni el
 * IGV del 10,5 % a peruanos y residentes: se añaden a mano en la cotización.
 *
 * Idempotente: cada cambio comprueba el valor de destino antes de escribir. Con `--dry-run`.
 * Ver docs/Travel.md «Plantillas de varios días».
 */
#[AsCommand(
    name: 'app:travel:aplicar-tarifario-natura-vive-2026',
    description: 'Cuadra las tarifas y plantillas de Skylodge y Starlodge con el tarifario 2026 de Natura Vive.',
    hidden: true,
)]
final class TravelAplicarTarifarioNaturaVive2026Command extends Command
{
    private const string ALOJAMIENTO = 'Alojamiento en Skylodge Starlodge';
    private const string ALOJAMIENTO_GENERICO = 'Alojamiento en Ollantaytambo';
    private const string PRESTADOR = 'Natura Vive';

    /** @var array<string, string> tarifa → monto del tarifario */
    private const array MONTOS = [
        'Skylodge FULL por persona' => '1375.00',
        'Starlodge (Zip LIne) por capsula' => '5337.50',
        'Starlodge FULL por capsula' => '5635.00',
    ];

    /** Las actividades de las dos cápsulas: son del mismo proveedor y van en el paquete. */
    private const array ACTIVIDADES = [
        'Experiencia Via Ferrata Skylodge',
        'Experiencia Zip Line Skylodge',
        'Ascenso sin actividades Starlodge',
        'Descenso sin actividades Starlodge',
    ];

    /** @var array<string, array{segmento: string, tarifa: string}> plantilla → su cápsula */
    private const array CAPSULAS = [
        'Skylodge con actividades' => ['segmento' => 'Alojamiento en Capsula Colgante del Skylodge', 'tarifa' => 'Skylodge FULL por persona'],
        'Starlodge con actividades' => ['segmento' => 'Alojamiento en Capsula Colgante del Starlodge', 'tarifa' => 'Starlodge FULL por persona'],
    ];

    /** @var list<array{plantilla: string, componente: string, dia: int, hora: string, horaFin: string}> */
    private const array HORAS = [
        ['plantilla' => 'Skylodge con actividades', 'componente' => 'Transporte Cusco ↔ Ollanta (ida o vuelta)', 'dia' => 2, 'hora' => '11:30', 'horaFin' => '13:00'],
        ['plantilla' => 'Starlodge con actividades', 'componente' => 'Experiencia Via Ferrata Skylodge', 'dia' => 1, 'hora' => '15:00', 'horaFin' => '17:00'],
    ];

    private int $cambios = 0;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'No escribe nada: sólo enseña lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        $alojamiento = $this->componente(self::ALOJAMIENTO);
        $generico = $this->componente(self::ALOJAMIENTO_GENERICO);
        $prestador = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::PRESTADOR]);

        if ($alojamiento === null || $generico === null || $prestador === null) {
            $io->error('Falta el alojamiento de las cápsulas, el genérico de Ollantaytambo o la organización Natura Vive.');

            return Command::FAILURE;
        }

        $io->section('Tarifas de la cápsula');
        foreach ($alojamiento->getTarifas() as $tarifa) {
            $nombre = (string) $tarifa->getNombreInterno();

            if (isset(self::MONTOS[$nombre]) && $tarifa->getMonto() !== self::MONTOS[$nombre]) {
                $this->anotar($io, sprintf('%s: %s → %s PEN', $nombre, $tarifa->getMonto(), self::MONTOS[$nombre]));
                $tarifa->setMonto(self::MONTOS[$nombre]);
            }

            $edad = str_starts_with($nombre, 'Skylodge') ? 15 : 12;
            if ($tarifa->getEdadMinima() !== $edad) {
                $this->anotar($io, sprintf('%s: edad mínima %s → %d', $nombre, $tarifa->getEdadMinima() ?? '—', $edad));
                $tarifa->setEdadMinima($edad);
            }

            if (str_ends_with($nombre, 'por capsula') && $tarifa->getCapacidadMaxima() !== 4) {
                $this->anotar($io, sprintf('%s: capacidad máxima → 4', $nombre));
                $tarifa->setCapacidadMaxima(4);
            }

            $this->ponerPrestador($io, $tarifa, $prestador);
        }

        $io->section('Tarifas de las actividades');
        foreach (self::ACTIVIDADES as $nombreActividad) {
            $actividad = $this->componente($nombreActividad);
            if ($actividad === null) {
                $io->warning('No encuentro la actividad «' . $nombreActividad . '».');
                continue;
            }
            foreach ($actividad->getTarifas() as $tarifa) {
                $this->ponerPrestador($io, $tarifa, $prestador);
            }
        }

        $io->section('La cápsula de cada plantilla');
        foreach (self::CAPSULAS as $nombrePlantilla => $capsula) {
            $plantilla = $this->em->getRepository(TravelItinerario::class)->findOneBy(['nombreInterno' => $nombrePlantilla]);
            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['nombreInterno' => $capsula['segmento']]);
            $tarifa = $this->tarifaDe($alojamiento, $capsula['tarifa']);
            $servicio = $plantilla?->getServicio();

            if ($plantilla === null || $segmento === null || $tarifa === null || $servicio === null) {
                $io->warning('Falta algo de «' . $nombrePlantilla . '»: plantilla, servicio, segmento o tarifa.');
                continue;
            }

            // El alojamiento correcto. El segmento es exclusivo de esta plantilla (comprobado el
            // 17/10/2026: cada cápsula la usa una sola), así que cambiar la fila global no le
            // cambia el alojamiento a nadie más.
            foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['segmento' => $segmento, 'componente' => $generico]) as $fila) {
                $this->anotar($io, sprintf('%s: «%s» → «%s»', $nombrePlantilla, self::ALOJAMIENTO_GENERICO, self::ALOJAMIENTO));
                $fila->setComponente($alojamiento);
            }

            if (!$servicio->getComponentes()->contains($alojamiento)) {
                $servicio->addComponente($alojamiento);
                $this->anotar($io, sprintf('%s: «%s» al pool del servicio', $nombrePlantilla, self::ALOJAMIENTO));
            }

            // La tarifa predeterminada va en una fila DE PLANTILLA, como el transporte: la global
            // dice que el componente existe en el segmento; cuál se cobra es de cada plantilla.
            $dePlantilla = $this->em->getRepository(TravelSegmentoComponente::class)->findOneBy([
                'segmento' => $segmento, 'componente' => $alojamiento, 'itinerarioContexto' => $plantilla,
            ]);

            if ($dePlantilla === null) {
                $this->em->persist((new TravelSegmentoComponente())
                    ->setSegmento($segmento)->setComponente($alojamiento)
                    ->setItinerarioContexto($plantilla)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setTarifaPredeterminada($tarifa)
                    ->setDia(1)->setOrden(1));
                $this->anotar($io, sprintf('%s: cápsula con «%s» predeterminada', $nombrePlantilla, $capsula['tarifa']));
            } elseif ($dePlantilla->getTarifaPredeterminada() !== $tarifa) {
                $dePlantilla->setTarifaPredeterminada($tarifa);
                $this->anotar($io, sprintf('%s: predeterminada → «%s»', $nombrePlantilla, $capsula['tarifa']));
            }
        }

        $io->section('Horas del itinerario oficial');
        foreach (self::HORAS as $cambio) {
            $plantilla = $this->em->getRepository(TravelItinerario::class)->findOneBy(['nombreInterno' => $cambio['plantilla']]);
            $componente = $this->componente($cambio['componente']);
            $fila = $plantilla === null || $componente === null ? null : $this->em->getRepository(TravelSegmentoComponente::class)->findOneBy([
                'itinerarioContexto' => $plantilla, 'componente' => $componente, 'dia' => $cambio['dia'],
            ]);

            if ($fila === null) {
                $io->warning(sprintf('No encuentro «%s» del día %d en «%s».', $cambio['componente'], $cambio['dia'], $cambio['plantilla']));
                continue;
            }

            if ($fila->getHora()?->format('H:i') !== $cambio['hora'] || $fila->getHoraFin()?->format('H:i') !== $cambio['horaFin']) {
                $this->anotar($io, sprintf('%s, día %d, %s: %s–%s → %s–%s', $cambio['plantilla'], $cambio['dia'], $cambio['componente'],
                    $fila->getHora()?->format('H:i') ?? '—', $fila->getHoraFin()?->format('H:i') ?? '—', $cambio['hora'], $cambio['horaFin']));
                $fila->setHora(new DateTimeImmutable($cambio['hora']));
                $fila->setHoraFin(new DateTimeImmutable($cambio['horaFin']));
            }
        }

        if ($this->cambios === 0) {
            $io->success('Todo coincide ya con el tarifario.');

            return Command::SUCCESS;
        }

        if ($seco) {
            $io->note(sprintf('--dry-run: %d cambios, no se ha escrito nada.', $this->cambios));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d cambios aplicados.', $this->cambios));

        return Command::SUCCESS;
    }

    private function componente(string $nombre): ?TravelComponente
    {
        return $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre]);
    }

    private function tarifaDe(TravelComponente $componente, string $nombre): ?TravelTarifa
    {
        foreach ($componente->getTarifas() as $tarifa) {
            if ($tarifa->getNombreInterno() === $nombre) {
                return $tarifa;
            }
        }

        return null;
    }

    private function ponerPrestador(SymfonyStyle $io, TravelTarifa $tarifa, TravelOrganizacion $prestador): void
    {
        if ($tarifa->getPrestador() === $prestador) {
            return;
        }

        $this->anotar($io, sprintf('%s · %s: prestador → %s', $tarifa->getComponente()?->getNombreInterno() ?? '?', $tarifa->getNombreInterno() ?? '?', self::PRESTADOR));
        $tarifa->setPrestador($prestador);
    }

    private function anotar(SymfonyStyle $io, string $que): void
    {
        $io->writeln('  ~ ' . $que);
        $this->cambios++;
    }
}
