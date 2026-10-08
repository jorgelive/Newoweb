<?php

declare(strict_types=1);

namespace App\Pax\Command;

use App\Pax\Entity\UiI18n;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Las cadenas del precio «desde» de las tarjetas del catálogo (07/10/2026).
 *
 * El «desde» pasó a ser el precio CALCULADO por pasajero salvo override
 * (`TourTarjetaResolver::preciosDesdeEfectivos()`). Con una sola clase ya no hay perfil que
 * nombrar: la tarjeta dice «por persona». Y si el precio depende del tamaño del grupo (Punta Cana,
 * base de 60), lo dice también, o quien viaja solo lee un precio que no le corresponde.
 *
 * Comando y no migración por lo de siempre: `UiI18n::$contenido` lleva `#[AutoTranslate]` y un
 * INSERT en SQL dejaría la cadena sólo en español. `{{ n }}` es un marcador: lo protege
 * `ProtectorDeMarcadores` al traducir y lo rellena `maestroStore.t()`.
 *
 * Idempotente por la clave natural.
 *
 *   php bin/console pax:textos:precio-desde --dry-run
 *   php bin/console pax:textos:precio-desde
 */
#[AsCommand(
    name: 'pax:textos:precio-desde',
    description: 'Crea las cadenas UiI18n del precio «desde» del catálogo que faltan. Idempotente.',
)]
final class PaxCrearTextosPrecioDesdeCommand extends Command
{
    private const string SCOPE = 'catalogo';

    /** @var array<string, string> */
    private const array TEXTOS = [
        'cat_por_persona' => 'por persona',
        'cat_base_pax'    => 'calculado para {{ n }} pasajeros',
    ];

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

        $repo = $this->em->getRepository(UiI18n::class);
        $creadas = [];
        $existentes = [];

        foreach (self::TEXTOS as $clave => $es) {
            if ($repo->find($clave) !== null) {
                $existentes[] = $clave;
                continue;
            }

            $creadas[] = [$clave, $es];

            if ($simular) {
                continue;
            }

            $texto = (new UiI18n())
                ->setId($clave)
                ->setScope(self::SCOPE)
                // El listener rellena los otros idiomas al persistir; aquí sólo va el origen.
                ->setContenido([['language' => 'es', 'content' => $es]]);

            $this->em->persist($texto);
        }

        if ($existentes !== []) {
            $io->writeln(sprintf('Ya existían (no se tocan): %s', implode(', ', $existentes)));
        }

        if ($creadas === []) {
            $io->success('No falta ninguna cadena.');

            return Command::SUCCESS;
        }

        $io->table(['clave', 'es'], $creadas);

        if ($simular) {
            $io->note('--dry-run: no se escribió nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d cadena(s) creada(s) y traducida(s).', count($creadas)));

        return Command::SUCCESS;
    }
}
