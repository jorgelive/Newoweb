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

    /**
     * ⚠️ **Una palabra suelta se traduce mal**, como ya avisa la guía para los títulos cortos
     * (docs/PmsGuiaHuesped.md §7 bis): sin contexto, «Siguiente» salió «Following», «Seguindo»,
     * «Im Folgenden» y «Volgend». Van a mano, conservando el `origenHash` que dejó el traductor —es
     * lo que le dice que la fila está al día—. Se reaplican en cada pasada.
     *
     * @var array<string, array<string, string>>
     */
    private const array A_MANO = [
        'gui_siguiente' => [
            'en' => 'Next', 'pt' => 'Próximo', 'fr' => 'Suivant', 'it' => 'Avanti', 'de' => 'Weiter', 'nl' => 'Volgende',
        ],
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

        if ($creadas !== []) {
            $io->table(['clave', 'es'], $creadas);
        }

        if ($simular) {
            $io->note('--dry-run: no se escribió nada.');

            return Command::SUCCESS;
        }

        // Primero se crean (y el listener traduce); después se fijan las que van a mano.
        $this->em->flush();
        $fijadas = $this->fijarAMano($repo);
        $this->em->flush();

        $io->success(sprintf('%d cadena(s) creada(s); %d traducción(es) fijada(s) a mano.', count($creadas), $fijadas));

        return Command::SUCCESS;
    }

    /** @param \Doctrine\ORM\EntityRepository<UiI18n> $repo */
    private function fijarAMano(\Doctrine\ORM\EntityRepository $repo): int
    {
        $fijadas = 0;

        foreach (self::A_MANO as $clave => $porIdioma) {
            $texto = $repo->find($clave);
            if (!$texto instanceof UiI18n) {
                continue;
            }

            $contenido = $texto->getContenido();
            $hecho = [];

            foreach ($contenido as $i => $fila) {
                $idioma = $fila['language'] ?? '';
                if (isset($porIdioma[$idioma]) && ($fila['content'] ?? null) !== $porIdioma[$idioma]) {
                    $contenido[$i]['content'] = $porIdioma[$idioma];
                    ++$fijadas;
                }
                $hecho[$idioma] = true;
            }

            foreach ($porIdioma as $idioma => $valor) {
                if (!isset($hecho[$idioma])) {
                    $contenido[] = ['language' => $idioma, 'content' => $valor];
                    ++$fijadas;
                }
            }

            $texto->setContenido($contenido);
            // Sin esto, el listener volvería a traducir encima de lo que acabamos de fijar.
            $texto->setEjecutarTraduccion(false);
        }

        return $fijadas;
    }
}
