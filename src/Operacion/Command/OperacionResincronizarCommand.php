<?php

declare(strict_types=1);

namespace App\Operacion\Command;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Service\BibliaSnapshotService;
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
 * Regenera las filas de La Biblia de una cotización ya confirmada.
 *
 * Existe porque el snapshot es idempotente por diseño: BibliaSnapshotService salta los
 * componentes que ya tienen OperacionServicio, así que volver a confirmar la cotización
 * NO repara filas viejas ni rellena campos añadidos después. Sin este comando, cualquier
 * cambio en las reglas del snapshot sólo aplicaría a cotizaciones futuras.
 */
#[AsCommand(
    name: 'operacion:resincronizar',
    description: 'Borra y regenera los servicios operativos (La Biblia) de una cotización confirmada.'
)]
class OperacionResincronizarCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BibliaSnapshotService $snapshot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('cotizacion', InputArgument::OPTIONAL, 'UUID de la cotización a resincronizar')
            ->addOption('todas', null, InputOption::VALUE_NONE, 'Resincroniza todas las cotizaciones confirmadas')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Borra también servicios ya asignados a una Orden de Servicio')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra qué haría sin tocar la base de datos')
            ->setHelp(<<<'TXT'
Uso habitual:

  <info>php bin/console operacion:resincronizar 0198f...</info>   una cotización
  <info>php bin/console operacion:resincronizar --todas</info>    todas las confirmadas
  <info>php bin/console operacion:resincronizar --todas --dry-run</info>

Los servicios ya vinculados a una Orden de Servicio se conservan salvo que uses --force:
borrarlos rompería la trazabilidad de lo que ya se le pidió al proveedor.
TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $force    = (bool) $input->getOption('force');
        $cotRepo  = $this->em->getRepository(Cotizacion::class);
        $opsRepo  = $this->em->getRepository(OperacionServicio::class);

        // ── Selección del universo a procesar ────────────────────────────────
        if ($input->getOption('todas')) {
            $cotizaciones = $cotRepo->findBy(['estado' => CotizacionEstadoEnum::CONFIRMADO]);
        } else {
            $id = $input->getArgument('cotizacion');
            if ($id === null) {
                $io->error('Indica el UUID de una cotización o usa --todas.');

                return Command::INVALID;
            }

            if (!Uuid::isValid($id)) {
                $io->error(sprintf('"%s" no es un UUID válido.', $id));

                return Command::INVALID;
            }

            $cotizacion = $cotRepo->find(Uuid::fromString($id));
            if ($cotizacion === null) {
                $io->error(sprintf('No existe la cotización %s.', $id));

                return Command::FAILURE;
            }

            $cotizaciones = [$cotizacion];
        }

        if (empty($cotizaciones)) {
            $io->warning('No hay cotizaciones confirmadas que resincronizar.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Resincronizando La Biblia de %d cotización(es)%s', count($cotizaciones), $dryRun ? ' — DRY RUN' : ''));

        $totalBorrados  = 0;
        $totalCreados   = 0;
        $totalOmitidos  = 0;
        $filas          = [];

        foreach ($cotizaciones as $cotizacion) {
            // ── Borrar el snapshot anterior ──────────────────────────────────
            // Se busca por la colección de cotservicios y no con un WHERE cs.cotizacion = :cot:
            // el id es un Uuid binario y la comparación en DQL no lo convierte, así que esa
            // consulta devuelve 0 filas en silencio. findBy() sí pasa por el persister.
            $cotservicios = $cotizacion->getCotservicios()->toArray();
            $existentes   = $cotservicios === []
                ? []
                : $opsRepo->findBy(['cotizacionServicio' => $cotservicios]);

            $borrados = 0;
            $omitidos = 0;

            foreach ($existentes as $ops) {
                // Conservar lo que ya viajó al proveedor salvo --force
                if ($ops->getOrdenServicio() !== null && !$force) {
                    ++$omitidos;
                    continue;
                }

                if (!$dryRun) {
                    $this->em->remove($ops);
                }
                ++$borrados;
            }

            // El flush intermedio es obligatorio: el guard de idempotencia consulta la BD,
            // y sin vaciar los removes pendientes vería las filas viejas y no crearía nada.
            if (!$dryRun) {
                $this->em->flush();
            }

            // ── Regenerar ────────────────────────────────────────────────────
            $creados = $dryRun ? [] : $this->snapshot->generarParaCotizacion($cotizacion);

            if (!$dryRun) {
                $this->em->flush();
            }

            $totalBorrados += $borrados;
            $totalCreados  += count($creados);
            $totalOmitidos += $omitidos;

            $filas[] = [
                substr((string) $cotizacion->getId(), 0, 8),
                $cotizacion->getFile()?->getNombreGrupo() ?? '—',
                $borrados,
                count($creados),
                $omitidos ?: '',
            ];
        }

        $io->table(['Cotización', 'Expediente', 'Borrados', 'Creados', 'Conservados (OS)'], $filas);

        if ($dryRun) {
            $io->note('Dry run: no se creó nada. Los "creados" se muestran en 0 porque la generación no se ejecuta.');
        }

        $io->success(sprintf(
            '%d servicios borrados, %d creados, %d conservados por pertenecer a una OS.',
            $totalBorrados,
            $totalCreados,
            $totalOmitidos
        ));

        return Command::SUCCESS;
    }
}
