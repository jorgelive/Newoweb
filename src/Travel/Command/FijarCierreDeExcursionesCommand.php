<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * El «servicio principal del día» que faltaba en el Valle VIP compartido, y las horas de fin que
 * no tenía ningún promovido de cuatrimotos ni de Titicaca.
 *
 * ── Lo que se vio en una propuesta ──────────────────────────────────────────
 *
 * En Q4V2FR el Valle VIP compartido enseñaba «07:00» y «Incluye: Guía, Transporte» en la tarjeta
 * del recojo, como si fueran de esa parada, en vez de «Horario de la excursión» en la cabecera del
 * servicio. La plantilla «Full Day Valle Vip» era la única de un día con excursión y **sin ningún
 * componente promovido**; sus tres hermanas del Valle lo tienen. Encima el pool llevaba la hora en
 * su relación GLOBAL, que según `docs/Travel.md` §11.quinquies va sin hora —la hora de salida es
 * de cada tour—, y era esa hora la que acababa en la tarjeta del recojo.
 *
 * ── Las dos pasadas ─────────────────────────────────────────────────────────
 *
 * 1. **Promover** el pool en su plantilla (07:00–18:30, confirmado por Jorge el 10/10/2026) con
 *    una relación de contexto copiada del global, y dejar el global sin hora.
 * 2. **Cerrar** los promovidos que tenían inicio y no fin. Sin fin, la cabecera dice «07:00» y no
 *    «07:00 – 15:00»: el cliente no sabe a qué hora vuelve, que es lo que decide si alcanza el bus.
 *
 * Van aparte porque se comprueban por claves distintas: la primera por componente, la segunda por
 * plantilla.
 *
 * ── Las horas, y de dónde salen ─────────────────────────────────────────────
 *
 * Sólo las que tienen fuente. Las demás se quedan sin fin y se dicen al terminar:
 *
 *   Valle VIP (las dos)        18:30   Jorge, 10/10/2026
 *   Cuatrimotos medio día      13:00   itinerario 2026 del proveedor: turnos 06:30–13:00 / 13:00–18:45
 *   Cuatrimotos y Zip Line     15:00   el mismo itinerario: combo 06:30–15:00
 *   Titicaca full days         15:00   tarifario de agencias 2026 de Qhapaq, «Horario: … – 15:00 hrs»
 *
 * Fuera, a propósito: el medio día de los Uros (el tarifario no da su fin), Checoq y el Full Day
 * de Maras privados (fuera del itinerario publicado), y los 2D de Titicaca: su promovido es del
 * día 1 y el 15:00 del tarifario es la vuelta del día 2.
 *
 * Idempotente: lo que ya está como se pide no se toca.
 *
 *   php bin/console app:travel:fijar-cierre-excursiones --dry-run
 */
#[AsCommand(
    name: 'app:travel:fijar-cierre-excursiones',
    description: 'Promueve el pool del Valle VIP y pone hora de fin a los promovidos que no la tienen.',
    hidden: true,
)]
final class FijarCierreDeExcursionesCommand extends Command
{
    /** Plantilla, segmento y componente del promovido que falta, con su horario. */
    private const array PROMOVER = [
        'plantilla' => '1D VALLE VIP POOL',
        'segmento' => 'SAL_EXC-VALLE_VIP-POOL',
        'componente' => 'Pool Super Valle',
        'hora' => '07:00',
        'horaFin' => '18:30',
    ];

