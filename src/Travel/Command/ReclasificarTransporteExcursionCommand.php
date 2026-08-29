<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pasa a `TRANSPORTE_EXCURSION` los transportes que en realidad son excursiones privadas.
 *
 * No los busca por nombre: los busca por **cómo están enganchados**. Un transporte que nombra una
 * ruta cuelga de un segmento que dice a dónde va —«Traslado a la estación de Ollantaytambo»—;
 * éstos cuelgan de un ANCLA que no habla de la fila: «Recojo en el Hotel (Servicio Privado)»,
 * idéntica en todas las excursiones.
 *
 * ⚠️ El criterio de detección es prosa —el segmento empieza por «Recojo» o «Inicio de Excursión»—
 * y por eso el comando **no reclasifica solo**: enseña lo que encontró y hay que confirmarlo con
 * `--aplicar`. La lista de hoy son cuatro y se revisó una a una. Si mañana aparece un quinto, que
 * lo vea una persona antes de moverlo.
 *
 * Por qué importa: `mandaElSegmento()` es true para todo `TRANSPORTE`, así que en cuanto se
 * vendiera una de estas excursiones el proveedor habría leído «Recojo en el Hotel» en grande y el
 * encargo debajo.
 */
#[AsCommand(
    name: 'app:travel:reclasificar-transporte-excursion',
    description: 'Detecta transportes que son excursiones privadas y los pasa a su tipo propio.',
)]
final class ReclasificarTransporteExcursionCommand extends Command
{
    /** @var list<string> */
    private const ANCLAS = ['Recojo', 'Inicio de Excursión'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('aplicar', null, InputOption::VALUE_NONE, 'Escribe. Sin esta opción sólo enseña.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $aplica = (bool) $input->getOption('aplicar');

        $candidatos = [];

        foreach ($this->em->getRepository(TravelComponente::class)->findBy(['tipo' => ComponenteTipoEnum::TRANSPORTE]) as $componente) {
            foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $componente]) as $rel) {
                $segmento = trim((string) $rel->getSegmento()?->getNombreInterno());

                foreach (self::ANCLAS as $ancla) {
                    if (str_starts_with($segmento, $ancla)) {
                        $candidatos[(string) $componente->getId()] = [$componente, $segmento];
                        break 2;
                    }
                }
            }
        }

        if ($candidatos === []) {
            $io->success('Ningún transporte cuelga de un ancla de excursión.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d transportes que parecen excursiones', count($candidatos)));

        foreach ($candidatos as [$componente, $segmento]) {
            $io->text(sprintf('  %-28s ← %s', $componente->getNombreInterno() ?? '', $segmento));

            if ($aplica) {
                $componente->setTipo(ComponenteTipoEnum::TRANSPORTE_EXCURSION);
            }
        }

        if ($aplica) {
            $this->em->flush();
            $io->success('Reclasificados. Ahora manda el componente y el segmento no se muestra.');
        } else {
            $io->warning('Sólo mostrado. Revisa la lista y vuelve con --aplicar.');
        }

        return Command::SUCCESS;
    }
}
