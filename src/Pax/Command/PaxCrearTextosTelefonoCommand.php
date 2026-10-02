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
 * Las cadenas de la tarjeta «¿Nos dejas tu WhatsApp?» de la página de la reserva
 * (`pax/src/components/huesped/PedirTelefono.vue`). Por comando y no por SQL: se traducen a los
 * siete idiomas al guardar (`#[AutoTranslate]`). Idempotente.
 *
 * ⚠️ **La tarjeta no se enseña hasta que existe `res_tel_titulo`.** Sin sus textos saldría en
 * español a un francés; así el código puede desplegarse antes de que se aprueben.
 *
 *   php bin/console pax:textos:telefono --dry-run
 *   php bin/console pax:textos:telefono
 */
#[AsCommand(
    name: 'pax:textos:telefono',
    description: 'Crea las cadenas UiI18n de la tarjeta que pide el WhatsApp al huésped. Idempotente.',
)]
final class PaxCrearTextosTelefonoCommand extends Command
{
    private const string SCOPE = 'reserva';

    private const array TEXTOS = [
        'res_tel_titulo' => '¿Nos dejas tu WhatsApp?',
        'res_tel_texto' => 'Por ahí te enviamos la guía de llegada y las indicaciones para entrar, y te respondemos más rápido.',
        'res_tel_ejemplo' => 'Con el código de tu país, por ejemplo +33 6 12 34 56 78',
        'res_tel_guardar' => 'Guardar',
        'res_tel_gracias' => '¡Gracias! Te escribiremos por WhatsApp.',
        'res_tel_invalido' => 'Revisa el número: escríbelo con el código de tu país (+…).',
        'res_tel_error' => 'No pudimos guardarlo. Inténtalo de nuevo en un momento.',
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
