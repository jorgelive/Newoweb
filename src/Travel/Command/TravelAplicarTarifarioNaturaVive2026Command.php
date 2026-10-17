<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\TarifaModalidadEnum;
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
 *  - **Horas** del itinerario oficial: el retorno de Skylodge sale a las 11:30 (no a las 11:00), las
 *    actividades de Starlodge empiezan a las 15:00 (las 14:30 son la charla) y su retorno, a las 10:30.
 *  - **El servicio principal de cada día** (`DIAS`), y el descenso por rapel de Skylodge, que
 *    tenía día 1.
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
        // Check-out a las 10:30 y «10.30 am – 13:00 pm Transporte» en su itinerario.
        ['plantilla' => 'Starlodge con actividades', 'componente' => 'Transporte Cusco ↔ Ollanta (ida o vuelta)', 'dia' => 2, 'hora' => '10:30', 'horaFin' => '13:00'],
    ];

    /**
     * El servicio principal de cada día: un componente `pool` a 0 que sólo aporta inicio y fin,
     * como los segundos días del Titicaca (`app:travel:titicaca-segundo-dia`). Horas del itinerario
     * oficial de cada tarifario:
     *
     *   Skylodge   día 1  14:00 recojo en Cusco → 18:30 fin de la charla del lodge
     *              día 2  07:00 café en la suite → 13:00 llegada a Cusco
     *   Starlodge  día 1  12:00 recojo en Cusco → 19:00 fin del check-in (luego tinas y cena)
     *              día 2  06:30 desayuno → 13:00 llegada a Cusco o al Valle
     *
     * Cuelga del primer segmento del día y **sólo con la fila de plantilla**: «Traslado desde la
     * Ciudad de Cusco» lo comparten las dos, y un global metería el día de una en la otra.
     *
     * @var list<array{plantilla: string, dia: int, nombre: string, segmento: string, hora: string, horaFin: string}>
     */
    private const array DIAS = [
        ['plantilla' => 'Skylodge con actividades', 'dia' => 1, 'nombre' => 'Primer día Skylodge', 'segmento' => 'Traslado desde la Ciudad de Cusco', 'hora' => '14:00', 'horaFin' => '18:30'],
        ['plantilla' => 'Skylodge con actividades', 'dia' => 2, 'nombre' => 'Segundo día Skylodge', 'segmento' => 'Descenso por Zipline (Circuito de Tirolesas Completo)', 'hora' => '07:00', 'horaFin' => '13:00'],
        ['plantilla' => 'Starlodge con actividades', 'dia' => 1, 'nombre' => 'Primer día Starlodge', 'segmento' => 'Traslado desde la Ciudad de Cusco', 'hora' => '12:00', 'horaFin' => '19:00'],
        ['plantilla' => 'Starlodge con actividades', 'dia' => 2, 'nombre' => 'Segundo día Starlodge', 'segmento' => 'Descenso del StarLodge: Retorno al Valle', 'hora' => '06:30', 'horaFin' => '13:00'],
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

        $io->section('La variante de rapel de Skylodge');
        // La ruta Zipline (Skylodge 01) sube por tirolesa y BAJA por rapel y Vía Ferrata. Su
        // descenso tenía día 1, y un componente con día sólo entra si el segmento cae ese día: al
        // cambiar a esa variante, el descenso del día 2 se quedaba sin componente.
        $skylodge = $this->em->getRepository(TravelItinerario::class)->findOneBy(['nombreInterno' => 'Skylodge con actividades']);
        $descensoRapel = $this->componente('Descenso sin actividades Skylodge');
        if ($skylodge !== null && $descensoRapel !== null) {
            foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['itinerarioContexto' => $skylodge, 'componente' => $descensoRapel, 'dia' => 1]) as $fila) {
                $this->anotar($io, 'Descenso por rapel de Skylodge: día 1 → 2');
                $fila->setDia(2);
            }
        }

        $io->section('Servicio principal de cada día');
        foreach (self::DIAS as $dia) {
            $this->principalDelDia($io, $dia, $prestador);
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

    /**
     * @param array{plantilla: string, dia: int, nombre: string, segmento: string, hora: string, horaFin: string} $dia
     */
    private function principalDelDia(SymfonyStyle $io, array $dia, TravelOrganizacion $prestador): void
    {
        $plantilla = $this->em->getRepository(TravelItinerario::class)->findOneBy(['nombreInterno' => $dia['plantilla']]);
        $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['nombreInterno' => $dia['segmento']]);
        $servicio = $plantilla?->getServicio();

        if ($plantilla === null || $segmento === null || $servicio === null) {
            $io->warning(sprintf('Falta la plantilla, su servicio o el segmento «%s».', $dia['segmento']));

            return;
        }

        $componente = $this->componente($dia['nombre']);
        if ($componente === null) {
            // Sin título público: en un `pool` titula el segmento (docs/TravelCargaDeCatalogo.md).
            $componente = (new TravelComponente())
                ->setNombreInterno($dia['nombre'])
                ->setTipo(ComponenteTipoEnum::EXCURSION_POOL);
            $this->em->persist($componente);
            $this->anotar($io, sprintf('+ componente «%s» (pool)', $dia['nombre']));
        }

        if (!$servicio->getComponentes()->contains($componente)) {
            $servicio->addComponente($componente);
        }

        $tarifa = $componente->getTarifas()->first() ?: null;
        if ($tarifa === null) {
            // Lo que cobra Natura Vive ya está entero en la cápsula: ésta existe para que el
            // componente no entre «sin tarifa». Los setters de la tarifa no se encadenan.
            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            $tarifa->setNombreInterno($dia['nombre'] . ' · Incluido en el paquete');
            $tarifa->setTitulo([['language' => 'es', 'content' => 'Incluido']]);
            $tarifa->setMoneda($this->em->getReference(MaestroMoneda::class, 'PEN'));
            $tarifa->setMonto('0.00');
            $tarifa->setModalidad(TarifaModalidadEnum::COMPARTIDO);
            $tarifa->setPrestador($prestador);
            $tarifa->setComprador($prestador);
            $componente->addTarifa($tarifa);
            $this->em->persist($tarifa);
        }

        $fila = $this->em->getRepository(TravelSegmentoComponente::class)->findOneBy([
            'segmento' => $segmento, 'componente' => $componente, 'itinerarioContexto' => $plantilla,
        ]);

        if ($fila === null) {
            $this->em->persist((new TravelSegmentoComponente())
                ->setSegmento($segmento)->setComponente($componente)
                ->setItinerarioContexto($plantilla)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setTarifaPredeterminada($tarifa)
                ->setDia($dia['dia'])->setOrden(1)
                ->setHora(new DateTimeImmutable($dia['hora']))
                ->setHoraFin(new DateTimeImmutable($dia['horaFin']))
                ->setHoraServicioCompleto(true));
            $this->anotar($io, sprintf('%s, día %d: principal «%s» %s–%s', $dia['plantilla'], $dia['dia'], $dia['nombre'], $dia['hora'], $dia['horaFin']));
        }
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
