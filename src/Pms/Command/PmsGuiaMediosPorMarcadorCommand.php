<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Los croquis, las fotos de puerta y los vídeos dejan de estar pegados: pasan a marcador.
 *
 * ── Qué pasaba ──────────────────────────────────────────────────────────────
 * Las siete fichas «Puerta (casa N)» llevaban el croquis y la foto **incrustados como `<img>`**,
 * con archivos de febrero y marzo. El 12/09/2026 se subió un croquis nuevo por casita a los medios
 * de la unidad y **la guía siguió enseñando los viejos**: nadie los miraba, porque ningún ítem
 * escribía `{{ croquis }}`.
 *
 * Y lo caro no es el despiste: la imagen vive DENTRO del contenido traducido, así que cambiar un
 * croquis a mano son **siete fichas por siete idiomas**. Con el marcador, la URL vive en la casita
 * ({@see \App\Pms\Entity\PmsUnidadMedia}) y subir un archivo nuevo cambia la guía sola.
 *
 * ── Qué se cambia ───────────────────────────────────────────────────────────
 * | Ficha | Antes | Ahora |
 * |---|---|---|
 * | `Puerta (casa 1..7)` | 1.ª imagen pegada | `{{ croquis }}` |
 * | `Puerta (casa 1..7)` | 2.ª imagen pegada | `{{ foto_puerta }}` |
 * | `Puerta (casa 1)` | enlace de YouTube en el texto | `{{ video_ingreso }}` |
 * | `Puerta (casa 2..7)` | (no había vídeo) | `{{ video_ingreso }}` al final |
 * | `Llaves (general)` | foto de la caja pegada (feb.) | `{{ foto_caja_llaves }}` (la del 12/09) |
 *
 * ⚠️ **Poner `{{ video_ingreso }}` donde todavía no hay vídeo es seguro**: un medio que no existe
 * NO pinta un marco de «bloqueado» ni deja la etiqueta a la vista — el marcador se quita y el
 * texto queda como si no estuviera ({@see \App\Pms\Guia\PmsGuiaInterpolador::resolverMediaDeLaCasita()}).
 * Por eso se puede escribir antes de subir los archivos: cada vídeo aparece solo el día que se sube.
 *
 * ── Por qué comando y no SQL ────────────────────────────────────────────────
 * `descripcion` lleva `#[AutoTranslate]`. Se toca **sólo el español** y el listener rehace los otros
 * seis — que es justo lo que hace que esto valga la pena: las traducciones se quedan sin imágenes
 * dentro para siempre.
 *
 * Nace `hidden` porque es de una vez: ver la regla de archivado en `CLAUDE.md`.
 */
#[AsCommand(
    name: 'app:pms:guia:medios-por-marcador',
    description: 'Cambia las imágenes y vídeos pegados de la guía por sus marcadores. Idempotente.',
    hidden: true,
)]
final class PmsGuiaMediosPorMarcadorCommand extends Command
{
    /** Una imagen suelta o envuelta en su `<figure>`: las dos formas conviven en las fichas. */
    private const string PATRON_IMAGEN = '/<figure[^>]*>\s*<img[^>]*>\s*<\/figure>|<img[^>]*>/i';

    /** El párrafo del vídeo pegado de la casa 1, con su enlace de YouTube dentro. */
    private const string PATRON_VIDEO = '/<p[^>]*>\s*📹.*?<\/p>/su';

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
        $filas = [];

        foreach ($this->fichas() as $nombre => $marcadores) {
            $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => $nombre]);

            if (!$item instanceof PmsGuiaItem) {
                $io->error(sprintf('No existe la ficha «%s». No se toca nada.', $nombre));

                return Command::FAILURE;
            }

            $contenido = $item->getDescripcion() ?? [];
            $indice = null;

            foreach ($contenido as $i => $fila) {
                if (($fila['language'] ?? null) === 'es') {
                    $indice = $i;
                    break;
                }
            }

            if ($indice === null) {
                $io->error(sprintf('«%s» no tiene descripción en español.', $nombre));

                return Command::FAILURE;
            }

            $texto = (string) ($contenido[$indice]['content'] ?? '');
            $cambios = [];
            $nuevo = $this->conMarcadores($texto, $marcadores, $cambios);

            if ($cambios === []) {
                $filas[] = [$nombre, '<comment>ya estaba</comment>'];
                continue;
            }

            $filas[] = [$nombre, implode(', ', $cambios)];

            if (!$simular) {
                $contenido[$indice]['content'] = $nuevo;
                // `array_values`: el setter pide una lista y reasignar una clave basta para que
                // deje de serlo a ojos del análisis.
                $item->setDescripcion($contenido);
            }
        }

        $io->table(['Ficha', 'Qué cambia'], $filas);

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Hecho. Los otros seis idiomas los rehace AutoTranslate al guardar.');

        return Command::SUCCESS;
    }

    /**
     * Qué marcador le toca a cada ficha, en el orden en que aparecen sus imágenes.
     *
     * @return array<string, array{imagenes: list<string>, video: ?string}>
     */
    private function fichas(): array
    {
        $fichas = [];

        for ($casa = 1; $casa <= 7; ++$casa) {
            $fichas[sprintf('Puerta (casa %d)', $casa)] = [
                'imagenes' => ['{{ croquis }}', '{{ foto_puerta }}'],
                'video' => '{{ video_ingreso }}',
            ];
        }

        $fichas['Llaves (general)'] = ['imagenes' => ['{{ foto_caja_llaves }}'], 'video' => null];

        return $fichas;
    }

    /**
     * Cambia las imágenes por sus marcadores, en orden, y coloca el del vídeo.
     *
     * El vídeo sustituye al párrafo pegado si lo hay —la casa 1— y si no se añade al final: en las
     * otras seis no había ninguno, y el marcador no molesta mientras el archivo no exista.
     *
     * @param array{imagenes: list<string>, video: ?string} $marcadores
     * @param list<string> $cambios Se rellena con lo que se tocó, para poder enseñarlo.
     * @param-out list<string> $cambios
     */
    private function conMarcadores(string $texto, array $marcadores, array &$cambios): string
    {
        $cambios = [];
        $pendientes = $marcadores['imagenes'];

        foreach ($pendientes as $marcador) {
            if (str_contains($texto, $marcador)) {
                // Ya convertida en una pasada anterior: ni se cuenta ni se vuelve a tocar.
                $pendientes = array_values(array_diff($pendientes, [$marcador]));
            }
        }

        $texto = (string) preg_replace_callback(
            self::PATRON_IMAGEN,
            static function (array $m) use (&$pendientes, &$cambios): string {
                if ($pendientes === []) {
                    // Una tercera imagen es del editor y no de esta receta: se deja como está.
                    return $m[0];
                }

                $marcador = array_shift($pendientes);
                $cambios[] = $marcador;

                return $marcador;
            },
            $texto
        );

        $video = $marcadores['video'];

        if ($video !== null && !str_contains($texto, $video)) {
            $conVideoPegado = (string) preg_replace(self::PATRON_VIDEO, sprintf('<p>%s</p>', $video), $texto, 1, $sustituido);

            if ($sustituido > 0) {
                $texto = $conVideoPegado;
                $cambios[] = $video . ' (sustituye al enlace pegado)';
            } else {
                $texto .= sprintf("\n<p>%s</p>", $video);
                $cambios[] = $video . ' (nuevo)';
            }
        }

        return $texto;
    }
}
