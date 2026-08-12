<?php

declare(strict_types=1);

namespace App\Operacion\Command;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Operacion\ApiPlatform\Dto\AplicarPlanInput;
use App\Operacion\ApiPlatform\Dto\CambioPropuesto;
use App\Operacion\Service\BibliaReconciliacionService;
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
 * Reconcilia La Biblia con la cotización, mostrando primero qué va a cambiar.
 *
 * Antes borraba y regeneraba. Dejó de hacerlo cuando las filas pasaron a contener cosas
 * que no están en la cotización —hora pactada por teléfono, prestador, teléfono del
 * recojo, costo real—: regenerar a ciegas se las llevaba por delante.
 *
 * Usa el mismo BibliaReconciliacionService que el panel de revisión, así que la consola
 * y la pantalla no pueden proponer cosas distintas.
 */
#[AsCommand(
    name: 'operacion:resincronizar',
    description: 'Reconcilia los servicios operativos (La Biblia) de una cotización confirmada, con confirmación previa.'
)]
class OperacionResincronizarCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BibliaReconciliacionService $reconciliacion,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('cotizacion', InputArgument::OPTIONAL, 'UUID de la cotización a reconciliar')
            ->addOption('todas', null, InputOption::VALUE_NONE, 'Reconcilia todas las cotizaciones confirmadas')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Aprueba también los conflictos y los sobrantes (nunca lo bloqueado)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra el plan y no aplica nada')
            ->setHelp(<<<'TXT'
Uso habitual:

  <info>php bin/console operacion:resincronizar 0198f...</info>            una cotización
  <info>php bin/console operacion:resincronizar --todas --dry-run</info>   ver qué cambiaría
  <info>php bin/console operacion:resincronizar --todas</info>             pregunta antes de aplicar

Sin --force sólo se aplica lo que llega aprobado por defecto: crear filas nuevas y
actualizar las que nadie ha tocado en Operaciones. Los conflictos (campos editados a
mano) y los sobrantes quedan fuera, igual que en el panel de revisión.

Nunca se toca lo bloqueado: filas de una Orden de Servicio ya emitida o con costo real
registrado. Ni con --force.
TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $force   = (bool) $input->getOption('force');
        $cotRepo = $this->em->getRepository(Cotizacion::class);

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
            $io->warning('No hay cotizaciones confirmadas que reconciliar.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Reconciliando %d cotización(es)%s', count($cotizaciones), $dryRun ? ' — DRY RUN' : ''));

        $totales = ['creados' => 0, 'actualizados' => 0, 'borrados' => 0, 'omitidos' => 0];

        foreach ($cotizaciones as $cotizacion) {
            if ($cotizacion->getCatalogo() !== null) {
                continue; // Los tours de catálogo no generan operación
            }

            $plan  = $this->reconciliacion->planificar($cotizacion);
            $rotulo = sprintf('%s · %s', substr((string) $cotizacion->getId(), 0, 8), $cotizacion->getFile()?->getNombreGrupo() ?? '—');

            if ($plan->cambios === []) {
                $io->writeln(sprintf('  <fg=gray>%s — sin cambios</>', $rotulo));
                continue;
            }

            $io->section($rotulo);
            $this->pintarPlan($io, $plan->cambios);
            $io->writeln('  ' . $plan->mensaje);

            if ($dryRun) {
                continue;
            }

            $aprobados = $this->aprobar($plan->cambios, $force);
            if ($aprobados === []) {
                $io->writeln('  <fg=gray>Nada que aplicar con los criterios actuales.</>');
                continue;
            }

            // El default de la pregunta depende de si hay borrados en el lote, y eso
            // decide también qué pasa con --no-interaction (Symfony devuelve el default).
            // Crear filas y actualizar campos que nadie tocó es reversible y no pierde
            // nada: puede ir por defecto, y así un cron nocturno sirve para algo. Borrar
            // no, aunque el operador lo haya pedido con --force: eso se teclea.
            $hayBorrados = $this->contarBorrados($plan->cambios, $aprobados) > 0;

            if (!$io->confirm(
                sprintf('¿Aplicar %d cambio(s) en «%s»?%s', count($aprobados), $rotulo, $hayBorrados ? ' INCLUYE BORRADOS.' : ''),
                !$hayBorrados
            )) {
                $io->writeln('  <fg=yellow>Omitida.</>');
                continue;
            }

            $resultado = $this->reconciliacion->aplicar(
                $cotizacion,
                new AplicarPlanInput(firma: $plan->firma, aprobados: $aprobados)
            );

            $totales['creados']      += $resultado->creados;
            $totales['actualizados'] += $resultado->actualizados;
            $totales['borrados']     += $resultado->borrados;
            $totales['omitidos']     += $resultado->omitidos;

            $io->writeln('  <info>' . $resultado->mensaje . '</info>');
        }

        if ($dryRun) {
            $io->note('Dry run: no se ha aplicado nada.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d creados, %d actualizados, %d borrados, %d omitidos.',
            $totales['creados'],
            $totales['actualizados'],
            $totales['borrados'],
            $totales['omitidos']
        ));

        return Command::SUCCESS;
    }

    /**
     * En consola no hay aprobación campo a campo: se aprueban los campos enteros del
     * cambio. Quien necesite ese detalle usa el panel de revisión.
     *
     * @param CambioPropuesto[] $cambios
     *
     * @return array<string, string[]>
     */
    private function aprobar(array $cambios, bool $force): array
    {
        $aprobados = [];

        foreach ($cambios as $cambio) {
            if ($cambio->bloqueado) {
                continue; // Ni con --force
            }
            if (!$force && !$cambio->aprobadoPorDefecto) {
                continue;
            }

            $aprobados[$cambio->id] = array_map(static fn ($c) => $c->campo, $cambio->campos);
        }

        return $aprobados;
    }

    /**
     * @param CambioPropuesto[]       $cambios
     * @param array<string, string[]> $aprobados
     */
    private function contarBorrados(array $cambios, array $aprobados): int
    {
        $n = 0;
        foreach ($cambios as $cambio) {
            if ($cambio->tipo === CambioPropuesto::TIPO_HUERFANO && isset($aprobados[$cambio->id])) {
                ++$n;
            }
        }

        return $n;
    }

    /** @param CambioPropuesto[] $cambios */
    private function pintarPlan(SymfonyStyle $io, array $cambios): void
    {
        $filas = [];

        foreach ($cambios as $cambio) {
            $marca = $cambio->bloqueado ? '🔒' : ($cambio->aprobadoPorDefecto ? '✓' : '·');
            $detalle = match ($cambio->tipo) {
                CambioPropuesto::TIPO_CREAR    => 'fila nueva',
                CambioPropuesto::TIPO_HUERFANO => 'ya no está en la cotización',
                default => implode(', ', array_map(
                    static fn ($c) => $c->etiqueta . ($c->enConflicto ? ' ⚠' : '') . ': ' . ($c->valorActual ?? '—') . ' → ' . ($c->valorPropuesto ?? '—'),
                    $cambio->campos
                )),
            };

            $filas[] = [
                $marca,
                $cambio->tipo,
                mb_substr($cambio->descripcion, 0, 28),
                $cambio->fecha ?? '—',
                mb_substr($detalle, 0, 70),
            ];
        }

        $io->table(['', 'Tipo', 'Servicio', 'Fecha', 'Cambio'], $filas);
        $io->writeln('  <fg=gray>✓ se aplica · · requiere aprobación (--force) · 🔒 bloqueado · ⚠ editado en Operaciones</>');
    }
}
