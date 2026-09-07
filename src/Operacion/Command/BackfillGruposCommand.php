<?php

declare(strict_types=1);

namespace App\Operacion\Command;

use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Operacion\Entity\OperacionOrdenServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rellena «quién viaja» en las órdenes que ya estaban emitidas.
 *
 * El bloque de grupos y la etiqueta por línea nacieron después que ellas, así que salen vacíos.
 * Dejarlos así obligaría a **reemitir una orden confirmada sólo para ponerle el nombre del
 * cliente** — anular, avisar al proveedor y volver a empezar, a cambio de un dato que no
 * contradice nada de lo enviado: añade lo que faltaba.
 *
 * ⚠️ Toca documentos ya emitidos, igual que `app:operacion:backfill-unidades` y por el mismo
 * motivo. No cambia ni una cifra, ni una fecha, ni un importe.
 */
#[AsCommand(
    name: 'app:operacion:backfill-grupos',
    description: 'Rellena el bloque de grupos y la etiqueta por línea en las órdenes ya emitidas.',
)]
final class BackfillGruposCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'No guarda nada: enseña lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        /** @var list<OperacionOrdenServicio> $ordenes */
        $ordenes = $this->em->getRepository(OperacionOrdenServicio::class)->findAll();

        $filas = [];
        $lineas = 0;

        foreach ($ordenes as $orden) {
            /** @var array<string, array{localizador: string, grupo: string, pasajero: string, telefono: string, habitaciones: int, pax: int}> $porFile */
            $porFile = [];
            /** @var array<string, string> $grupoPorServicio */
            $grupoPorServicio = [];

            foreach ($orden->getOperacionServicios() as $servicio) {
                $file = $servicio->getFile();

                if ($file === null) {
                    continue;
                }

                $nombre = (string) ($file->getNombreGrupo() ?? '');
                $grupoPorServicio[(string) $servicio->getId()] = $nombre;

                $clave = (string) $file->getId();

                if (isset($porFile[$clave])) {
                    continue;
                }

                $habitaciones = 0;
                foreach ($file->getGrupos() as $grupo) {
                    if ($grupo->getTipo() === GrupoTipoEnum::HABITACION) {
                        ++$habitaciones;
                    }
                }

                $porFile[$clave] = [
                    'localizador'  => (string) $file->getLocalizador(),
                    'grupo'        => $nombre,
                    'pasajero'     => (string) ($file->getPasajeroPrincipal() ?? ''),
                    'telefono'     => (string) ($file->getTelefono() ?? ''),
                    'habitaciones' => $habitaciones,
                    'pax'          => $servicio->getCotizacionServicio()?->getCotizacion()?->getNumPax() ?? 0,
                ];
            }

            if ($porFile === []) {
                continue;
            }

            $orden->setGruposSnapshot(array_values($porFile));

            foreach ($orden->getItems() as $item) {
                $nombre = $grupoPorServicio[(string) $item->getOperacionServicioId()] ?? '';

                if ($nombre !== '') {
                    $item->setNombreGrupo($nombre);
                    ++$lineas;
                }
            }

            $primero = array_values($porFile)[0];
            $filas[] = [
                $orden->getNumeroOs(),
                (string) count($porFile),
                $primero['grupo'] !== '' ? $primero['grupo'] : '—',
                $primero['pasajero'] !== '' ? $primero['pasajero'] : '—',
                (string) $primero['habitaciones'],
                (string) $primero['pax'],
                $primero['telefono'] !== '' ? $primero['telefono'] : '— sin teléfono',
            ];
        }

        if ($filas === []) {
            $io->success('No hay órdenes con expediente que rellenar.');

            return Command::SUCCESS;
        }

        $io->table(['Orden', 'Grupos', 'Grupo', 'Pasajero', 'Hab.', 'Pax', 'Teléfono'], $filas);
        $io->writeln(sprintf('  Líneas etiquetadas: <info>%d</info>', $lineas));

        if ($seco) {
            $io->warning('Ensayo: no se guardó nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d orden(es) rellenada(s).', count($filas)));

        return Command::SUCCESS;
    }
}
