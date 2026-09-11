<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lee o reescribe el cuerpo EN ESPAÑOL de una plantilla, y deja que `AutoTranslate` rehaga el resto.
 *
 * ## Por qué existe
 *
 * Porque la reformulación de las plantillas al huésped (§18.b de `docs/Mensajeria.md`) es
 * precisamente eso, cuerpo a cuerpo, y el panel no se puede usar desde la consola. Y porque la
 * alternativa rápida —un `UPDATE` en SQL— se salta `AutoTranslate`: el español cambiaría y los
 * otros seis idiomas seguirían diciendo lo viejo, sin que nada fallara. Regla de CLAUDE.md.
 *
 * ## Se parte del texto VIVO, no de uno guardado en el código
 *
 * `--exportar` saca lo que hay ahora en la base. Así se edita encima de lo último que tocó
 * alguien en el panel, en vez de pisarlo con una versión antigua.
 *
 * ```
 * bin/console msg:plantilla:cuerpo politicas_booking --exportar > /tmp/cuerpo.txt
 * # …editar /tmp/cuerpo.txt…
 * bin/console msg:plantilla:cuerpo politicas_booking --desde=/tmp/cuerpo.txt --dry-run
 * bin/console msg:plantilla:cuerpo politicas_booking --desde=/tmp/cuerpo.txt
 * ```
 *
 * ## Lo que NO hace: el cuerpo de Meta
 *
 * Meta no deja editar de verdad una plantilla aprobada: cambiarla es una rotación (`x_v2`, subir,
 * repuntar), §18. Editarla aquí dejaría el texto local distinto del aprobado y el siguiente push
 * intentaría una edición que Meta no acepta. Por eso sólo `beds24` y `link`, que son nuestros.
 */
