<?php

declare(strict_types=1);

namespace App\Operacion\Command;

use App\Operacion\Entity\OperacionOrdenServicioItem;
use App\Operacion\Entity\OperacionServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vuelve a copiar los textos de La Biblia a las líneas de órdenes YA EMITIDAS.
 *
 * ⚠️⚠️ **Esto reescribe un documento congelado, y normalmente NO se hace.** Una orden emitida
 * dice lo que decía al emitirse; si el catálogo cambia, se anula y se reemite. Aquí la excepción
 * está autorizada y tiene una condición concreta, del 29/08/2026: **ninguna de esas órdenes se
 * había enviado todavía**. Estaban emitidas en el sistema pero no habían salido a ningún
 * proveedor, así que no hay nadie con una versión distinta en la mano.
 *
 * **Esa condición es la que lo hace legítimo, y no volverá a cumplirse sola.** Antes de correr
 * esto otra vez hay que comprobar que sigue siendo cierta; si una sola orden salió, lo correcto
 * es anular y reemitir, no reescribir.
 *
 * El orden importa: primero `app:cotizacion:refrescar-nombres-maestros`, luego
 * `operacion:resincronizar --todas` —que recalcula La Biblia— y sólo entonces esto, que copia de
 * ella. Al revés se copiarían los nombres viejos.
 */
#[AsCommand(
    name: 'app:operacion:refrescar-ordenes-emitidas',
    description: 'Recopia los textos de La Biblia a las órdenes ya emitidas. Sólo si ninguna se ha enviado.',
)]
final class RefrescarOrdenesEmitidasCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué cambiaría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $io->warning('Reescribe documentos emitidos. Sólo es legítimo si NINGUNA orden se ha enviado todavía.');

        $repoBiblia = $this->em->getRepository(OperacionServicio::class);
        $tocadas = 0;
        $huerfanas = 0;

        foreach ($this->em->getRepository(OperacionOrdenServicioItem::class)->findAll() as $item) {
            $id = $item->getOperacionServicioId();
            $biblia = $id !== null ? $repoBiblia->find($id) : null;

            if ($biblia === null) {
                ++$huerfanas;
                $io->text(sprintf('  <fg=yellow>huérfana</> · %s — su fila de La Biblia ya no existe', $item->getTituloParaProveedor()));
                continue;
            }

            $cambios = [];

            foreach ([
                'nombreComponente' => [$item->getNombreComponente(), $biblia->getNombreComponente()],
                'nombreSegmento' => [$item->getNombreSegmento(), $biblia->getNombreSegmento()],
                'contextoServicio' => [$item->getContextoServicio(), $biblia->getContextoServicio()],
                'descripcion' => [$item->getDescripcion(), $biblia->getDescripcionServicio()],
                // Sin él, una orden vieja seguiría poniendo el componente en grande aunque su
                // tipo diga que manda el segmento. Ver ComponenteTipoEnum::mandaElSegmento().
                'tipoComponente' => [$item->getTipoComponente(), $biblia->getTipoComponente()],
                'ordenItinerario' => [$item->getOrdenItinerario(), $biblia->getOrdenItinerario()],
            ] as $campo => [$antes, $ahora]) {
                // Un vacío en La Biblia no borra lo que la orden ya dice: sería perder texto por
                // un recálculo incompleto, que es peor que quedarse con el nombre viejo.
                if ($ahora === null || trim((string) $ahora) === '' || $antes === $ahora) {
                    continue;
                }

                $cambios[$campo] = [$antes, $ahora];
            }

            if ($cambios === []) {
                continue;
            }

            ++$tocadas;

            foreach ($cambios as $campo => [$antes, $ahora]) {
                $io->text(sprintf('  %-18s %-45s → %s', $campo, (string) $antes, (string) $ahora));
            }

            if ($simula) {
                continue;
            }

            foreach ($cambios as $campo => [, $ahora]) {
                match ($campo) {
                    'nombreComponente' => $item->setNombreComponente($ahora),
                    'nombreSegmento' => $item->setNombreSegmento($ahora),
                    'contextoServicio' => $item->setContextoServicio($ahora),
                    'descripcion' => $item->setDescripcion((string) $ahora),
                    'tipoComponente' => $item->setTipoComponente($ahora),
                    'ordenItinerario' => $item->setOrdenItinerario((int) $ahora),
                };
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->table(['líneas actualizadas', 'huérfanas'], [[$tocadas, $huerfanas]]);

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
