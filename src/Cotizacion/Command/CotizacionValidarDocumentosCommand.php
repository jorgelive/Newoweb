<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Documento\ValidadorDeManifiesto;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\ValidacionIdentificacionEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Valida el manifiesto de un expediente contra los escaneos de la bóveda.
 *
 * ⚠️ **Esto SÍ escribe**, y es lo que se quiere: escribe **el veredicto** —estado, discrepancias y
 * notas— sobre cada número del manifiesto. Lo que no toca nunca son los datos del pasajero: si el
 * documento dice `2036` y el manifiesto `2026`, deja escrito que no coinciden y **no corrige
 * nada**. Corregir es una decisión de una persona mirando los dos valores.
 *
 * Es **incremental**: lo ya validado se salta, así que se puede volver a lanzar sin pensarlo. Y lo
 * caro —la lectura del documento— se cachea en el archivo, así que ni siquiera `--forzar` vuelve a
 * pagar la IA.
 */
#[AsCommand(
    name: 'app:cotizacion:validar-documentos',
    description: 'Valida el manifiesto contra los escaneos: qué número no coincide con su documento.',
)]
final class CotizacionValidarDocumentosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidadorDeManifiesto $validador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('expediente', InputArgument::REQUIRED, 'UUID del CotizacionFile')
            ->addOption('forzar', null, InputOption::VALUE_NONE, 'Revisa también lo ya validado (cambió el criterio)')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Cuántos documentos leer como mucho');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('expediente');

        if (!Uuid::isValid($id)) {
            $io->error('Eso no es un UUID.');

            return Command::INVALID;
        }

        $expediente = $this->em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));
        if ($expediente === null) {
            $io->error('No existe ese expediente.');

            return Command::FAILURE;
        }

        $limite = $input->getOption('limite');
        $conteo = $this->validador->validar(
            $expediente,
            (bool) $input->getOption('forzar'),
            $limite !== null ? max(1, (int) $limite) : null,
        );

        $io->table(
            ['estado', 'documentos'],
            [
                [ValidacionIdentificacionEnum::VALIDADO_MRZ->getLabel(), $conteo[ValidacionIdentificacionEnum::VALIDADO_MRZ->value]],
                [ValidacionIdentificacionEnum::VALIDADO_OCR->getLabel(), $conteo[ValidacionIdentificacionEnum::VALIDADO_OCR->value]],
                [ValidacionIdentificacionEnum::OBSERVADO->getLabel(), $conteo[ValidacionIdentificacionEnum::OBSERVADO->value]],
                [ValidacionIdentificacionEnum::NO_VALIDADO->getLabel(), $conteo[ValidacionIdentificacionEnum::NO_VALIDADO->value]],
                ['└ de ésos, sin escaneo en la bóveda', $conteo['sin_documento']],
            ],
        );

        $io->success(sprintf(
            '%d observados esperan a que alguien decida. El manifiesto NO se ha corregido: eso se hace mirando.',
            $conteo[ValidacionIdentificacionEnum::OBSERVADO->value],
        ));

        return Command::SUCCESS;
    }
}
