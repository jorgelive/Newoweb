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
 * Las cadenas de navegación de la guía del huésped (`pax/src/views/huesped/HuespedGuiaView.vue`).
 * Por comando y no por SQL: se traducen a los siete idiomas al guardar (`#[AutoTranslate]`).
 * Idempotente.
 *
 * `gui_siguiente` (14/10/2026): el pie de cada ítem lleva al siguiente de su sección —ubicación,
 * llaves, puerta…— sin volver a la lista.
 *
 *   php bin/console pax:textos:guia --dry-run
 *   php bin/console pax:textos:guia
 */
#[AsCommand(
    name: 'pax:textos:guia',
    description: 'Crea las cadenas UiI18n de navegación de la guía del huésped. Idempotente.',
)]
final class PaxCrearTextosGuiaCommand extends Command
{
    private const string SCOPE = 'guia';

    private const array TEXTOS = [
        'gui_siguiente' => 'Siguiente',
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

        foreach (self::TEXTOS as $clave => $es) {
            if ($repo->find($clave) !== null) {
                continue;
            }

            $creadas[] = [$clave, $es];

            if (!$simular) {
                $this->em->persist((new UiI18n())
                    ->setId($clave)
                    ->setScope(self::SCOPE)
                    // El listener rellena los otros idiomas al persistir; aquí sólo va el origen.
                    ->setContenido([['language' => 'es', 'content' => $es]]));
            }
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
