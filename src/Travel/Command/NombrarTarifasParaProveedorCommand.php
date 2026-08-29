<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Llena `nombreParaPrestador` en las tarifas de transporte bidireccional.
 *
 * Es el **peldaño 1** de `BibliaSnapshotService::resolverDescripcion()`, y estaba vacío en 859 de
 * 863 tarifas del catálogo. Sin él, la orden cae al peldaño 3 —el nombre interno de la tarifa— y
 * al proveedor le llega «Auto a Miraflores Noche», que es jerga nuestra: una etiqueta pensada
 * para elegir en un desplegable, no para que alguien reconozca lo que se le encarga.
 *
 * ## Por qué bidireccional también para él
 *
 * Su tarifa lo es: cobra lo mismo por llevar al aeropuerto que por traer de él, y el sentido
 * concreto se lo dice el **segmento**, que va en grande y encima. Repetirle la dirección aquí
 * sería decirla dos veces; omitir la ruta entera sería dejarle sólo «Auto día».
 *
 * ```
 * *Transporte desde el Aeropuerto de Lima al hotel en Lima*   ← el segmento: hacia dónde HOY
 * Traslado Aeropuerto Lima ↔ Miraflores · Auto Noche          ← lo contratado, como él lo tarifa
 * ```
 *
 * ⚠️ **Sólo los bidireccionales.** Un componente que aún nombra un sentido —«Transporte Ollanta -
 * Cusco»— no se toca: ahí la ruta con dirección todavía es correcta y meterle un «↔» sería
 * afirmar algo que su tarifa no dice.
 */
#[AsCommand(
    name: 'app:travel:nombrar-tarifas-para-proveedor',
    description: 'Rellena el nombre con el que el proveedor reconoce cada tarifa de transporte.',
)]
final class NombrarTarifasParaProveedorCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sobrescribir', null, InputOption::VALUE_NONE, 'Pisa los que ya tienen nombre.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué escribiría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');
        $pisa = (bool) $input->getOption('sobrescribir');

        $puestos = 0;
        $respetados = 0;

        foreach ([ComponenteTipoEnum::TRANSPORTE, ComponenteTipoEnum::TREN, ComponenteTipoEnum::VUELO] as $tipo) {
            foreach ($this->em->getRepository(TravelComponente::class)->findBy(['tipo' => $tipo]) as $componente) {
                $nombre = (string) $componente->getNombreInterno();

                if (!str_contains($nombre, '↔')) {
                    continue;
                }

                $ruta = $this->ruta($nombre);
                $cabecera = false;

                foreach ($componente->getTarifas() as $tarifa) {
                    $actual = trim((string) $tarifa->getNombreParaPrestador());

                    if ($actual !== '' && !$pisa) {
                        ++$respetados;
                        continue;
                    }

                    $texto = sprintf('%s · %s', $ruta, trim((string) $tarifa->getNombreInterno()));

                    if ($texto === $actual) {
                        continue;
                    }

                    if (!$cabecera) {
                        $io->section($nombre);
                        $cabecera = true;
                    }

                    ++$puestos;
                    $io->text(sprintf('  %s', $texto));

                    if (!$simula) {
                        $tarifa->setNombreParaPrestador($texto);
                    }
                }
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('  nombres puestos: %d · respetados por tener uno: %d', $puestos, $respetados));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /** «Transporte Aeropuerto Lima ↔ Miraflores (ida o vuelta)» → «Traslado Aeropuerto Lima ↔ Miraflores». */
    private function ruta(string $nombreComponente): string
    {
        $sin = preg_replace('/\s*\(ida o vuelta\)\s*$/u', '', $nombreComponente) ?? $nombreComponente;
        $sin = preg_replace('/^Transporte\s+/u', 'Traslado ', $sin) ?? $sin;

        return trim($sin);
    }
}
