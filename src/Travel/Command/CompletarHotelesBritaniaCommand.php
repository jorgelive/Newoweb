<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionImagen;
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

/**
 * Los hoteles Britania Miraflores y Britania Crystal Collection, con sus habitaciones y fotos
 * (23/10/2026). Misma receta que `app:travel:completar-hotel-habitat`.
 *
 * Datos y fotos de la web oficial (hbritania.com), con permiso de Jorge; los textos son redacción
 * propia a partir de lo que dice la web. Cada habitación lleva la foto que la web pone en SU bloque.
 *
 * - **Britania Miraflores reutiliza la organización que ya había**, «Hotet Britania - Lima» (con
 *   erratas y vacía; ninguna tarifa ni cotización la usaba). Se busca por la palabra «Britania», no
 *   por el nombre exacto: es lo que evitó repetir el error del boleto de Pukará.
 * - **Britania Crystal Collection** se crea.
 * - La dirección SÍ se carga: es la que lee el trasladista al recoger en el hotel.
 * - Teléfono y correo, comprobados (Jorge): la web escribe «hbritaniamiaflores.com», que NO existe
 *   —sin DNS—; el bueno es hbritaniamiraflores.com, con correo en Google Workspace, igual que
 *   britaniam.com. Para Crystal la web da un buzón en cada dominio: son alias del mismo correo
 *   («no gastan mailboxes por gusto»), y se usa el de la ficha del hotel.
 *
 * Idempotente: sólo rellena lo vacío, y no repite habitaciones ni fotos.
 *
 *   php bin/console app:travel:completar-hoteles-britania --fotos=/tmp/brit_fotos --dry-run
 */
#[AsCommand(
    name: 'app:travel:completar-hoteles-britania',
    description: 'Crea o completa los hoteles Britania Miraflores y Crystal, con habitaciones y fotos.',
    hidden: true,
)]
final class CompletarHotelesBritaniaCommand extends Command
{
    private const string BASE = 'Con baño privado con ducha o tina, secadora de cabello, TV LCD de 32” con cable, '
        . 'teléfono, caja fuerte, frigobar y aire acondicionado.';

