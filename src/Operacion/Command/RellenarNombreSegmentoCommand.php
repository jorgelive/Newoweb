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
 * Rellena `nombreSegmento` en las líneas de órdenes ya emitidas.
 *
 * ⚠️ **Esto toca un documento congelado, que normalmente no se toca.** Una orden emitida dice lo
 * que decía al emitirse: si el catálogo cambia, se reemite, no se reescribe. La excepción está
 * autorizada expresamente y tiene fecha —29/08/2026— porque el campo no existía y lo que se
 * añade no contradice nada de lo enviado: **suma** el momento del programa a una línea que ya
 * llevaba el encargo.
 *
 * Por eso sólo escribe donde está a NULO. Nunca pisa un valor: si alguien lo editó a mano, esa
 * decisión gana.
 *
 * El dato sale de La Biblia (`OperacionServicio::$nombreSegmento`), enlazada por el soft-link
 * `operacionServicioId`. Si la fila de La Biblia ya no existe —se recalculó, se borró el
 * servicio— la línea se queda sin momento y se cuenta como huérfana: es preferible una línea
 * incompleta a una que afirme algo que no se puede comprobar.
 */
#[AsCommand(
    name: 'app:operacion:rellenar-nombre-segmento',
    description: 'Añade el nombre del segmento a las líneas de órdenes ya emitidas que no lo llevan.',
)]
final class RellenarNombreSegmentoCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué rellenaría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $items = $this->em->getRepository(OperacionOrdenServicioItem::class)
            ->findBy(['nombreSegmento' => null]);

        if ($items === []) {
            $io->success('No hay líneas sin momento: nada que rellenar.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d líneas sin nombre de segmento', count($items)));

        $repoBiblia = $this->em->getRepository(OperacionServicio::class);
        $rellenadas = 0;
        $huerfanas = 0;
        $vacias = 0;

        foreach ($items as $item) {
            $id = $item->getOperacionServicioId();
            $biblia = $id !== null ? $repoBiblia->find($id) : null;

            if ($biblia === null) {
                ++$huerfanas;
                $io->text(sprintf('  <fg=yellow>huérfana</> · %s — su fila de La Biblia ya no existe', $item->getTituloParaProveedor()));
                continue;
            }

            $momento = trim((string) $biblia->getNombreSegmento());

            if ($momento === '') {
                ++$vacias;
                continue;
            }

            ++$rellenadas;
            $io->text(sprintf('  %s · %s → «%s»', $simula ? 'rellenaría' : 'rellenada ', $item->getTituloParaProveedor(), $momento));

            if (!$simula) {
                $item->setNombreSegmento($momento);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->table(
            ['rellenadas', 'sin momento en La Biblia', 'huérfanas'],
            [[$rellenadas, $vacias, $huerfanas]],
        );

        if ($huerfanas > 0) {
            $io->warning('Las huérfanas se quedan como estaban: su origen ya no se puede comprobar.');
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
