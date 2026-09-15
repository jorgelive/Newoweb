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
 * Las cadenas de la tarjeta «Lo tuyo»: sus acordeones y las etiquetas de sus datos.
 *
 * ── Por qué un comando y no una migración ───────────────────────────────────
 * `UiI18n::$contenido` lleva `#[AutoTranslate(sourceLanguage: 'es')]` y ese listener cuelga de
 * `prePersist`. Un `INSERT` en SQL se lo salta y la cadena nacería **sólo en español**.
 *
 * 🔥 **Y ese hueco no se ve.** El front las pide con `t('clave') || 'respaldo en español'`, así que
 * una clave que no existe no rompe nada: enseña el español a los siete idiomas y parece que
 * funciona. Se descubre mirando la pantalla en otro idioma, o comparando las claves usadas contra
 * la tabla — que es como salieron las tres primeras de aquí abajo.
 *
 * ── Qué entra ───────────────────────────────────────────────────────────────
 * **Tres deudas del 14/09/2026**, del día que las tres secciones de «Lo tuyo» pasaron a ser
 * acordeones: se escribieron claves nuevas en la vista y no se sembraron. Llevaban desde entonces
 * en español para todo el mundo.
 *
 * **Y las etiquetas de los datos del subgrupo.** El localizador salía a pelo —«QYLS7T»— y un
 * código de seis letras sin etiqueta no significa nada para quien viaja: se leía como un
 * identificador interno nuestro, cuando es lo que la aerolínea le pide por teléfono. Igual el
 * número de vuelo.
 *
 * ⚠️ Singular y plural son **dos filas**, no una con un contador: hay idiomas de los siete en los
 * que la frase entera cambia, no sólo la `s`.
 *
 * Idempotente por la clave natural: si ya existe no se toca, ni para reescribir su español.
 *
 *   php bin/console pax:textos:lo-tuyo --dry-run
 *   php bin/console pax:textos:lo-tuyo
 */
#[AsCommand(
    name: 'pax:textos:lo-tuyo',
    description: 'Crea las cadenas UiI18n de la tarjeta «Lo tuyo» que faltan. Idempotente.',
)]
final class PaxCrearTextosLoTuyoCommand extends Command
{
    private const string SCOPE = 'cotizacion';

    /** @var array<string, string> */
    private const array TEXTOS = [
        // Deuda de los acordeones (14/09/2026): se usaban sin existir.
        'cot_mis_grupos' => 'Mis grupos',
        'cot_mis_grupos_vuelos' => 'Mis grupos y vuelos',
        'cot_mis_documentos_faltan' => 'Te falta alguno por mandar.',

        // Las etiquetas de los datos del subgrupo.
        // ⚠️ «PNR» **sólo en los vuelos**: es jerga aérea, y un código de habitación o de grupo
        // no es ninguna reserva aérea. Llamarlo PNR manda a buscar algo que no existe.
        'cot_codigo_reserva' => 'Código de reserva (PNR)',
        'cot_codigo_grupo' => 'Código de grupo',
        'cot_numero_vuelo' => 'Número de vuelo',
        'cot_numeros_vuelo' => 'Números de vuelo',
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