    /**
     * @var list<array{buscar: list<string>, nombre: string, titulo: string, descripcion: string, url: string,
     *                 direccion: string, telefono: string, email: string, fotos: list<string>,
     *                 habitaciones: list<array{nombre: string, descripcion: string, fotos: list<string>}>}>
     */
    private const array HOTELES = [
        [
            'buscar' => ['Hotet Britania - Lima', 'Hotel Britania Miraflores'],
            'nombre' => 'Hotel Britania Miraflores',
            'titulo' => 'Hotel Britania Miraflores',
            'descripcion' => 'Hotel de 94 habitaciones en la calle Independencia, en Miraflores, a unos 500 metros del '
                . 'Parque Kennedy y a 800 de la Huaca Pucllana; Larcomar y la playa quedan a menos de un kilómetro. '
                . 'Desayuno buffet, wifi gratuito, aire acondicionado y estacionamiento privado.',
            'url' => 'https://www.hbritania.com/es/miraflores/',
            'direccion' => 'Calle Independencia 211, Miraflores, Lima',
            'telefono' => '+51 961 359 381',
            'email' => 'agencias@hbritaniamiraflores.com',
            'fotos' => ['mir__dsc08866-hdr_copy.jpg', 'mir__dsc08846.png', 'mir__dsc08660.jpg', 'mir__dsc08677.jpg', 'mir__suite_moderna_1.jpg'],
            'habitaciones' => [
                ['nombre' => 'Habitación Individual', 'fotos' => ['mir__eyuy.png'],
                    'descripcion' => 'Habitación acogedora para una persona. ' . self::BASE],
                ['nombre' => 'Habitación Doble (1 cama)', 'fotos' => ['mir__eyuy.png'],
                    'descripcion' => 'Habitación con una cama de dos plazas. ' . self::BASE],
                ['nombre' => 'Habitación Twin', 'fotos' => ['mir__doble_1.jpg'],
                    'descripcion' => 'Habitación con dos camas. ' . self::BASE],
                ['nombre' => 'Habitación Triple', 'fotos' => ['mir__geeru.jpg'],
                    'descripcion' => 'Habitación para tres personas. ' . self::BASE],
                ['nombre' => 'Suite', 'fotos' => ['mir__bthgh.png', 'mir__vbnfd.png'],
                    'descripcion' => 'Suite con jacuzzi y minisala, baño privado con ducha española, secadora de cabello, '
                        . 'TV LCD de 43” con cable, teléfono, caja fuerte, frigobar y aire acondicionado.'],
            ],
        ],
        [
            'buscar' => ['Hotel Britania Crystal Collection'],
            'nombre' => 'Hotel Britania Crystal Collection',
            'titulo' => 'Hotel Britania Crystal Collection',
            'descripcion' => 'Hotel de 88 habitaciones en la calle Narciso de la Colina, en Miraflores, a cinco minutos a '
                . 'pie del Parque Kennedy. En la azotea tiene piscina al aire libre, sauna y solárium, y el desayuno '
                . 'buffet se sirve en su restaurante, Chavela 376. Wifi gratuito y estacionamiento subterráneo.',
            'url' => 'https://www.hbritania.com/es/crystal-collection/',
            'direccion' => 'Calle Narciso de la Colina 345, Miraflores, Lima',
            'telefono' => '+51 991 486 719',
            'email' => 'reservascrystal@hbritaniamiraflores.com',
            'fotos' => ['cry__hyyy.png', 'cry__e343.jpg', 'cry__dsc04721-hdr.jpg', 'cry__dsc04547-hdr.jpg', 'cry__p1024274.jpg', 'cry__chavela376_crystal_3.jpg'],
            'habitaciones' => [
                ['nombre' => 'Habitación Individual', 'fotos' => ['cry__13.png'],
                    'descripcion' => 'Habitación para una persona, insonorizada, con aire acondicionado y wifi gratuito.'],
                ['nombre' => 'Habitación Doble (1 cama)', 'fotos' => ['cry__web_formato_fotos_horizontal.png'],
                    'descripcion' => 'Habitación con cama de matrimonio, baño privado y vista a la ciudad.'],
                ['nombre' => 'Habitación Twin', 'fotos' => ['cry__5ec49b99-ea26-4f40-b5d7-eacd70c27087.jpeg'],
                    'descripcion' => 'Habitación con dos camas individuales, baño privado y caja fuerte.'],
                ['nombre' => 'Habitación Triple', 'fotos' => ['cry__12.png'],
                    'descripcion' => 'Habitación con tres camas individuales, baño privado, TV por cable y wifi gratuito.'],
                ['nombre' => 'Junior Suite', 'fotos' => ['cry__d2677971-b620-4e3e-935a-3efddcd1932d.jpg'],
                    'descripcion' => 'Habitación amplia e insonorizada con cama extralarga, aire acondicionado individual, '
                        . 'escritorio y minibar.'],
                ['nombre' => 'Suite Smart', 'fotos' => ['cry__4fa616fa-7d22-40f7-bbbc-df6ce373fd34.jpeg'],
                    'descripcion' => 'Habitación amplia con cama extragrande y sala, escritorio, caja fuerte, frigobar y '
                        . 'asistente virtual.'],
            ],
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fotos', null, InputOption::VALUE_REQUIRED, 'Carpeta con las fotos descargadas de la web.');
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

        $foto = static function (string $archivo) use ($carpeta): UploadedFile {
            $ruta = rtrim($carpeta, '/') . '/' . $archivo;
            if (!is_file($ruta)) {
                throw new \RuntimeException('Falta la foto ' . $ruta);
            }

            // ⚠️ Sobre una COPIA: Vich MUEVE el archivo al subirlo, y la misma foto va en dos
            // habitaciones (Individual y Doble de Miraflores comparten la de la web). Sin copia, la
            // segunda no encontraría nada.
            $copia = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6)) . '_' . $archivo;
            if (!copy($ruta, $copia)) {
                throw new \RuntimeException('No se pudo copiar ' . $ruta);
            }

            // `test: true`: no llega por HTTP, y sin esto `move()` se niega a moverlo.
            return new UploadedFile($copia, $archivo, null, null, true);
        };

