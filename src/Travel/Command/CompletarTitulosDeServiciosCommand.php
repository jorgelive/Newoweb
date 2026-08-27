<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelOrganizacionServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rellena el título público de los servicios que se quedaron sin él.
 *
 * ⚠️ **Un servicio sin `titulo` es INVISIBLE para el huésped aunque esté todo lo demás bien.** La
 * tarjeta del prestador se arma con el título, no con el nombre: `CotizacionCotcomponentePrestador
 * PublicNormalizer::conDatosVivos()` copia `$servicio->getTitulo()` tal cual, y si viene vacío el
 * front no pinta nada. Y no pinta *nada* — ni un hueco, ni un aviso—, que es indistinguible de
 * «este componente no tiene habitación asignada».
 *
 * Pasó con «Habitación premium» del Hotel Terra: asignada en nueve componentes, con tres imágenes
 * cargadas, y sin salir en ninguna parte.
 *
 * Se rellena con el `nombre`, que es lo que alguien ya escribió pensando en cómo se llama esa
 * habitación. **No inventa texto**: si el nombre también está vacío, se salta y lo dice.
 *
 * Idempotente: sólo toca los que tienen el título vacío.
 */
#[AsCommand(
    name: 'app:travel:completar-titulos-servicios',
    description: 'Da título público a los servicios de organización que no lo tienen.',
)]
final class CompletarTitulosDeServiciosCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.')
            ->addOption('solo-visibles', null, InputOption::VALUE_NONE, 'Sólo los de empresas que el cliente puede ver.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $simula   = (bool) $input->getOption('dry-run');
        $visibles = (bool) $input->getOption('solo-visibles');

        $puestos = $sinNombre = 0;

        foreach ($this->em->getRepository(TravelOrganizacionServicio::class)->findAll() as $servicio) {
            $empresa = $servicio->getOrganizacion();

            if ($visibles && $empresa?->isVisibleParaCliente() !== true) {
                continue;
            }

            if ($this->tieneTexto($servicio->getTitulo())) {
                continue;
            }

            $nombre = trim((string) $servicio->getNombre());

            if ($nombre === '') {
                $io->text(sprintf('  ⚠️  %s · servicio sin nombre NI título: no hay de dónde sacarlo', $empresa?->getNombreComercial() ?? '?'));
                ++$sinNombre;
                continue;
            }

            $io->text(sprintf(
                '  %s · %-28s %s',
                $simula ? 'pondría' : 'puesto ',
                mb_substr((string) $empresa?->getNombreComercial(), 0, 28),
                $nombre
            ));

            if (!$simula) {
                $servicio->setTitulo([['language' => 'es', 'content' => $nombre]]);
            }

            ++$puestos;
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('%s: %d · sin nombre del que tirar: %d', $simula ? 'Se pondrían' : 'Puestos', $puestos, $sinNombre));

        if ($simula) {
            $io->warning('SIMULACRO: no se escribió nada.');
        }

        return Command::SUCCESS;
    }

    /** @param array<int, array{language?: string, content?: string}> $i18n */
    private function tieneTexto(array $i18n): bool
    {
        foreach ($i18n as $item) {
            if (trim((string) ($item['content'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
