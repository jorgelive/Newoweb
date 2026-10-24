<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Pms\Entity\PmsGuiaItem;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionServicio;
use App\Travel\Entity\TravelOrganizacionServicioImagen;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Las 7 casitas de Centro Cusco Inti como «habitaciones» de su organización en el catálogo de
 * viajes (23/10/2026).
 *
 * Jorge: «crea las 7 casitas dentro del prestador de Centro Cusco Inti como si fueran habitaciones
 * de hotel, usa algunas de las fotos de mi guía». Así, al cotizar el alojamiento en Cusco se elige
 * la casita igual que la habitación de un hotel, y el cliente ve su ficha con fotos.
 *
 * - **Los datos salen del PMS** (`PmsUnidad`: capacidad, dormitorios, camas, baños); la redacción es
 *   propia, sin gradas ni accesos —eso lo cuenta sólo el agente (ver la memoria de accesos)—.
 * - **Las fotos salen del álbum de cada casita en la guía** (`album-casa-N`), elegidas por su pie:
 *   la sala o el comedor de portada, y luego dormitorios, cocina y baño. Se miraron una a una: las
 *   que enseñan sobre todo la escalera (estar de la 6, comedor de la 7) se quedaron fuera. Se COPIAN del
 *   archivo de la guía: subir el original lo movería y la guía se quedaría sin foto.
 *
 * Idempotente: no repite casitas, y una casita que ya tiene fotos no recibe más.
 */
#[AsCommand(
    name: 'app:travel:casitas-centro-cusco-inti',
    description: 'Crea las 7 casitas de Centro Cusco Inti como habitaciones de su organización, con fotos de la guía.',
    hidden: true,
)]
final class CasitasCentroCuscoIntiCommand extends Command
{
    private const string ORGANIZACION = 'Centro Cusco Inti - Cusco';

    /**
     * Casita → descripción y fotos del álbum de la guía (por nombre de archivo), en orden.
     *
     * @var array<int, array{descripcion: string, fotos: list<string>}>
     */
    private const array CASITAS = [
        1 => [
            'descripcion' => 'Casita para hasta 8 personas, con dos dormitorios —uno con dos camas individuales y una '
                . 'doble, y otro con dos dobles—, dos baños, sala y cocina.',
            'fotos' => ['2026-02-14_20-30-30_dd425dde730a4ca9_2527.webp', '2026-02-14_20-30-53_60a59b0de5faaf6f_f984.webp',
                '2026-02-14_20-30-35_ae7c83bb792ff719_84a6.webp', '2026-02-14_20-30-40_56845e9e68882e69_8daf.webp',
                '2026-02-14_20-30-46_01bb14b5f34271ac_74c9.webp'],
        ],
        2 => [
            'descripcion' => 'Casita para hasta 7 personas, con dos dormitorios —uno con dos camas dobles y una '
                . 'individual, y otro con una doble—, un baño, sala y cocina.',
            'fotos' => ['2026-03-03_18-20-05_1c5be6bc6a1ae3c8_5a5d.webp', '2026-03-03_18-19-57_fe04fd763cd9b1dc_ee94.webp',
                '2026-03-03_18-19-55_a215d5fb1d9d7a4f_6165.webp', '2026-03-03_18-19-52_c1e702643b0f9bf0_eea6.webp',
                '2026-03-03_18-19-50_cfbf2f1b006a500d_5b62.webp'],
        ],
        3 => [
            'descripcion' => 'Casita para hasta 8 personas, con dos dormitorios de dos camas dobles cada uno, un baño, '
                . 'cocina y comedor.',
            'fotos' => ['2026-03-03_21-06-16_f389e21e9d9f5478_e1b7.webp', '2026-03-03_21-06-21_9b1a8165ba504d1c_e4c5.webp',
                '2026-03-03_21-06-19_902d4048fdda1805_af62.webp', '2026-03-03_21-06-14_279df583614e9937_7409.webp'],
        ],
        4 => [
            'descripcion' => 'Casita para hasta 4 personas, con un dormitorio con una cama doble y dos individuales, '
                . 'un baño y cocina.',
            'fotos' => ['2026-03-02_16-19-54_c7a2b81a72a34f8b_6529.webp', '2026-03-02_16-19-56_287d851e1a8be623_9b0e.webp',
                '2026-03-02_16-19-50_204dfcab181066d3_e446.webp', '2026-03-02_16-19-46_72a3d9d8fede16d9_07a2.webp'],
        ],
        5 => [
            'descripcion' => 'Casita para dos personas, con un dormitorio con cama doble, un baño, cocina y comedor.',
            'fotos' => ['2026-03-03_16-27-45_be7601696b0047ac_392f.webp', '2026-03-03_16-27-40_df4023c61bce5c1c_b396.webp',
                '2026-03-03_16-27-42_39c376cb23ecd1ef_f40e.webp', '2026-03-03_16-27-36_12842155e4e63f46_f5c5.webp'],
        ],
        6 => [
            'descripcion' => 'Casita para hasta 10 personas, con tres dormitorios de dos camas dobles cada uno, dos baños, '
                . 'sala de estar, comedor y cocina.',
            // Sin el «Estar»: es el rellano de la escalera, no sirve de portada.
            'fotos' => ['2026-03-03_21-51-13_58c2ece58f04ff5d_554d.webp',
                '2026-03-03_21-51-25_d35807692ea73c20_7e1c.webp', '2026-03-03_21-51-15_65b29121a823514e_12c5.webp',
                '2026-03-03_21-51-04_16353e0f84447640_186a.webp', '2026-03-03_21-50-59_834cfe2c24ff20b0_bf7b.webp'],
        ],
        7 => [
            'descripcion' => 'Casita para hasta 10 personas, con dos dormitorios —uno con tres camas dobles y otro con '
                . 'dos—, dos baños, comedor y cocina.',
            // Sin el «Comedor 1»: lo domina la escalera. Abre el dormitorio de la ventana.
            'fotos' => ['2026-03-03_22-20-56_47a44bc16ce3593b_5db1.webp',
                '2026-03-03_22-20-59_bffce7e5259f989a_0939.webp', '2026-03-03_22-20-49_7d5140dac7e8c10e_021a.webp',
                '2026-03-03_22-20-41_09276ddc6167262b_d87e.webp'],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StorageInterface $almacen,
    ) {
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

        $org = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::ORGANIZACION])
            ?? throw new \RuntimeException('Falta la organización ' . self::ORGANIZACION);

