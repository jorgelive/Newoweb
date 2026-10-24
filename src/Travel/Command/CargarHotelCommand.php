<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Dto\Lee;
use App\Travel\Dto\HotelACargar;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionImagen;
use App\Travel\Entity\TravelOrganizacionServicio;
use App\Travel\Entity\TravelOrganizacionServicioImagen;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Carga la ficha de un hotel —título, descripción, contacto, fotos y habitaciones— desde un JSON
 * (23/10/2026). La forma del archivo está en {@see HotelACargar}.
 *
 * Nace de hacer lo mismo tres veces en un día (Habitat, los dos Britania, Qelqatani): la receta es
 * siempre igual y lo que cambia son los datos. Por eso está a la vista y no archivada: es de los
 * parametrizados de verdad (CLAUDE.md, «Qué entra por migración y qué por comando»).
 *
 * Lo que garantiza, aprendido en esos tres:
 *
 * - **Busca antes de crear, por todos los nombres de `buscar`**: el catálogo tiene fichas vacías y
 *   con erratas («Hotet Britania - Lima») que hay que reutilizar, no duplicar.
 * - **`persist()` DESPUÉS de rellenar lo traducible**: la traducción corre en `prePersist`, y una
 *   ficha persistida vacía se queda sólo en español (pasó con el Crystal).
 * - **Cada foto se sube desde una COPIA**: Vich mueve el archivo, y la misma foto puede ir en dos
 *   habitaciones.
 * - **Sólo rellena lo vacío**: título, contacto, fotos y habitaciones que ya estén no se tocan.
 *
 * ⚠️ El correo y el teléfono son de donde salen las órdenes al proveedor: compruébalos (el MX del
 * dominio, como con los Britania) antes de ponerlos en el JSON.
 *
 *   php bin/console app:travel:cargar-hotel hotel.json --fotos=/tmp/fotos --dry-run
 */
#[AsCommand(
    name: 'app:travel:cargar-hotel',
    description: 'Crea o completa la ficha de un hotel (con habitaciones y fotos) desde un JSON.',
)]
final class CargarHotelCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('archivo', InputArgument::REQUIRED, 'JSON con la ficha (ver HotelACargar).');
        $this->addOption('fotos', null, InputOption::VALUE_REQUIRED, 'Carpeta con las fotos que nombra el JSON.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $archivo = Lee::texto($input->getArgument('archivo')) ?? '';
        $carpeta = Lee::texto($input->getOption('fotos')) ?? '';

        if (!is_file($archivo)) {
            $io->error('No encuentro el archivo ' . $archivo);

            return Command::FAILURE;
        }

        $hotel = HotelACargar::fromArray(Lee::mapa(json_decode((string) file_get_contents($archivo), true)));

        $todas = [...$hotel->fotos, ...array_merge(...array_map(static fn (array $h): array => $h['fotos'], $hotel->habitaciones ?: [['fotos' => []]]))];
        foreach ($todas as $foto) {
            if (!is_file(rtrim($carpeta, '/') . '/' . $foto)) {
                $io->error(sprintf('Falta la foto %s en %s', $foto, $carpeta));

                return Command::FAILURE;
            }
        }

        $io->section($hotel->nombre);

        $repo = $this->em->getRepository(TravelOrganizacion::class);
        $org = null;
        foreach ($hotel->buscar as $nombre) {
            $org ??= $repo->findOneBy(['nombreComercial' => $nombre]);
        }

        $nueva = !$org instanceof TravelOrganizacion;
        if (!$org instanceof TravelOrganizacion) {
            $io->text(sprintf('  %s', $seco ? 'se crearía' : 'creada'));
            $org = (new TravelOrganizacion())->setNombreComercial($hotel->nombre)->setRazonSocial($hotel->nombre);
        } elseif ($org->getNombreComercial() !== $hotel->nombre) {
            $io->text(sprintf('  «%s» → «%s»', $org->getNombreComercial(), $hotel->nombre));
            if (!$seco) {
                $org->setNombreComercial($hotel->nombre);
            }
        }

        if ($org->getTitulo() === []) {
            $io->text('  título, descripción, web, dirección y visible para el cliente');
            if (!$seco) {
                $org->setTitulo([['language' => 'es', 'content' => $hotel->titulo]])
                    ->setDescripcion([['language' => 'es', 'content' => $hotel->descripcion]])
                    ->setUrl($org->getUrl() ?: $hotel->url)
                    ->setDireccion($org->getDireccion() ?: $hotel->direccion)
                    ->setVisibleParaCliente(true);
            }
        }

        if ($hotel->telefono !== null && ($org->getTelefono() ?? '') === '') {
            $io->text('  teléfono: ' . $hotel->telefono);
            if (!$seco) {
                $org->setTelefono($hotel->telefono);
            }
        }
        if ($hotel->email !== null && ($org->getEmail() ?? '') === '') {
            $io->text('  correo: ' . $hotel->email);
            if (!$seco) {
                $org->setEmail($hotel->email);
            }
        }

        // ⚠️ Después de rellenar título y descripción: la traducción corre en `prePersist`.
        if ($nueva && !$seco) {
            $this->em->persist($org);
        }

        if ($nueva || $org->getImagenes()->isEmpty()) {
            foreach ($hotel->fotos as $i => $foto) {
                $io->text(sprintf('  foto %d: %s%s', $i + 1, $foto, $i === 0 ? ' (portada)' : ''));
                if (!$seco) {
                    $imagen = (new TravelOrganizacionImagen())->setOrganizacion($org)->setOrden($i)->setIsPortada($i === 0);
                    $imagen->setImageFile($this->copia($carpeta, $foto));
                    $this->em->persist($imagen);
                }
            }
        }

        $yaEstan = [];
        if (!$nueva) {
            foreach ($this->em->getRepository(TravelOrganizacionServicio::class)->findBy(['organizacion' => $org]) as $s) {
                $yaEstan[(string) $s->getNombre()] = true;
            }
        }

        foreach ($hotel->habitaciones as $hab) {
            if (isset($yaEstan[$hab['nombre']])) {
                $io->text(sprintf('  %s — ya existe', $hab['nombre']));
                continue;
            }
            $io->text(sprintf('  %s — %s (%d foto/s)', $hab['nombre'], $seco ? 'se crearía' : 'creada', \count($hab['fotos'])));
            if ($seco) {
                continue;
            }

            $servicio = (new TravelOrganizacionServicio())
                ->setOrganizacion($org)
                ->setNombre($hab['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $hab['nombre']]])
                ->setDescripcion([['language' => 'es', 'content' => $hab['descripcion']]]);
            $this->em->persist($servicio);

            foreach ($hab['fotos'] as $i => $foto) {
                $imagen = (new TravelOrganizacionServicioImagen())
                    ->setOrganizacionServicio($servicio)->setOrden($i)->setIsPortada($i === 0);
                $imagen->setImageFile($this->copia($carpeta, $foto));
                $this->em->persist($imagen);
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }

    /** La foto, copiada aparte: Vich mueve el archivo al subirlo, y puede ir en dos sitios. */
    private function copia(string $carpeta, string $foto): UploadedFile
    {
        $origen = rtrim($carpeta, '/') . '/' . $foto;
        $copia = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6)) . '_' . basename($foto);
        if (!copy($origen, $copia)) {
            throw new \RuntimeException('No se pudo copiar ' . $origen);
        }

        // `test: true`: no llega por HTTP, y sin esto `move()` se niega a moverlo.
        return new UploadedFile($copia, basename($foto), null, null, true);
    }
}
