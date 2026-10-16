<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Cotizacion\Finanzas\CotizacionEnlaceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Da su enlace del adelanto a las propuestas que YA estaban publicadas (16/10/2026).
 *
 * El enlace nace al publicar (`CotizacionPublicadaEnlaceListener`), así que las propuestas
 * publicadas antes de existir ese disparador se quedaron sin él. Esto llama a la misma
 * `CotizacionEnlaceService::emitirAdelanto()` que el disparador: mismas reglas —que se ofrezca,
 * que pida adelanto, nada pagado, no en grupo—, idempotente (reutiliza el vivo por el mismo
 * importe) y en tramos si no cabe en el tope de la pasarela.
 *
 *   php bin/console app:cotizacion:enlaces:emitir-publicadas --dry-run
 *   php bin/console app:cotizacion:enlaces:emitir-publicadas
 */
#[AsCommand(
    name: 'app:cotizacion:enlaces:emitir-publicadas',
    description: 'Emite el enlace del adelanto de las propuestas ya publicadas que se ofrecen. Idempotente.',
)]
final class CotizacionEmitirEnlacesPublicadasCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CotizacionEnlaceService $enlaces,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo lista las propuestas que se mirarían');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');

        /** @var list<Cotizacion> $candidatas */
        $candidatas = $this->em->getRepository(Cotizacion::class)->findBy([
            'publicado' => true,
            'estado' => [CotizacionEstadoEnum::PENDIENTE, CotizacionEstadoEnum::ENVIADO],
        ]);

        $filas = [];
        foreach ($candidatas as $cotizacion) {
            $file = $cotizacion->getFile();
            if ($file === null) {
                continue; // Un catálogo: no se vende directamente.
            }

            $emitidos = $simular ? [] : $this->enlaces->emitirAdelanto($cotizacion);
            $filas[] = [
                $file->getLocalizador(),
                'v' . $cotizacion->getPropuesta(),
                $cotizacion->getEstado()->value,
                $cotizacion->getTotalVenta(),
                $simular ? '—' : ($emitidos === [] ? 'no procede (ver log)' : sprintf('%d enlace(s): %s', \count($emitidos), implode(' + ', array_map(static fn ($e) => $e->getMontoNeto(), $emitidos)))),
            ];
        }

        $io->table(['expediente', 'propuesta', 'estado', 'total USD', 'enlace'], $filas);
        $io->success($simular ? '--dry-run: no se emitió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
