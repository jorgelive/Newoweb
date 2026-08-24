<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Padron\PadronImportador;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Carga un padrón desde la consola.
 *
 * Existe además del endpoint porque es la única forma de probar un archivo real contra datos
 * reales sin montar el navegador — y porque el día que una carga salga mal, se repite aquí con
 * `--dry-run` y se lee el informe entero sin límite de pantalla.
 */
#[AsCommand(
    name: 'app:cotizacion:importar-padron',
    description: 'Carga un padrón .xlsx en un expediente',
)]
final class CotizacionImportarPadronCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PadronImportador $importador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('expediente', InputArgument::REQUIRED, 'UUID o nombre del grupo')
            ->addArgument('archivo', InputArgument::REQUIRED, 'Ruta del .xlsx')
            ->addOption('aplicar', null, InputOption::VALUE_NONE, 'Guarda de verdad (por defecto sólo ensaya)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repo = $this->em->getRepository(CotizacionFile::class);
        $referencia = (string) $input->getArgument('expediente');

        $file = preg_match('/^[0-9a-f-]{36}$/i', $referencia)
            ? $repo->find($referencia)
            : $repo->findOneBy(['nombreGrupo' => $referencia]);

        if ($file === null) {
            $io->error(sprintf('No encontré el expediente «%s».', $referencia));

            return Command::FAILURE;
        }

        $archivo = (string) $input->getArgument('archivo');
        if (!is_file($archivo)) {
            $io->error(sprintf('No existe el archivo «%s».', $archivo));

            return Command::FAILURE;
        }

        $seco = !$input->getOption('aplicar');
        $r = $this->importador->importar($file, $archivo, $seco);

        $io->section(sprintf('%s — %s', $file->getNombreGrupo(), $seco ? 'ENSAYO' : 'APLICADO'));
        $io->table(['qué', 'cuántos'], [
            ['filas leídas', $r->filasLeidas],
            ['pasajeros creados', $r->pasajerosCreados],
            ['pasajeros actualizados', $r->pasajerosActualizados],
            ['identificaciones creadas', $r->identificacionesCreadas],
            ['grupos creados', $r->gruposCreados],
            ['pertenencias creadas', $r->pertenenciasCreadas],
            ['pertenencias quitadas', $r->pertenenciasQuitadas],
        ]);

        foreach ($r->avisos as $aviso) {
            $io->warning($aviso);
        }

        if ($r->noEstanEnElArchivo !== []) {
            $io->warning(sprintf(
                '%d personas están en el sistema y NO en el archivo. NO se han borrado: %s',
                count($r->noEstanEnElArchivo),
                implode(', ', array_slice($r->noEstanEnElArchivo, 0, 8)).(count($r->noEstanEnElArchivo) > 8 ? '…' : ''),
            ));
        }

        if ($r->tieneErrores()) {
            $io->error(sprintf('%d filas con problemas. NO se guardó nada:', count($r->errores)));
            $io->listing(array_slice($r->errores, 0, 20));

            return Command::FAILURE;
        }

        $io->success($seco ? 'Ensayo limpio. Repite con --aplicar para guardar.' : 'Padrón cargado.');

        return Command::SUCCESS;
    }
}
