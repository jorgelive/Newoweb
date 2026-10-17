<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\TarifaCalculoEnum;
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
 * El circuito de aventura de Vertical Sky, que la plantilla vendía sin tener.
 *
 * ── Qué faltaba ─────────────────────────────────────────────────────────────
 * «Two Day Vertical Sky con actividades» tenía en el día 2 el segmento «Circuito Completo de
 * Aventura Vertical» —Vía Ferrata, puente colgante y tirolesa— **sin ningún componente**: la
 * actividad que se vende era sólo texto. No sumaba nada, no tenía hora y el día 2 se quedaba sin
 * servicio principal (`app:travel:proponer-puntos` lo listaba).
 *
 * ── Por qué a 0,00 ──────────────────────────────────────────────────────────
 * Es el patrón de Skylodge, decidido por Jorge el 17/10/2026: las actividades van como pool con
 * la tarifa «Incluido en el Paquete» a 0,00 USD, y **el precio vive en el alojamiento**, que
 * tiene una variante por combinación (Via Ferrata, Zip Line, FULL). El componente existe para que
 * la actividad figure, tenga hora y encabece el día 2.
 *
 * ── La hora ─────────────────────────────────────────────────────────────────
 * 08:00–11:30. No hay tarifario original; sale del texto del traslado de salida —«después del
 * desayuno, o al culminar las actividades de aventura por la mañana»— y de que ese traslado sale a
 * las 12:00. Se corrige en el panel cuando se confirme con el proveedor.
 *
 * Idempotente: busca por nombre interno y por la fila de la plantilla; lo que ya está no se toca.
 * Ver docs/TravelCargaDeCatalogo.md §4 y docs/Travel.md «Plantillas de varios días».
 */
#[AsCommand(
    name: 'app:travel:montar-circuito-vertical-sky',
    description: 'Crea el componente del circuito de aventura de Vertical Sky (a 0,00) y lo marca como principal del día 2.',
    hidden: true,
)]
final class TravelMontarCircuitoVerticalSkyCommand extends Command
{
    private const string PLANTILLA = 'Two Day Vertical Sky con actividades';
    private const string SEGMENTO = 'Circuito Completo de Aventura Vertical';
    private const string COMPONENTE = 'Experiencia Circuito Vertical Sky';
    private const string TARIFA = 'Incluido en el Paquete';
    private const int DIA = 2;
    private const string HORA = '08:00';
    private const string HORA_FIN = '11:30';

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

        $plantilla = $this->em->getRepository(TravelItinerario::class)->findOneBy(['nombreInterno' => self::PLANTILLA]);
        $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['nombreInterno' => self::SEGMENTO]);
        $servicio = $plantilla?->getServicio();

        if ($plantilla === null || $segmento === null || $servicio === null) {
            $io->error('No encuentro la plantilla, su servicio o el segmento del circuito.');

            return Command::FAILURE;
        }

        // 3 · el componente
        $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => self::COMPONENTE]);
        if ($componente === null) {
            $componente = (new TravelComponente())
                ->setNombreInterno(self::COMPONENTE)
                ->setTitulo([['language' => 'es', 'content' => 'Circuito de aventura: Vía Ferrata, puente colgante y tirolesa']])
                ->setTipo(ComponenteTipoEnum::EXCURSION_POOL)
                // Mismo aviso que las actividades de Skylodge: 4 días antes.
                ->setAnticipacionalerta(4);
            $this->em->persist($componente);
            $io->writeln('  + componente «' . self::COMPONENTE . '» (pool)');
        } else {
            $io->writeln('  = componente ya existe');
        }

        // 4 · la tarifa a 0,00: el precio va en el alojamiento
        $tarifa = null;
        foreach ($componente->getTarifas() as $existente) {
            if ($existente->getNombreInterno() === self::TARIFA) {
                $tarifa = $existente;
            }
        }
        if ($tarifa === null) {
            // Paso a paso: varios setters de TravelTarifa devuelven `void`.
            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            $tarifa->setNombreInterno(self::TARIFA);
            $tarifa->setMonto('0.00');
            $tarifa->setMoneda($this->em->getReference(MaestroMoneda::class, MaestroMoneda::DB_ID_USD));
            $tarifa->setModalidad(TarifaModalidadEnum::COMPARTIDO);
            $tarifa->setCalculo(TarifaCalculoEnum::INDIVIDUAL);
            $componente->addTarifa($tarifa);
            $this->em->persist($tarifa);
            $io->writeln('  + tarifa «' . self::TARIFA . '» 0,00 USD');
        } else {
            $io->writeln('  = tarifa ya existe');
        }

        // 5 · los dos pivotes: el global (sin día ni hora) y el de la plantilla, que encabeza el día
        $global = null;
        $dePlantilla = null;
        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['segmento' => $segmento, 'componente' => $componente]) as $fila) {
            if ($fila->getItinerarioContexto() === null) {
                $global = $fila;
            } elseif ($fila->getItinerarioContexto() === $plantilla) {
                $dePlantilla = $fila;
            }
        }

        if ($global === null) {
            $this->em->persist((new TravelSegmentoComponente())
                ->setSegmento($segmento)->setComponente($componente)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setTarifaPredeterminada($tarifa)
                ->setOrden(1));
            $io->writeln('  + pivote global (sin día ni hora)');
        } else {
            $io->writeln('  = pivote global ya existe');
        }

        if ($dePlantilla === null) {
            $this->em->persist((new TravelSegmentoComponente())
                ->setSegmento($segmento)->setComponente($componente)
                ->setItinerarioContexto($plantilla)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setTarifaPredeterminada($tarifa)
                ->setDia(self::DIA)->setOrden(1)
                ->setHora(new DateTimeImmutable(self::HORA))
                ->setHoraFin(new DateTimeImmutable(self::HORA_FIN))
                ->setHoraServicioCompleto(true));
            $io->writeln(sprintf('  + pivote de la plantilla: día %d, %s–%s, servicio principal', self::DIA, self::HORA, self::HORA_FIN));
        } else {
            $io->writeln('  = pivote de la plantilla ya existe');
        }

        // 6 · los dos pools del servicio
        if (!$servicio->getComponentes()->contains($componente)) {
            $servicio->addComponente($componente);
            $io->writeln('  + componente al pool del servicio');
        }
        if (!$servicio->getSegmentos()->contains($segmento)) {
            $servicio->addSegmento($segmento);
            $io->writeln('  + segmento al pool del servicio');
        }

        if ($seco) {
            $io->note('--dry-run: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Circuito de Vertical Sky montado.');

        return Command::SUCCESS;
    }
}