        $yaEstan = [];
        foreach ($this->em->getRepository(TravelOrganizacionServicio::class)->findBy(['organizacion' => $org]) as $s) {
            $yaEstan[(string) $s->getNombre()] = $s;
        }

        foreach (self::CASITAS as $numero => $def) {
            $nombre = 'Casita ' . $numero;
            $casita = $yaEstan[$nombre] ?? null;

            if ($casita === null) {
                $io->text(sprintf('· %s — %s', $nombre, $seco ? 'se crearía' : 'creada'));
                $casita = (new TravelOrganizacionServicio())
                    ->setOrganizacion($org)
                    ->setNombre($nombre)
                    ->setTitulo([['language' => 'es', 'content' => $nombre]])
                    ->setDescripcion([['language' => 'es', 'content' => $def['descripcion']]]);
                if (!$seco) {
                    $this->em->persist($casita);
                }
            } elseif (!$casita->getImagenes()->isEmpty()) {
                $io->text(sprintf('· %s — ya existe, con fotos', $nombre));
                continue;
            }

            $archivos = $this->archivosDelAlbum($numero);
            foreach ($def['fotos'] as $i => $foto) {
                $ruta = $archivos[$foto] ?? throw new \RuntimeException(sprintf('La foto %s no está en el álbum de la casita %d', $foto, $numero));
                $io->text(sprintf('    foto %d%s', $i + 1, $i === 0 ? ' (portada)' : ''));
                if ($seco) {
                    continue;
                }

                // Sobre una COPIA: Vich mueve el archivo al subirlo, y éste es el de la guía.
                $copia = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6)) . '_' . $foto;
                if (!copy($ruta, $copia)) {
                    throw new \RuntimeException('No se pudo copiar ' . $ruta);
                }

                $imagen = (new TravelOrganizacionServicioImagen())
                    ->setOrganizacionServicio($casita)->setOrden($i)->setIsPortada($i === 0);
                $imagen->setImageFile(new UploadedFile($copia, $foto, null, null, true));
                $this->em->persist($imagen);
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }

    /**
     * Nombre de archivo → ruta en disco, de las fotos del álbum de la casita en la guía.
     *
     * @return array<string, string>
     */
    private function archivosDelAlbum(int $numero): array
    {
        $album = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['codigo' => 'album-casa-' . $numero])
            ?? throw new \RuntimeException('Falta el álbum de la casita ' . $numero);

        $rutas = [];
        foreach ($album->getGaleria() as $foto) {
            $ruta = $this->almacen->resolvePath($foto, 'imageFile');
            if ($foto->getImageName() !== null && \is_string($ruta) && is_file($ruta)) {
                $rutas[$foto->getImageName()] = $ruta;
            }
        }

        return $rutas;
    }
}
