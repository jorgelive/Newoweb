<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoImagen;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Las fotos de las paradas de la Ruta del Sol, en MARCA BLANCA (23/10/2026).
 *
 * Sacadas de las webs de los operadores (Avalos Tours y Turismo Mer; las de Inka Express miden
 * 393×300 y no sirven), por petición de Jorge: «busca fotos y ponlas en los segmentos que sean
 * marca blanca». Se miraron una a una, también las esquinas a tamaño real, y se descartaron:
 *
 * - el bus y sus asientos: van rotulados con la empresa;
 * - el mapa de la ruta de Mer: lleva su texto;
 * - la fachada de Andahuaylillas de Mer: marca de agua de otra web en la esquina;
 * - el restaurante de Sicuani: se lee el letrero «La Pascana», que es de Turismo Mer.
 *
 * Por eso el almuerzo, las salidas y las llegadas se quedan sin foto. La primera de cada segmento
 * es la portada. Idempotente: un segmento que ya tiene fotos no se toca.
 *
 *   php bin/console app:travel:fotos-ruta-del-sol --fotos=/tmp/rsol_fotos --dry-run
 */
#[AsCommand(
    name: 'app:travel:fotos-ruta-del-sol',
    description: 'Sube las fotos (sin marca) de las paradas de la Ruta del Sol.',
    hidden: true,
)]
final class FotosRutaDelSolCommand extends Command
{
    /** Segmento → archivos, en orden; el primero es la portada. */
    private const array FOTOS = [
        'VIS-RSOL-ANDAHUAYLILLAS' => ['av_and_int1.jpg', 'av_and_int2.jpg'],
        'VIS-RSOL-RAQCHI' => ['av_raq.jpg', 'av_raq_colcas.jpg', 'av_raq_guia.jpg'],
        'VIS-RSOL-LA_RAYA' => ['av_ray.jpg', 'mer_tour03.webp'],
        'VIS-RSOL-PUKARA' => ['av_puk.jpg', 'mer_tour02.webp'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fotos', null, InputOption::VALUE_REQUIRED, 'Carpeta con las fotos descargadas.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $carpeta = $input->getOption('fotos');

        if (!\is_string($carpeta) || !is_dir($carpeta)) {
            $io->error('Indica la carpeta de las fotos con --fotos.');

            return Command::FAILURE;
        }

        foreach (self::FOTOS as $slug => $archivos) {
            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug])
                ?? throw new \RuntimeException('Falta el segmento ' . $slug);

            if (!$segmento->getImagenes()->isEmpty()) {
                $io->text(sprintf('· %s — ya tiene fotos', $slug));
                continue;
            }

            $io->text(sprintf('· %s — %s %s', $slug, $seco ? 'subiría' : 'sube', implode(', ', $archivos)));
            if ($seco) {
                continue;
            }

            foreach ($archivos as $i => $archivo) {
                $ruta = rtrim($carpeta, '/') . '/' . $archivo;
                if (!is_file($ruta)) {
                    throw new \RuntimeException('Falta la foto ' . $ruta);
                }

                $imagen = (new TravelSegmentoImagen())
                    ->setSegmento($segmento)
                    ->setOrden($i)
                    ->setIsPortada($i === 0);
                // `test: true`: no llega por HTTP, y sin esto `move()` se niega a moverlo.
                $imagen->setImageFile(new UploadedFile($ruta, $archivo, null, null, true));
                $segmento->addImagen($imagen);
                $this->em->persist($imagen);
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
