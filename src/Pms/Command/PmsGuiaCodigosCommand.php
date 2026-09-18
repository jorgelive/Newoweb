<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Le pone a cada ficha de la guía su `codigo`, deducido del nombre interno.
 *
 * `Calefactor (general)` → `calefactor` · `Puerta (casa 4)` → `puerta-casa-4`
 *
 * El `(general)` se cae porque no distingue nada —lo es casi todo— y `(casa N)` se conserva porque
 * es lo único que separa siete fichas que se llaman igual. El resultado se lee sin traducir nada,
 * que es para lo que existe el código.
 *
 * ── Sólo rellena lo que falta ───────────────────────────────────────────────
 * Una ficha que ya tenga código **no se toca**: en cuanto un código se escribe en el texto de otra
 * ficha (`{{ ficha: calefactor }}`), cambiarlo rompe ese enlace. Por eso esto no es un renombrador:
 * es el relleno de la primera vez, y el que hace falta cuando alguien crea una ficha por SQL.
 *
 * Si dos nombres distintos dan el mismo código —`Cocina (casa 1)` y `Cocina (casa 1)` duplicada—,
 * se numera el segundo (`cocina-casa-1-2`) y se avisa: es preferible a que el índice único reviente
 * a mitad del recorrido y deje media guía sin código.
 */
#[AsCommand(
    name: 'app:pms:guia:codigos',
    description: 'Rellena el código de las fichas de la guía que no lo tengan. Idempotente.',
)]
final class PmsGuiaCodigosCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');
        $slugger = new AsciiSlugger();

        /** @var list<PmsGuiaItem> $items */
        $items = $this->em->getRepository(PmsGuiaItem::class)->findBy([], ['nombreInterno' => 'ASC']);

        $usados = [];

        foreach ($items as $item) {
            $codigo = $item->getCodigo();

            if ($codigo !== null && $codigo !== '') {
                $usados[$codigo] = true;
            }
        }

        $filas = [];

        foreach ($items as $item) {
            if (($item->getCodigo() ?? '') !== '') {
                continue;
            }

            $base = $this->codigoDe((string) $item->getNombreInterno(), $slugger);
            $codigo = $base;
            $sufijo = 1;

            while (isset($usados[$codigo])) {
                $codigo = sprintf('%s-%d', $base, ++$sufijo);
            }

            if ($codigo !== $base) {
                $io->warning(sprintf('«%s» chocaba con otro código: queda «%s».', $item->getNombreInterno(), $codigo));
            }

            $usados[$codigo] = true;
            $filas[] = [(string) $item->getNombreInterno(), $codigo];

            if (!$simular) {
                $item->setCodigo($codigo);
            }
        }

        if ($filas === []) {
            $io->success('Todas las fichas tienen código.');

            return Command::SUCCESS;
        }

        $io->table(['Ficha', 'Código'], $filas);

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d códigos puestos.', count($filas)));

        return Command::SUCCESS;
    }

    /** `Puerta (casa 4)` → `puerta-casa-4`; `Calefactor (general)` → `calefactor`. */
    private function codigoDe(string $nombreInterno, AsciiSlugger $slugger): string
    {
        $limpio = (string) preg_replace('/\s*\(general\)\s*$/iu', '', $nombreInterno);

        return substr(strtolower($slugger->slug($limpio)->toString()), 0, 50);
    }
}
