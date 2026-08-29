<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Vuelos\VuelosImportador;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Carga los vuelos de un expediente desde un archivo de localizadores.
 *
 * Aquí no vive ninguna regla: todo está en {@see VuelosImportador}, porque **lo mismo se hace
 * desde el expediente** —pegando el JSON en el cuadro de texto, junto al cargador de Excel— y
 * dos copias de esta lógica se separarían a la primera.
 *
 * El expediente es un argumento y no va dentro del archivo: desde la pantalla lo pone el
 * contexto, y así «localizador» significa una sola cosa, el PNR de la aerolínea.
 *
 * ⚠️ **Ensaya por defecto.** Lo dispara un correo de la aerolínea, y los correos se leen con
 * prisa. Con `--aplicar` escribe.
 */
#[AsCommand(
    name: 'app:cotizacion:cargar-vuelos',
    description: 'Carga o actualiza los vuelos de un expediente desde un JSON de localizadores.',
)]
final class CotizacionCargarVuelosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VuelosImportador $importador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('expediente', InputArgument::REQUIRED, 'Localizador del expediente (ej. 5SRAJV).');
        $this->addArgument('archivo', InputArgument::REQUIRED, 'Ruta del JSON de localizadores.');
        $this->addOption('aplicar', null, InputOption::VALUE_NONE, 'Escribe. Sin esto sólo enseña el diff.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $aplicar = (bool) $input->getOption('aplicar');
        $ruta = (string) $input->getArgument('archivo');

        if (!is_file($ruta)) {
            $io->error(sprintf('No existe el archivo %s', $ruta));

            return Command::FAILURE;
        }

        $file = $this->em->getRepository(CotizacionFile::class)
            ->findOneBy(['localizador' => (string) $input->getArgument('expediente')]);

        if ($file === null) {
            $io->error(sprintf('No existe el expediente %s', (string) $input->getArgument('expediente')));

            return Command::FAILURE;
        }

        try {
            $reservas = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error('El archivo no es JSON válido: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if (!is_array($reservas) || !array_is_list($reservas)) {
            $io->error('Se espera una LISTA de localizadores, cada uno con sus «vuelos».');

            return Command::FAILURE;
        }

        /** @var list<array<string, mixed>> $reservas */
        $io->title(sprintf('Vuelos de %s · %d localizador(es)', (string) $file->getLocalizador(), count($reservas)));

        $r = $this->importador->importar($file, $reservas, $aplicar);

        foreach ($r->cambios as $linea) {
            $io->writeln('  ' . $linea);
        }

        if ($r->avisos !== []) {
            $io->newLine();
            foreach ($r->avisos as $linea) {
                $io->writeln('  <comment>⚠</comment> ' . $linea);
            }
        }

        if ($r->problemas !== []) {
            $io->newLine();
            $io->warning(implode("\n", $r->problemas));
        }

        $io->newLine();

        if (!$r->hayAlgoQueHacer()) {
            $io->success('Nada que cambiar: el archivo coincide con lo que ya había.');

            return $r->problemas === [] ? Command::SUCCESS : Command::FAILURE;
        }

        if (!$aplicar) {
            $io->note('Ensayo: no se escribió nada. Repite con --aplicar.');

            return Command::SUCCESS;
        }

        $io->success('Aplicado.');

        return Command::SUCCESS;
    }
}
