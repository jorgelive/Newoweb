<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItemDiccionario;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelSegmentoComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Las plantillas del Titicaca pasan a lo que dicen los ITINERARIOS OFICIALES de Qhapaq (16/10/2026).
 *
 * `app:travel:crear-titicaca` hizo mandar al tarifario de agencias en horas. Con los itinerarios
 * oficiales del Clásico, el Folclórico, el VIP, el medio día y el 2D con Taquile delante, Jorge:
 * «respeta el itinerario oficial». La jerarquía de fuentes se invierte para lo que esos documentos
 * cubren; el tarifario sigue mandando en precio y en lo que no tiene itinerario (Full Day Amantaní,
 * 2D Amantaní, Luquina).
 *
 * ```
 *                                   tarifario      itinerario oficial
 * Folclórico · recojo               08:00          07:00   (show en el puerto 07:15)
 * VIP · recojo                      08:00          07:00   (Uros 07:30)
 * 2D Amantaní-Taquile · recojo      08:00          07:45   (sale del puerto 08:00)
 * 2D Amantaní-Taquile · día 2 fin   15:00          16:00   (deja Taquile 13:00, 3 h de lancha)
 * Clásico · llegada a Puno          15:00          15:30
 * VIP · bajada a Taquile            triciclo       los 538 escalones   → fuera el triciclo
 * Clásico · embarcación             turística      lancha rápida
 * ```
 *
 * El recojo más temprano no es un matiz: con 08:00 en la propuesta y el bus en la puerta a las
 * 07:00, el pasajero se queda en tierra.
 *
 * El segmento «Bajada en triciclo» no se borra: sale de la plantilla VIP y su ítem del componente,
 * pero sigue en el pool por si Qhapaq lo ofrece de verdad en algún caso.
 *
 * Idempotente: lo que ya está como se pide no se toca.
 */
#[AsCommand(
    name: 'app:travel:titicaca-itinerario-oficial',
    description: 'Ajusta las plantillas del Titicaca a los itinerarios oficiales de Qhapaq.',
    hidden: true,
)]
final class TiticacaItinerarioOficialCommand extends Command
{
    /** Plantilla · día del promovido → [hora de inicio, hora de fin] (null = no se toca). */
    private const array HORAS = [
        ['FD-TITICACA-TAQUILE-FOLCLORICO', 1, '07:00', null],
        ['FD-TITICACA-TAQUILE-VIP', 1, '07:00', null],
        ['FD-TITICACA-TAQUILE-CLASICO', 1, null, '15:30'],
        ['2D-TITICACA-AMANTANI-TAQUILE', 1, '07:45', null],
        ['2D-TITICACA-AMANTANI-TAQUILE', 2, null, '16:00'],
    ];

    private const string VIP = 'FD-TITICACA-TAQUILE-VIP';
    private const string VIP_COMPONENTE = 'Pool Uros Taquile VIP';
    private const string TRICICLO_SEGMENTO = 'ACT-TITICACA-TRICICLO';
    private const string TRICICLO_ITEM = 'Bajada en triciclo';

    private const string CLASICO_COMPONENTE = 'Pool Uros Taquile con almuerzo';
    private const string LANCHA = 'Lancha';
    private const string LANCHA_RAPIDA = 'Lancha rápida';

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
        $seco = (bool) $input->getOption('dry-run');
        $verbo = $seco ? 'pasaría' : 'pasa';

        foreach (self::HORAS as [$slug, $dia, $inicio, $fin]) {
            $plantilla = $this->plantilla($slug);
            $pivote = $this->em->getRepository(TravelSegmentoComponente::class)
                ->findOneBy(['itinerarioContexto' => $plantilla, 'dia' => $dia, 'horaServicioCompleto' => true]);
            if (!$pivote instanceof TravelSegmentoComponente) {
                $io->error(sprintf('«%s» no tiene servicio principal el día %d.', $slug, $dia));

                return Command::FAILURE;
            }

            foreach (['inicio' => $inicio, 'fin' => $fin] as $cual => $hora) {
                if ($hora === null) {
                    continue;
                }
                $actual = ($cual === 'inicio' ? $pivote->getHora() : $pivote->getHoraFin())?->format('H:i');
                if ($actual === $hora) {
                    continue;
                }
                $io->text(sprintf('· %s día %d — %s %s de %s a %s', $slug, $dia, $cual, $verbo, $actual ?? '—', $hora));
                if (!$seco) {
                    $cual === 'inicio'
                        ? $pivote->setHora(new \DateTimeImmutable($hora))
                        : $pivote->setHoraFin(new \DateTimeImmutable($hora));
                }
            }
        }

        // VIP: la bajada es por los 538 escalones, no en triciclo.
        $vip = $this->plantilla(self::VIP);
        foreach ($vip->getItinerarioSegmentos() as $rel) {
            if ($rel->getSegmento()?->getSlug() === self::TRICICLO_SEGMENTO) {
                $io->text(sprintf('· %s — %s el paso «Bajada en triciclo»', self::VIP, $seco ? 'quitaría' : 'quitado'));
                if (!$seco) {
                    $vip->removeItinerarioSegmento($rel);
                }
            }
        }
        $vipComponente = $this->componente(self::VIP_COMPONENTE);
        foreach ($vipComponente->getComponenteItems() as $item) {
            if ($item->getDiccionario()?->getNombreInterno() === self::TRICICLO_ITEM) {
                $io->text(sprintf('· %s — %s el ítem «%s»', self::VIP_COMPONENTE, $seco ? 'quitaría' : 'quitado', self::TRICICLO_ITEM));
                if (!$seco) {
                    $vipComponente->removeComponenteItem($item);
                }
            }
        }

        // Clásico: lancha rápida.
        $rapida = null;
        foreach ($this->em->getRepository(TravelItemDiccionario::class)->findBy(['nombreInterno' => self::LANCHA_RAPIDA]) as $d) {
            // findBy no distingue tildes (docs/TravelCargaDeCatalogo.md): se compara en PHP.
            if ($d->getNombreInterno() === self::LANCHA_RAPIDA) {
                $rapida = $d;
            }
        }
        if (!$rapida instanceof TravelItemDiccionario) {
            $io->error(sprintf('No está «%s» en el diccionario.', self::LANCHA_RAPIDA));

            return Command::FAILURE;
        }
        foreach ($this->componente(self::CLASICO_COMPONENTE)->getComponenteItems() as $item) {
            if ($item->getDiccionario()?->getNombreInterno() === self::LANCHA) {
                $io->text(sprintf('· %s — «%s» %s a «%s»', self::CLASICO_COMPONENTE, self::LANCHA, $verbo, self::LANCHA_RAPIDA));
                if (!$seco) {
                    $item->setDiccionario($rapida);
                }
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }

    private function plantilla(string $slug): TravelItinerario
    {
        return $this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => $slug])
            ?? throw new \RuntimeException(sprintf('No existe la plantilla «%s».', $slug));
    }

    private function componente(string $nombre): TravelComponente
    {
        return $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre])
            ?? throw new \RuntimeException(sprintf('No existe el componente «%s».', $nombre));
    }
}
