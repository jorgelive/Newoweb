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
 * Las cadenas del disparador de la tarjeta de precio y del adelanto de opciones.
 *
 * ── Por qué un comando y no una migración ───────────────────────────────────
 * Misma razón que {@see PaxCrearTextosItinerarioCommand}: `UiI18n::$contenido` lleva
 * `#[AutoTranslate(sourceLanguage: 'es')]` y ese listener cuelga de `prePersist`. Un `INSERT` en
 * SQL se lo salta y la cadena nacería sólo en español — y el respaldo del `||` en `pax` no
 * falla, así que el hueco sólo se ve mirando la pantalla en otro idioma.
 *
 * ── Por qué CUATRO variantes del mismo botón ────────────────────────────────
 * Porque decían una sola cosa y casi siempre era mentira. `cot_ver_precios_perfil` se enseñaba
 * hubiera un perfil o cinco: con uno solo prometía una comparación inexistente, y quien lo
 * pulsaba encontraba el mismo precio que ya estaba viendo. Un control que miente sobre su
 * contenido se deja de pulsar, y detrás estaban las opciones.
 *
 * Son cuatro filas más que traducir a siete idiomas para siempre; se aceptan porque la
 * alternativa —una frase genérica que valga para todo, «ver más»— no dice qué hay dentro, que
 * es justamente lo que hace falta para decidir si merece la pena abrirlo.
 *
 * Idempotente por la clave natural: si ya existe no se toca, ni para reescribir su español.
 *
 *   php bin/console pax:textos:panel-precio --dry-run
 *   php bin/console pax:textos:panel-precio
 */
#[AsCommand(
    name: 'pax:textos:panel-precio',
    description: 'Crea las cadenas UiI18n de la tarjeta de precio que faltan. Idempotente.',
)]
final class PaxCrearTextosPanelPrecioCommand extends Command
{
    private const string SCOPE = 'cotizacion';

    /** @var array<string, string> */
    private const array TEXTOS = [
        'cot_ver_precios_perfil'     => 'Toca para ver precios por perfil',
        'cot_ver_perfiles_y_opciones' => 'Toca para ver precios por perfil y opciones',
        'cot_ver_detalle_opcion'     => 'Toca para ver el detalle de la opción',
        'cot_ver_detalle_opciones'   => 'Toca para ver el detalle de las opciones',
        'cot_opcion_mas'             => 'opción más',
        'cot_opciones_mas'           => 'opciones más',
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
