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
 * La cadena del botón de imprimir de la guía del cliente.
 *
 * ── Por qué un comando y no una migración ───────────────────────────────────
 * Misma razón que {@see PaxCrearTextosCobroCommand}: `UiI18n::$contenido` lleva
 * `#[AutoTranslate(sourceLanguage: 'es')]` y ese listener cuelga de `prePersist`. Un `INSERT`
 * en SQL se lo salta y la cadena nacería sólo en español.
 *
 * ⚠️ **Y aquí eso se ve, no se intuye.** El botón se probó sobre una propuesta en inglés y
 * salió «IMPRIMIR» en medio de «DETAILS» y «SUMMARY»: el respaldo del `||` no falla —devuelve
 * el castellano y la pantalla se pinta entera—, así que un hueco del diccionario sólo se
 * descubre mirando. Es la misma familia de fallo mudo que persigue este proyecto.
 *
 * Idempotente por la clave natural: si ya existe no se toca, ni para reescribir su español.
 *
 *   php bin/console pax:textos:itinerario --dry-run
 *   php bin/console pax:textos:itinerario
 */
#[AsCommand(
    name: 'pax:textos:itinerario',
    description: 'Crea las cadenas UiI18n de la guía del itinerario que faltan. Idempotente.',
)]
final class PaxCrearTextosItinerarioCommand extends Command
{
    private const string SCOPE = 'cotizacion';

    /**
     * ⚠️ **Una sola clave para el botón y su tooltip.** El botón enseña «Imprimir» y el `title`
     * explica que también sirve para guardar en PDF; podrían ser dos cadenas, pero la segunda
     * sólo la lee quien deja el ratón encima —nadie en un móvil, que es donde más se usa— y
     * cada clave de más es una fila más que traducir a siete idiomas para siempre.
     *
     * @var array<string, string>
     */
    private const array TEXTOS = [
        'cot_imprimir' => 'Imprimir',
        // «Lo tuyo»: con quién comparte cada subgrupo. Ver CotizacionFile::$miIdentidad.
        'cot_personas' => 'personas',
        'cot_tu' => 'tú',
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

        $io->table(['Clave', 'Español'], $creadas);

        if ($simular) {
            $io->note(sprintf('Simulación: se crearían %d. Sin --dry-run se escriben y se traducen.', count($creadas)));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d cadena(s) creada(s) y encolada(s) para traducir.', count($creadas)));

        return Command::SUCCESS;
    }
}
