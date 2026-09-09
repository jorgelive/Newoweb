<?php

declare(strict_types=1);

namespace App\Operacion\Command;

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Service\OperacionOrdenDocumento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imprime, tal cual, el documento que se le manda al proveedor.
 *
 * ── Para qué ────────────────────────────────────────────────────────────────
 *
 * `OperacionOrdenDocumento::linea()` compone un texto con doce datos y varias reglas de «esto se
 * calla si repite a aquello». Cualquier cambio ahí tiene que dar **el mismo texto byte a byte**
 * para las órdenes que ya se mandaron, y a ojo no se ve un espacio de más ni un separador movido.
 *
 * Se escribió al refactorizar esa composición para poder comparar antes y después:
 *
 *   php bin/console app:operacion:ver-documento OS-20260827-256 > /tmp/antes.txt
 *   …cambios…
 *   php bin/console app:operacion:ver-documento OS-20260827-256 | diff /tmp/antes.txt -
 *
 * Sirve igual para responder «¿qué le llegó exactamente al proveedor?» sin abrir el chat.
 *
 * Sólo lee. El enlace se pasa fijo para que dos ejecuciones sean comparables.
 */
#[AsCommand(
    name: 'app:operacion:ver-documento',
    description: 'Imprime el documento que se le manda al proveedor, para comparar salidas.'
)]
final class OperacionVerDocumentoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OperacionOrdenDocumento $documento,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('numero', InputArgument::OPTIONAL, 'Número de OS. Sin él, la más reciente.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->em->getRepository(OperacionOrdenServicio::class);
        $numero = $input->getArgument('numero');

        $orden = is_string($numero) && $numero !== ''
            ? $repo->findOneBy(['numeroOs' => $numero])
            : $repo->findOneBy([], ['createdAt' => 'DESC']);

        if (!$orden instanceof OperacionOrdenServicio) {
            $output->writeln('<error>No se encontró la orden.</error>');

            return Command::FAILURE;
        }

        $output->writeln('═══ ' . $orden->getNumeroOs() . ' ═══');

        foreach ($this->documento->para($orden, 'https://pax.openperu.pe/orden/TOKEN-FIJO') as $clave => $valor) {
            $output->writeln('── ' . $clave);
            $output->writeln((string) $valor);
        }

        return Command::SUCCESS;
    }
}
