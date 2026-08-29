<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pone al día el NOMBRE INTERNO congelado en las cotizaciones cuando el maestro se ha renombrado.
 *
 * Tras el refactor de transporte de agosto de 2026 —94 componentes con 336 tarifas reducidos a 25
 * rutas bidireccionales— las cotizaciones seguían diciendo «Transporte Cusco - Ollanta» mientras
 * el catálogo ya decía «Transporte Cusco ↔ Ollanta (ida o vuelta)». El UUID apuntaba bien; el
 * texto no. Y de ahí lo copia La Biblia, que es lo que ve el operador al despachar.
 *
 * ## Qué se refresca y qué NO
 *
 * - **Sí `nombreInternoSnapshot`**: es el nombre OPERATIVO, con el que se despacha. Que diga un
 *   nombre que ya no existe en el catálogo obliga a buscar a ciegas.
 * - **NO `tituloSnapshot`**, y es deliberado: es prosa de CLIENTE, se edita a mano por cotización
 *   y refrescarlo **destruiría esas ediciones** — además de cambiar lo que un cliente ya leyó en
 *   una cotización enviada.
 * - **NO los importes**: el precio congelado es lo que se vendió, y eso no lo cambia un renombrado.
 *
 * ## Las órdenes emitidas no entran
 *
 * Un documento emitido dice lo que dijo. Se ponen al día corriendo después
 * `operacion:resincronizar`, que sí recalcula La Biblia — y sólo las órdenes que aún no se han
 * emitido heredarán el nombre nuevo.
 */
#[AsCommand(
    name: 'app:cotizacion:refrescar-nombres-maestros',
    description: 'Actualiza el nombre interno congelado en cotizaciones cuando el maestro se renombró.',
)]
final class RefrescarNombresMaestrosCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué actualizaría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $componentes = $this->refrescarComponentes($io, $simula);
        $tarifas = $this->refrescarTarifas($io, $simula);

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->table(
            ['componentes actualizados', 'tarifas actualizadas'],
            [[$componentes, $tarifas]],
        );

        if ($componentes > 0 || $tarifas > 0) {
            $io->note('Ahora `operacion:resincronizar --todas` para que La Biblia lo recoja.');
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    private function refrescarComponentes(SymfonyStyle $io, bool $simula): int
    {
        $io->section('Componentes');
        $repo = $this->em->getRepository(TravelComponente::class);
        $tocados = 0;

        foreach ($this->em->getRepository(CotizacionCotcomponente::class)->findAll() as $linea) {
            $id = $linea->getComponenteMaestroId();

            if ($id === null || $id === '') {
                continue;
            }

            $maestro = $repo->find($id);
            $actual = $maestro?->getNombreInterno();

            if ($actual === null || $actual === $linea->getNombreInternoSnapshot()) {
                continue;
            }

            ++$tocados;
            $io->text(sprintf('  %-45s → %s', (string) $linea->getNombreInternoSnapshot(), $actual));

            if (!$simula) {
                $linea->setNombreInternoSnapshot($actual);
            }
        }

        return $tocados;
    }

    private function refrescarTarifas(SymfonyStyle $io, bool $simula): int
    {
        $io->section('Tarifas');
        $repo = $this->em->getRepository(TravelTarifa::class);
        $tocados = 0;

        foreach ($this->em->getRepository(CotizacionCottarifa::class)->findAll() as $linea) {
            $id = $linea->getTarifaMaestraId();

            if ($id === null || $id === '') {
                continue;
            }

            $maestro = $repo->find($id);

            if ($maestro === null) {
                continue;
            }

            // ⚠️ Y el NOMBRE PARA EL PROVEEDOR con él. Es el peldaño 1 de resolverDescripcion(),
            // el texto que de verdad lee quien hace el servicio: dejarlo atrás mientras se
            // refresca el interno sería actualizar justo el que él no ve.
            $paraProveedor = trim((string) $maestro->getNombreParaPrestador());

            if ($paraProveedor !== '' && $paraProveedor !== $linea->getNombreParaProveedorSnapshot()) {
                ++$tocados;
                $io->text(sprintf('  proveedor · %-34s → %s', (string) $linea->getNombreParaProveedorSnapshot(), $paraProveedor));

                if (!$simula) {
                    $linea->setNombreParaProveedorSnapshot($paraProveedor);
                }
            }

            $actual = $maestro->getNombreInterno();

            if ($actual === null || $actual === $linea->getNombreInternoSnapshot()) {
                continue;
            }

            ++$tocados;
            $io->text(sprintf('  %-45s → %s', (string) $linea->getNombreInternoSnapshot(), $actual));

            if (!$simula) {
                $linea->setNombreInternoSnapshot($actual);
            }
        }

        return $tocados;
    }
}
