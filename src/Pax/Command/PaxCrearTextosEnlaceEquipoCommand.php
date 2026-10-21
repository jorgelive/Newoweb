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
 * Las cadenas de la página de una ficha por enlace del equipo
 * (`pax/src/views/huesped/PaxGuiaEnlaceView.vue`, `/acceso/{token}`). Por comando y no por SQL: se
 * traducen a los siete idiomas al guardar (`#[AutoTranslate]`). Idempotente.
 *
 * La vista lleva el español de respaldo, así que puede desplegarse antes que esto: hasta que
 * corra, un francés lee esas tres frases en español y la ficha (que sí viene traducida) en francés.
 *
 *   php bin/console pax:textos:enlace-equipo --dry-run
 *   php bin/console pax:textos:enlace-equipo
 */
#[AsCommand(
    name: 'pax:textos:enlace-equipo',
    description: 'Crea las cadenas UiI18n de la página de una ficha por enlace del equipo. Idempotente.',
)]
final class PaxCrearTextosEnlaceEquipoCommand extends Command
{
    private const string SCOPE = 'guia';

    private const array TEXTOS = [
        // `{{ fecha }}`: hasta cuándo vale el enlace, ya formateada en su idioma.
        'enl_privado' => 'Este enlace es sólo para ti y vale hasta el {{ fecha }}. No lo compartas.',
        'enl_no_disponible' => 'Este enlace ya no está disponible. Escríbenos y te enviamos uno nuevo.',
        'enl_error_red' => 'No pudimos cargar las instrucciones. Inténtalo en un momento.',
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
