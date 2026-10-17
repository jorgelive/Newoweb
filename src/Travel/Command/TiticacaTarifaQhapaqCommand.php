<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Las dos plantillas de Titicaca que no cobraban la tarifa de Qhapaq (16/10/2026).
 *
 * `app:travel:crear-titicaca` reutilizó dos componentes que ya existían (`crear => false`) con
 * SUS tarifas, y entre ellas quedó una vieja de «Titicaca Tours», sin prestador ni comprador:
 *
 * ```
 * Full Day Uros y Taquile (Clásico)   sin tarifa por defecto       Qhapaq S/80 · TT USD 30
 * 2D/1N Uros, Amantaní y Taquile      por defecto la de TT, S/200  Qhapaq S/160
 * ```
 *
 * Sin tarifa por defecto la plantilla puede tomar cualquiera; con la de TT, la cotización salía
 * S/40 más cara por persona y con un proveedor que no es el del tarifario 2026. Jorge: «ponle la
 * tarifa por defecto Qhapaq y elimina Titicaca Tours».
 *
 * Se borran, no se marcan, porque lo pidió él y porque nada las usa: ninguna `cotizacion_cottarifa`
 * las referencia (lo comprueba el comando antes de tocar nada y, si alguna lo hace, para). Los
 * pivotes —el global y el de la plantilla— pasan antes a la de Qhapaq.
 */
#[AsCommand(
    name: 'app:travel:titicaca-tarifa-qhapaq',
    description: 'Pone la tarifa de Qhapaq por defecto en los dos Titicaca que no la tenían y borra las de Titicaca Tours.',
    hidden: true,
)]
final class TiticacaTarifaQhapaqCommand extends Command
{
    private const string PROVEEDOR_VIEJO = 'Titicaca Tours';

    /** Componente → nombre de su tarifa de Qhapaq. */
    private const array COMPONENTES = [
        'Pool Uros Taquile con almuerzo' => 'Pool Uros Taquile con almuerzo · Compartido',
        'Pool Uros Amantani Taquile con almuerzo' => 'Pool Uros Amantani Taquile con almuerzo · Compartido',
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
        $seco = (bool) $input->getOption('dry-run');

        foreach (self::COMPONENTES as $nombre => $nombreTarifa) {
            $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre]);
            if (!$componente instanceof TravelComponente) {
                $io->error(sprintf('No encuentro el componente «%s».', $nombre));

                return Command::FAILURE;
            }

            $qhapaq = null;
            $viejas = [];
            foreach ($componente->getTarifas() as $tarifa) {
                if ($tarifa->getNombreInterno() === $nombreTarifa) {
                    $qhapaq = $tarifa;
                } elseif ($tarifa->getNombreInterno() === self::PROVEEDOR_VIEJO) {
                    $viejas[] = $tarifa;
                }
            }

            if (!$qhapaq instanceof TravelTarifa || $qhapaq->getPrestadorNombre() !== 'Qhapaq Adventures') {
                $io->error(sprintf('«%s» no tiene la tarifa de Qhapaq «%s».', $nombre, $nombreTarifa));

                return Command::FAILURE;
            }

            foreach ($viejas as $vieja) {
                $usos = $this->em->getConnection()->fetchOne(
                    'SELECT COUNT(*) FROM cotizacion_cottarifa WHERE tarifa_maestra_id = ?',
                    [(string) $vieja->getId()],
                );
                $usos = is_numeric($usos) ? (int) $usos : 0;
                if ($usos > 0) {
                    $io->error(sprintf('La tarifa «%s» de «%s» la usan %d cotizaciones: no se borra.', self::PROVEEDOR_VIEJO, $nombre, $usos));

                    return Command::FAILURE;
                }
            }

            $repuntados = 0;
            foreach ($componente->getSegmentoComponentesInyectados() as $pivote) {
                if ($pivote->getTarifaPredeterminada() !== $qhapaq) {
                    ++$repuntados;
                    if (!$seco) {
                        $pivote->setTarifaPredeterminada($qhapaq);
                    }
                }
            }

            if (!$seco) {
                foreach ($viejas as $vieja) {
                    $componente->removeTarifa($vieja);
                }
            }

            $io->text(sprintf(
                '· %s — %d pivote(s) a la de Qhapaq (S/%s), %d tarifa(s) de %s %s',
                $nombre,
                $repuntados,
                $qhapaq->getMonto(),
                count($viejas),
                self::PROVEEDOR_VIEJO,
                $seco ? 'se borrarían' : 'borradas',
            ));
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
