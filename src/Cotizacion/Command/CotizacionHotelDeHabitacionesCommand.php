<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Enum\GrupoTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le pone el HOTEL a las habitaciones de un expediente.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * El `subeje` del subgrupo pasó a estar disponible para cualquier eje, así que una habitación puede
 * decir en qué hotel está —`HABITACIÓN · OCCIDENTAL CARIBE`, igual que `VUELO · INTERNACIONAL`—.
 * Pero los expedientes que ya existían lo tienen vacío, y son **66 habitaciones en uno solo**:
 * a mano es una tarde, y una tarde de teclear el mismo texto 66 veces es una tarde de erratas.
 *
 * 🔥 **Y no es cosmético: el hotel entra en la clave única** `(file, tipo, subeje, clave)`. Con el
 * sufijo vacío, la `HA13` del hotel A y la `HA13` del hotel B **son la misma fila** y una pisa a la
 * otra. Rellenarlo es lo que las separa.
 *
 * ── Por qué un comando y no una migración ───────────────────────────────────
 * ⚠️ Esto es **contenido de un expediente**, no evolución del esquema. Una migración corre a ciegas
 * en cada entorno y una sola vez: en una base recién creada ese expediente no existe, y si el
 * nombre del hotel estaba mal no hay forma de volver a pasarla. Un comando se apunta a un
 * expediente, se prueba con `--dry-run` y se repite las veces que haga falta.
 *
 * ── Por prefijo, porque un expediente puede tener dos hoteles ───────────────
 * ⚠️ **No hay forma de deducir qué habitación es de qué hotel.** El itinerario sabe qué hoteles
 * tiene el viaje, pero no qué número de cuarto cae en cuál — eso lo sabe quien recibió el rooming
 * list. Por eso el filtro es el PREFIJO de la clave, que es como los numeran los hoteles
 * (`HA01…HA66` en uno, `HP01…HP14` en otro), y el nombre se escribe a mano.
 *
 * Sin `--prefijo` se aplica a TODAS las habitaciones del expediente, que es lo correcto cuando hay
 * un solo hotel.
 *
 * ── Idempotente, y no pisa lo ya escrito ────────────────────────────────────
 * Una habitación que ya tenga hotel se deja como está y se dice. Para cambiarlo, `--forzar`.
 *
 *   php bin/console app:cotizacion:hotel-habitaciones 5SRAJV "Occidental Caribe" --prefijo=HA --dry-run
 *   php bin/console app:cotizacion:hotel-habitaciones 5SRAJV "Occidental Caribe" --prefijo=HA
 */
#[AsCommand(
    name: 'app:cotizacion:hotel-habitaciones',
    description: 'Pone el hotel (subeje) a las habitaciones de un expediente. Idempotente.',
)]
final class CotizacionHotelDeHabitacionesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('localizador', InputArgument::REQUIRED, 'El localizador del expediente, p. ej. 5SRAJV')
            ->addArgument('hotel', InputArgument::REQUIRED, 'El nombre del hotel, tal cual lo verá el pasajero')
            ->addOption('prefijo', null, InputOption::VALUE_REQUIRED, 'Sólo las claves que empiecen así (HA, HP…)')
            ->addOption('forzar', null, InputOption::VALUE_NONE, 'También reescribe las que ya tienen hotel')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $localizador = (string) $input->getArgument('localizador');
        $hotel = trim((string) $input->getArgument('hotel'));
        $prefijo = $input->getOption('prefijo');
        $prefijo = is_string($prefijo) ? mb_strtoupper(trim($prefijo)) : null;
        $forzar = (bool) $input->getOption('forzar');
        $simular = (bool) $input->getOption('dry-run');

        if ($hotel === '') {
            $io->error('El nombre del hotel no puede estar vacío: es lo que va a leer el pasajero.');

            return Command::FAILURE;
        }

        $file = $this->em->getRepository(CotizacionFile::class)->findOneBy(['localizador' => $localizador]);

        if ($file === null) {
            $io->error(sprintf('No existe ningún expediente con el localizador «%s».', $localizador));

            return Command::FAILURE;
        }

        $tocadas = [];
        $yaTenian = [];

        foreach ($file->getGrupos() as $grupo) {
            if ($grupo->getTipo() !== GrupoTipoEnum::HABITACION) {
                continue;
            }

            $clave = (string) $grupo->getClave();

            if ($prefijo !== null && !str_starts_with(mb_strtoupper($clave), $prefijo)) {
                continue;
            }

            $actual = trim($grupo->getSubeje());

            if ($actual === $hotel) {
                continue;
            }

            // ⚠️ Lo que ya tiene hotel no se pisa por defecto. Si alguien reparó tres a mano y
            // luego se lanza esto con el nombre de otro hotel, sin este guarda se las lleva por
            // delante y no queda rastro de cuáles eran.
            if ($actual !== '' && !$forzar) {
                $yaTenian[] = [$clave, $actual];
                continue;
            }

            $tocadas[] = [$clave, $actual === '' ? '—' : $actual, $hotel];

            if (!$simular) {
                $grupo->setSubeje($hotel);
            }
        }

        if ($yaTenian !== []) {
            $io->warning(sprintf('%d ya tienen otro hotel y NO se tocan (usa --forzar):', count($yaTenian)));
            $io->table(['habitación', 'hotel actual'], $yaTenian);
        }

        if ($tocadas === []) {
            $io->success('No hay nada que cambiar.');

            return Command::SUCCESS;
        }

        $io->table(['habitación', 'antes', 'después'], array_slice($tocadas, 0, 10));

        if (count($tocadas) > 10) {
            $io->writeln(sprintf('… y %d más.', count($tocadas) - 10));
        }

        if ($simular) {
            $io->note(sprintf('--dry-run: no se escribió nada. Serían %d habitaciones.', count($tocadas)));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d habitación(es) ahora en «%s».', count($tocadas), $hotel));

        return Command::SUCCESS;
    }
}
