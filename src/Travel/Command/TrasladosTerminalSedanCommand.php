<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
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
 * El sedán por defecto en los traslados a los terminales de bus (23/10/2026).
 *
 * Jorge: «tarifa sedán». En el catálogo el sedán se llama **Auto** (1 a 3 pasajeros), igual en
 * todos los traslados de Cusco y Puno. Ninguno de los seis segmentos de terminal —Terminal
 * Terrestre y Cruz del Sur, ida, vuelta y recepción en Puno— tenía tarifa por defecto.
 *
 * - **Puno** («Transporte Term Puno - Punto de Inicio») ya tenía su Auto: sólo se pone por defecto.
 * - **Cusco** («Transporte Htl Cusco ↔ Paradero Cusco») no tenía sedán: Van, Master, Sprinter y Bus.
 *   Se crea el Auto con `--monto-cusco` (en soles), y no antes: el precio lo decide Jorge.
 *
 * Idempotente: la tarifa por nombre dentro del componente, y los pivotes que ya apuntan a ella no
 * se tocan.
 */
#[AsCommand(
    name: 'app:travel:traslados-terminal-sedan',
    description: 'Pone el Auto (sedán) por defecto en los traslados a los terminales de Cusco y Puno.',
    hidden: true,
)]
final class TrasladosTerminalSedanCommand extends Command
{
    private const string AUTO = 'Auto';
    private const string CUSCO = 'Transporte Htl Cusco ↔ Paradero Cusco (ida o vuelta)';
    private const string PUNO = 'Transporte Term Puno - Punto de Inicio';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.')
            ->addOption('monto-cusco', null, InputOption::VALUE_REQUIRED, 'Precio en soles del Auto de Cusco, si hay que crearlo.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $montoCusco = $input->getOption('monto-cusco');
        $montoCusco = is_string($montoCusco) && is_numeric($montoCusco) ? number_format((float) $montoCusco, 2, '.', '') : null;

        foreach ([self::PUNO, self::CUSCO] as $nombre) {
            $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre]);
            if (!$componente instanceof TravelComponente) {
                $io->error(sprintf('No existe «%s».', $nombre));

                return Command::FAILURE;
            }

            $auto = null;
            foreach ($componente->getTarifas() as $tarifa) {
                if ($tarifa->getNombreInterno() === self::AUTO) {
                    $auto = $tarifa;
                }
            }

            if (!$auto instanceof TravelTarifa) {
                if ($nombre !== self::CUSCO || $montoCusco === null) {
                    $io->warning(sprintf('«%s» no tiene Auto. Para crearlo: --monto-cusco=<soles>.', $nombre));
                    continue;
                }

                $io->text(sprintf('· %s — Auto S/%s %s', $nombre, $montoCusco, $seco ? 'se crearía' : 'creado'));
                $moneda = $this->em->getRepository(MaestroMoneda::class)->find('PEN')
                    ?? throw new \RuntimeException('No existe la moneda PEN.');
                $auto = new TravelTarifa();
                $auto->setComponente($componente);
                $auto->setNombreInterno(self::AUTO);
                $auto->setTitulo([['language' => 'es', 'content' => self::AUTO]]);
                $auto->setMoneda($moneda);
                $auto->setMonto($montoCusco);
                $auto->setModalidad(TarifaModalidadEnum::PRIVADO);
                $auto->setCapacidadMinima(1);
                $auto->setCapacidadMaxima(3);
                if (!$seco) {
                    $this->em->persist($auto);
                }
            }

            foreach ($componente->getSegmentoComponentesInyectados() as $pivote) {
                if ($pivote->getTarifaPredeterminada() === $auto) {
                    continue;
                }
                $io->text(sprintf('· %s — Auto por defecto en %s', $nombre, $pivote->getSegmento()?->getSlug() ?? '?'));
                if (!$seco) {
                    $pivote->setTarifaPredeterminada($auto);
                }
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