    /** plantilla → hora de fin de su promovido. @var array<string, string> */
    private const array CIERRES = [
        '1D VALLE VIP PRIV' => '18:30',
        'HD-ATV_MARAS-MORAY-POOL' => '13:00',
        'HD-ATV_MARAS-MORAY-PRIV' => '13:00',
        'HD-ATV_MARAS-HUAYPO-POOL' => '13:00',
        'HD-ATV_MARAS-HUAYPO-PRIV' => '13:00',
        'FD-ATV_MARAS-ZIPLINE-POOL' => '15:00',
        'FD-TITICACA-TAQUILE-CLASICO' => '15:00',
        'FD-TITICACA-TAQUILE-FOLCLORICO' => '15:00',
        'FD-TITICACA-TAQUILE-VIP' => '15:00',
        'FD-TITICACA-AMANTANI' => '15:00',
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
        $repo = $this->em->getRepository(TravelSegmentoComponente::class);

        $io->title('Cierre de las excursiones');

        // ── 1. Promover el pool del Valle VIP ────────────────────────────────
        $io->section('1. Valle VIP compartido: servicio principal del día');
        $p = self::PROMOVER;

        $plantilla = $this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => $p['plantilla']]);
        $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $p['segmento']]);
        $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $p['componente']]);

        if ($plantilla === null || $segmento === null || $componente === null) {
            $io->error('Falta la plantilla, el segmento o el componente del Valle VIP compartido.');

            return Command::FAILURE;
        }

        $global = $repo->findOneBy(['segmento' => $segmento, 'componente' => $componente, 'itinerarioContexto' => null]);
        if ($global === null) {
            $io->error('El pool del Valle VIP no tiene relación global: no hay de dónde copiar la tarifa.');

            return Command::FAILURE;
        }

        $deContexto = $repo->findOneBy(['segmento' => $segmento, 'componente' => $componente, 'itinerarioContexto' => $plantilla]);
        $yaEsta = $deContexto !== null
            && $deContexto->isHoraServicioCompleto()
            && $deContexto->getHora()?->format('H:i') === $p['hora']
            && $deContexto->getHoraFin()?->format('H:i') === $p['horaFin'];

        if ($yaEsta) {
            $io->text(sprintf('  ya está  · %s promovido %s–%s', $p['componente'], $p['hora'], $p['horaFin']));
        } else {
            $io->text(sprintf('  %s · %s promovido en «%s» %s–%s%s',
                $simula ? 'haría ' : 'hecho ', $p['componente'], $plantilla->getNombreInterno(), $p['hora'], $p['horaFin'],
                $deContexto === null ? '  (crea la relación de plantilla)' : ''));

            if (!$simula) {
                if ($deContexto === null) {
                    $deContexto = (new TravelSegmentoComponente())
                        ->setSegmento($segmento)
                        ->setComponente($componente)
                        ->setItinerarioContexto($plantilla)
                        ->setTarifaPredeterminada($global->getTarifaPredeterminada())
                        ->setModo($global->getModo())
                        ->setOrden($global->getOrden());
                    $this->em->persist($deContexto);
                }
                $deContexto->setHora(new \DateTimeImmutable($p['hora']));
                $deContexto->setHoraFin(new \DateTimeImmutable($p['horaFin']));
                $deContexto->setHoraServicioCompleto(true);
            }
        }

        // El global, sin hora: con ella, quien use el segmento fuera de la plantilla vuelve a ver
        // la salida de ESTE tour pegada a la tarjeta del recojo.
        if ($global->getHora() !== null || $global->getHoraFin() !== null) {
            $io->text(sprintf('  %s · relación global sin hora (tenía %s)',
                $simula ? 'haría ' : 'hecho ', $global->getHora()?->format('H:i') ?? '—'));
            if (!$simula) {
                $global->setHora(null);
                $global->setHoraFin(null);
            }
        } else {
            $io->text('  ya está  · relación global sin hora');
        }

        // ── 2. Hora de fin de los promovidos ────────────────────────────────
        $io->section('2. Hora de fin de los promovidos');
        $cerrados = 0;

        foreach (self::CIERRES as $slug => $fin) {
            $itinerario = $this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => $slug]);
            if ($itinerario === null) {
                $io->error(sprintf('No existe la plantilla «%s».', $slug));

                return Command::FAILURE;
            }

            $promovidos = $repo->findBy(['itinerarioContexto' => $itinerario, 'horaServicioCompleto' => true]);
            if (count($promovidos) !== 1) {
                $io->warning(sprintf('«%s» tiene %d promovidos; se esperaba uno. No se toca.', $slug, count($promovidos)));
                continue;
            }

            $promovido = $promovidos[0];
            $actual = $promovido->getHoraFin()?->format('H:i');
            if ($actual === $fin) {
                $io->text(sprintf('  ya está  · %-34s %s', $slug, $fin));
                continue;
            }

            ++$cerrados;
            $io->text(sprintf('  %s · %-34s %s – %s', $simula ? 'haría ' : 'hecho ', $slug,
                $promovido->getHora()?->format('H:i') ?? '?', $fin));

            if (!$simula) {
                $promovido->setHoraFin(new \DateTimeImmutable($fin));
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf('%s %d cierre(s).', $simula ? 'Se fijarían' : 'Fijados', $cerrados));
        $io->note('Siguen sin hora de fin, por falta de fuente: Medio Día Uros, Checoq y Full Day Maras '
            . 'privados, y los tres 2D de Titicaca (su promovido es del día 1).');

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
