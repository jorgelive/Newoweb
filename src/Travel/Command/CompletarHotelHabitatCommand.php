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
 * La ficha pública del Hotel Habitat (Miraflores), que estaba vacía (23/10/2026).
 *
 * Jorge lo marcó «Nombrarlo al cliente» en la propuesta de Sylvie Goussé y no salía: la guía sólo
 * pinta la tarjeta del prestador si la organización tiene TÍTULO público, y ésta no tenía título,
 * descripción, web ni fotos. Datos de su web oficial (habitathotelperu.com); la descripción es
 * redacción propia, aprobada por Jorge. Las fotos se descargaron de esa web con su permiso.
 *
 * Crea además las cuatro habitaciones que ofrece —Individual, Matrimonial, Doble y Triple— como
 * servicios de la organización, con su foto, para elegirlas en el componente (Prestador → servicio).
 *
 * Y el contacto de reservas, que tampoco tenía: el móvil —el formato de los demás, y el que sirve
 * para WhatsApp— y el correo de reservas. ⚠️ De la ficha salen las órdenes al proveedor; se puso
 * porque Jorge lo pidió expresamente. La centralita (+51 1 242 2222) queda anotada aquí.
 *
 * Por comando y no por migración: título y descripciones llevan `#[AutoTranslate]`.
 * Idempotente: sólo rellena lo vacío y no duplica habitaciones ni fotos.
 *
 *   php bin/console app:travel:completar-hotel-habitat --fotos=/tmp/habitat_fotos --dry-run
 */
#[AsCommand(
    name: 'app:travel:completar-hotel-habitat',
    description: 'Completa la ficha del Hotel Habitat: título, descripción, web, contacto, fotos y habitaciones.',
    hidden: true,
)]
final class CompletarHotelHabitatCommand extends Command
{
    private const string NOMBRE = 'Hotel Habitat';
    private const string TITULO = 'Habitat Hotel';
    private const string URL = 'https://habitathotelperu.com/es/';
    private const string TELEFONO = '+51 997 392 963';
    private const string EMAIL = 'reservas@habitathotelperu.com';
    private const string DESCRIPCION = 'Hotel moderno en el corazón de Miraflores, en la esquina de la calle Piura con la '
        . 'avenida Arequipa. Está a unos 600 metros del Parque Kennedy, a pasos del Mercado Indio y rodeado de '
        . 'restaurantes; Larcomar y el malecón quedan a unos 2 km. Wifi gratuito, recepción las 24 horas y '
        . 'desayuno buffet incluido.';

    /** Galería del hotel, en orden; la primera es la portada. */
    private const array FOTOS_HOTEL = ['img-home1', 'slider-home-n', 'banner2-n', 'restaurante-bloq2', 'img-home2'];

    /** @var list<array{nombre: string, descripcion: string, foto: string}> */
    private const array HABITACIONES = [
        ['nombre' => 'Habitación Individual', 'foto' => 'habitacion-ind-bloq2',
            'descripcion' => 'Habitación con una cama queen, frigobar, wifi gratuito y desayuno incluido.'],
        ['nombre' => 'Habitación Matrimonial', 'foto' => 'slider-new-habitaciones-11',
            'descripcion' => 'Habitación amplia con una cama queen, frigobar, wifi gratuito y desayuno incluido.'],
        ['nombre' => 'Habitación Doble', 'foto' => 'habitacion3-ind-bloq2',
            'descripcion' => 'Habitación amplia con dos camas de plaza y media, frigobar, wifi gratuito y desayuno incluido.'],
        ['nombre' => 'Habitación Triple', 'foto' => 'habitacion4-ind-bloq2',
            'descripcion' => 'Habitación amplia con tres camas de plaza y media, frigobar, wifi gratuito y desayuno incluido.'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fotos', null, InputOption::VALUE_REQUIRED, 'Carpeta con las fotos .webp descargadas de la web del hotel.');
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

        $hotel = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::NOMBRE]);
        if (!$hotel instanceof TravelOrganizacion) {
            $io->error('No existe la organización ' . self::NOMBRE . '.');

            return Command::FAILURE;
        }

        $foto = static function (string $nombre) use ($carpeta): UploadedFile {
            $ruta = rtrim($carpeta, '/') . '/' . $nombre . '.webp';
            if (!is_file($ruta)) {
                throw new \RuntimeException('Falta la foto ' . $ruta);
            }

            // `test: true`: no llega por HTTP, y sin esto `move()` se niega a moverlo.
            return new UploadedFile($ruta, $nombre . '.webp', 'image/webp', null, true);
        };

        if ($hotel->getTitulo() === []) {
            $io->text('· Título, descripción, web y visible para el cliente');
            if (!$seco) {
                $hotel->setTitulo([['language' => 'es', 'content' => self::TITULO]])
                    ->setDescripcion([['language' => 'es', 'content' => self::DESCRIPCION]])
                    ->setUrl(self::URL)
                    ->setVisibleParaCliente(true);
            }
        }

        if (($hotel->getTelefono() ?? '') === '' || ($hotel->getEmail() ?? '') === '') {
            $io->text(sprintf('· Contacto: %s · %s', self::TELEFONO, self::EMAIL));
            if (!$seco) {
                if (($hotel->getTelefono() ?? '') === '') {
                    $hotel->setTelefono(self::TELEFONO);
                }
                if (($hotel->getEmail() ?? '') === '') {
                    $hotel->setEmail(self::EMAIL);
                }
            }
        }

        if ($hotel->getImagenes()->isEmpty()) {
            foreach (self::FOTOS_HOTEL as $i => $nombre) {
                $io->text(sprintf('· Foto del hotel %d: %s%s', $i + 1, $nombre, $i === 0 ? ' (portada)' : ''));
                if ($seco) {
                    continue;
                }

                $imagen = (new TravelOrganizacionImagen())
                    ->setOrganizacion($hotel)
                    ->setOrden($i)
                    ->setIsPortada($i === 0);
                $imagen->setImageFile($foto($nombre));
                $this->em->persist($imagen);
            }
        }

        $yaEstan = [];
        foreach ($this->em->getRepository(TravelOrganizacionServicio::class)->findBy(['organizacion' => $hotel]) as $s) {
            $yaEstan[(string) $s->getNombre()] = true;
        }

        foreach (self::HABITACIONES as $def) {
            if (isset($yaEstan[$def['nombre']])) {
                $io->text(sprintf('· %s — ya existe', $def['nombre']));
                continue;
            }

            $io->text(sprintf('· %s — %s', $def['nombre'], $seco ? 'se crearía' : 'creada'));
            if ($seco) {
                continue;
            }

            $habitacion = (new TravelOrganizacionServicio())
                ->setOrganizacion($hotel)
                ->setNombre($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['nombre']]])
                ->setDescripcion([['language' => 'es', 'content' => $def['descripcion']]]);
            $this->em->persist($habitacion);

            $imagen = (new TravelOrganizacionServicioImagen())
                ->setOrganizacionServicio($habitacion)
                ->setOrden(0)
                ->setIsPortada(true);
            $imagen->setImageFile($foto($def['foto']));
            $this->em->persist($imagen);
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