        $repo = $this->em->getRepository(TravelOrganizacion::class);

        foreach (self::HOTELES as $def) {
            $io->section($def['nombre']);

            $hotel = null;
            foreach ($def['buscar'] as $nombre) {
                $hotel ??= $repo->findOneBy(['nombreComercial' => $nombre]);
            }

            if (!$hotel instanceof TravelOrganizacion) {
                $io->text(sprintf('  %s', $seco ? 'se crearía' : 'creado'));
                $hotel = (new TravelOrganizacion())->setNombreComercial($def['nombre'])->setRazonSocial('Hotel Britania');
                if (!$seco) {
                    $this->em->persist($hotel);
                }
            } elseif ($hotel->getNombreComercial() !== $def['nombre']) {
                $io->text(sprintf('  «%s» → «%s»', $hotel->getNombreComercial(), $def['nombre']));
                if (!$seco) {
                    $hotel->setNombreComercial($def['nombre'])->setRazonSocial('Hotel Britania');
                }
            }

            if ($hotel->getTitulo() === []) {
                $io->text('  título, descripción, web, dirección y visible para el cliente');
                if (!$seco) {
                    $hotel->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                        ->setDescripcion([['language' => 'es', 'content' => $def['descripcion']]])
                        ->setUrl($def['url'])
                        ->setDireccion($def['direccion'])
                        ->setVisibleParaCliente(true);
                }
            }

            if (($hotel->getTelefono() ?? '') === '' || ($hotel->getEmail() ?? '') === '') {
                $io->text(sprintf('  contacto: %s · %s', $def['telefono'], $def['email']));
                if (!$seco) {
                    if (($hotel->getTelefono() ?? '') === '') {
                        $hotel->setTelefono($def['telefono']);
                    }
                    if (($hotel->getEmail() ?? '') === '') {
                        $hotel->setEmail($def['email']);
                    }
                }
            }

            if ($hotel->getId() === null || $hotel->getImagenes()->isEmpty()) {
                foreach ($def['fotos'] as $i => $archivo) {
                    $io->text(sprintf('  foto %d: %s%s', $i + 1, $archivo, $i === 0 ? ' (portada)' : ''));
                    if ($seco) {
                        continue;
                    }
                    $imagen = (new TravelOrganizacionImagen())->setOrganizacion($hotel)->setOrden($i)->setIsPortada($i === 0);
                    $imagen->setImageFile($foto($archivo));
                    $this->em->persist($imagen);
                }
            }

            $yaEstan = [];
            if ($hotel->getId() !== null) {
                foreach ($this->em->getRepository(TravelOrganizacionServicio::class)->findBy(['organizacion' => $hotel]) as $s) {
                    $yaEstan[(string) $s->getNombre()] = true;
                }
            }

            foreach ($def['habitaciones'] as $hab) {
                if (isset($yaEstan[$hab['nombre']])) {
                    $io->text(sprintf('  %s — ya existe', $hab['nombre']));
                    continue;
                }
                $io->text(sprintf('  %s — %s (%s)', $hab['nombre'], $seco ? 'se crearía' : 'creada', implode(', ', $hab['fotos'])));
                if ($seco) {
                    continue;
                }

                $servicio = (new TravelOrganizacionServicio())
                    ->setOrganizacion($hotel)
                    ->setNombre($hab['nombre'])
                    ->setTitulo([['language' => 'es', 'content' => $hab['nombre']]])
                    ->setDescripcion([['language' => 'es', 'content' => $hab['descripcion']]]);
                $this->em->persist($servicio);

                foreach ($hab['fotos'] as $i => $archivo) {
                    $imagen = (new TravelOrganizacionServicioImagen())
                        ->setOrganizacionServicio($servicio)->setOrden($i)->setIsPortada($i === 0);
                    $imagen->setImageFile($foto($archivo));
                    $this->em->persist($imagen);
                }
            }

            if (!$seco) {
                $this->em->flush();
            }
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