#[AsCommand(
    name: 'msg:plantilla:cuerpo',
    description: 'Exporta o reescribe el cuerpo en español de una plantilla (Beds24 o enlace).',
)]
final class MessageCuerpoPlantillaCommand extends Command
{
    /** Canal → [getter, setter]. Sin Meta a propósito: ver el docblock. */
    private const array CANALES = [
        'beds24' => ['getBeds24Tmpl', 'setBeds24Tmpl'],
        'link' => ['getWhatsappLinkTmpl', 'setWhatsappLinkTmpl'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Código de la plantilla.')
            ->addOption('canal', null, InputOption::VALUE_REQUIRED, 'beds24 | link', 'beds24')
            ->addOption('exportar', null, InputOption::VALUE_NONE, 'Imprime el cuerpo en español tal cual, para editarlo.')
            ->addOption('desde', null, InputOption::VALUE_REQUIRED, 'Archivo con el nuevo cuerpo en español.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña el cambio sin guardarlo.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = (string) $input->getArgument('code');
        $canal = (string) $input->getOption('canal');

        if (!isset(self::CANALES[$canal])) {
            $io->error(sprintf(
                'Canal «%s». Sólo beds24 y link: el cuerpo de Meta se cambia rotando la plantilla (§18).',
                $canal
            ));

            return Command::FAILURE;
        }

        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $code]);

        if (!$plantilla instanceof MessageTemplate) {
            $io->error(sprintf('No existe ninguna plantilla con el código «%s».', $code));

            return Command::FAILURE;
        }

        [$getter, $setter] = self::CANALES[$canal];
        $bloque = $plantilla->$getter() ?? [];
        $cuerpos = is_array($bloque['body'] ?? null) ? $bloque['body'] : [];
        $indice = $this->indiceDelEspanol($cuerpos);
        $actual = $indice !== null ? (string) ($cuerpos[$indice]['content'] ?? '') : '';

        // ── Exportar: el texto crudo y nada más, para poder redirigirlo a un archivo ──
        if ($input->getOption('exportar')) {
            $output->write($actual);

            return Command::SUCCESS;
        }

        $ruta = $input->getOption('desde');

        if (!is_string($ruta) || $ruta === '') {
            $io->error('Di qué hacer: --exportar para leerlo, o --desde=<archivo> para reescribirlo.');

            return Command::FAILURE;
        }

        $nuevo = @file_get_contents($ruta);

        if ($nuevo === false) {
            $io->error(sprintf('No se pudo leer «%s».', $ruta));

            return Command::FAILURE;
        }

        // El editor suele dejar un salto al final; el cuerpo guardado no lo lleva.
        $nuevo = rtrim($nuevo, "\n");

        if (trim($nuevo) === '') {
            $io->error('El archivo está vacío: un cuerpo en blanco dejaría la plantilla sin texto en este canal.');

            return Command::FAILURE;
        }

        if ($nuevo === $actual) {
            $io->note('El texto es idéntico al que ya hay: no se toca nada.');

            return Command::SUCCESS;
        }

        $this->ensenarDiferencia($io, $actual, $nuevo);

        if ($input->getOption('dry-run')) {
            $io->note('Simulación: no se ha guardado nada.');

            return Command::SUCCESS;
        }

        // Una foto de los otros idiomas, para decir después cuáles se rehicieron de verdad.
        $antes = $this->porIdioma($cuerpos);

        if ($indice !== null) {
            $cuerpos[$indice]['content'] = $nuevo;
        } else {
            $cuerpos[] = ['language' => 'es', 'content' => $nuevo];
        }

        $bloque['body'] = $cuerpos;
        // Un array NUEVO en el setter: es lo que hace que Doctrine vea el cambio, dispare
        // `preUpdate` y, con él, `AutoTranslate` — que rehace cada idioma cuyo `origenHash` ya no
        // case con este español.
        $plantilla->$setter($bloque);
        $this->em->flush();

        $despues = $this->porIdioma($plantilla->$getter()['body'] ?? []);
        $filas = [];

        foreach ($despues as $idioma => $texto) {
            if ($idioma === 'es') {
                continue;
            }

            $filas[] = [
                strtoupper($idioma),
                ($antes[$idioma] ?? null) === $texto ? '<error>sin cambios</error>' : '<info>retraducido</info>',
            ];
        }

        $io->table(['Idioma', 'Resultado'], $filas);

        // Un idioma que no se movió con el español cambiado es una traducción desfasada: o se
        // cayó Google, o esa fila está marcada como curada a mano. Las dos cosas hay que verlas.
        $sinMover = array_filter($filas, static fn (array $f): bool => str_contains($f[1], 'sin cambios'));

        if ($sinMover !== []) {
            $io->warning('Algún idioma no se retradujo. Revisa el log de AutoTranslate o si esa fila es manual.');

            return Command::FAILURE;
        }

        $io->success(sprintf('«%s» (%s) actualizada en los %d idiomas.', $code, $canal, count($despues)));

        return Command::SUCCESS;
    }

    /** @param list<array<string, mixed>> $cuerpos */
    private function indiceDelEspanol(array $cuerpos): ?int
    {
        foreach ($cuerpos as $i => $cuerpo) {
            if (($cuerpo['language'] ?? null) === 'es') {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $cuerpos
     * @return array<string, string>
     */
    private function porIdioma(array $cuerpos): array
    {
        $mapa = [];

        foreach ($cuerpos as $cuerpo) {
            if (is_array($cuerpo) && is_string($cuerpo['language'] ?? null)) {
                $mapa[$cuerpo['language']] = (string) ($cuerpo['content'] ?? '');
            }
        }

        return $mapa;
    }

    /** Líneas quitadas y añadidas, en el orden del texto. Suficiente para revisar un cuerpo. */
    private function ensenarDiferencia(SymfonyStyle $io, string $actual, string $nuevo): void
    {
        $viejas = explode("\n", $actual);
        $nuevas = explode("\n", $nuevo);

        $io->section('Cambio en el español');

        foreach (array_diff($viejas, $nuevas) as $linea) {
            $io->writeln('<fg=red>- ' . $linea . '</>');
        }

        foreach (array_diff($nuevas, $viejas) as $linea) {
            $io->writeln('<fg=green>+ ' . $linea . '</>');
        }

        $io->newLine();
    }
}
