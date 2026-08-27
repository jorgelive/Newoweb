<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copia el cuadro de tarifas de un componente a otros que compartan su forma.
 *
 * Nace de los vuelos: «Ticket aereo» tenía las cuatro variantes de equipaje —artículo personal,
 * mano, mano y bodega, bodega—, que es como tarifan las aerolíneas, y las 32 rutas nuevas nacieron
 * sin ninguna. Sirve igual para cualquier familia que se tarife igual (trenes, tickets).
 *
 * ⚠️ **Por ORM y no por SQL**: `TravelTarifa::$titulo` lleva `#[AutoTranslate]`. Un `INSERT`
 * directo dejaría las tarifas sin traducir y sin que nada lo denunciara.
 *
 * ⚠️ **`TravelTarifa::__clone()` antepone «(Clon) » al nombre interno.** Es correcto para el botón
 * de duplicar de la UI —ahí el clon convive con el original y hay que distinguirlos— y es justo lo
 * contrario de lo que hace falta aquí: la tarifa aterriza en OTRO componente, donde no hay con qué
 * confundirla, y quedaría «(Clon) Equipaje de mano» en 32 rutas. Se le quita el prefijo a
 * propósito, y por eso este comentario existe.
 *
 * ⚠️ **La traducción se apaga.** El clon ya se lleva los siete idiomas del original y el texto es
 * idéntico: dejarla encendida serían 128 llamadas para reescribir lo mismo.
 *
 * Idempotente por `nombreInterno`: repetirlo no duplica tarifas ni pisa importes ya puestos.
 *
 * ```
 * php bin/console app:travel:copiar-tarifas --desde="Ticket aereo" --a="Vuelo %" --dry-run
 * ```
 */
#[AsCommand(
    name: 'app:travel:copiar-tarifas',
    description: 'Copia las tarifas de un componente a todos los que casen con un patrón.',
)]
final class CopiarTarifasDeComponenteCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('desde', null, InputOption::VALUE_REQUIRED, 'Nombre exacto del componente de origen.')
            ->addOption('a', null, InputOption::VALUE_REQUIRED, 'Patrón SQL de los destinos, p. ej. "Vuelo %".')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $simula  = (bool) $input->getOption('dry-run');
        $desde   = (string) $input->getOption('desde');
        $patron  = (string) $input->getOption('a');

        if ($desde === '' || $patron === '') {
            $io->error('Hacen falta --desde y --a.');

            return Command::INVALID;
        }

        $repo   = $this->em->getRepository(TravelComponente::class);
        $origen = $repo->findOneBy(['nombre' => $desde]);

        if ($origen === null) {
            $io->error(sprintf('No existe ningún componente llamado «%s».', $desde));

            return Command::FAILURE;
        }

        $plantilla = $origen->getTarifas();

        if ($plantilla->isEmpty()) {
            $io->error(sprintf('«%s» no tiene tarifas que copiar.', $desde));

            return Command::FAILURE;
        }

        $io->section(sprintf('Origen: %s (%d tarifas)', $desde, $plantilla->count()));

        foreach ($plantilla as $tarifa) {
            $io->text(sprintf('  · %s — %s %s', $tarifa->getNombreInterno(), $tarifa->getMoneda()?->getId() ?? '', $tarifa->getMonto() ?? ''));
        }

        /** @var TravelComponente[] $destinos */
        $destinos = $repo->createQueryBuilder('c')
            ->where('c.nombre LIKE :patron')
            ->andWhere('c.id != :origen')
            ->setParameter('patron', $patron)
            ->setParameter('origen', $origen->getId())
            ->orderBy('c.nombre', 'ASC')
            ->getQuery()
            ->getResult();

        $io->section(sprintf('Destinos que casan con «%s»: %d', $patron, \count($destinos)));

        $puestas   = 0;
        $yaTenian  = 0;

        foreach ($destinos as $destino) {
            $existentes = [];

            foreach ($destino->getTarifas() as $suya) {
                $existentes[(string) $suya->getNombreInterno()] = true;
            }

            $nuevas = 0;

            foreach ($plantilla as $tarifa) {
                $nombre = (string) $tarifa->getNombreInterno();

                if (isset($existentes[$nombre])) {
                    ++$yaTenian;
                    continue;
                }

                ++$nuevas;
                ++$puestas;

                if ($simula) {
                    continue;
                }

                $copia = clone $tarifa;
                // Se deshace el «(Clon) » de `__clone()`: aquí no hay original con el que
                // confundirla, y el prefijo se quedaría a la vista en las 32 rutas.
                $copia->setNombreInterno($nombre);
                // El título ya viene con los siete idiomas del original y es el mismo texto.
                $copia->setEjecutarTraduccion(false);

                $destino->addTarifa($copia);
            }

            if ($nuevas > 0) {
                $io->text(sprintf('  %s +%d · %s', $simula ? 'pondría' : 'puestas', $nuevas, $destino->getNombre()));
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('Tarifas %s: %d · ya estaban: %d', $simula ? 'por poner' : 'puestas', $puestas, $yaTenian));

        if ($simula) {
            $io->warning('SIMULACRO: no se escribió nada.');

            return Command::SUCCESS;
        }

        $io->success('Listo. Los importes se rellenan desde el catálogo, ruta por ruta.');

        return Command::SUCCESS;
    }
}
