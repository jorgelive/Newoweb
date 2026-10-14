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
 * Las instrucciones de la puerta de las siete casitas, con UN formato (14/10/2026).
 *
 * Cada «Puerta (casa N)» estaba escrita a su manera —prosa en la 1 a la 4, pasos en la 5 a la 7—,
 * con erratas y, en la 4, el croquis pegado al texto. Jorge las comparó y pidió que todas fueran
 * como la 7. El formato, en este orden y siempre el mismo:
 *
 * ```
 * 🔑 Cómo se abre      🧭 Cómo llegar (pasos)      🚪 Tu puerta
 * #️⃣ croquis           📸 foto                     🎥 vídeo (si la casa lo tiene)
 * ℹ️ A tener en cuenta (sólo si hace falta)
 * ```
 *
 * ⚠️ **La frase del vídeo va sólo en las casas que lo tienen** (todas menos la 5, Jorge). El
 * marcador `{{ video_ingreso }}` desaparece solo si no hay vídeo cargado, pero la frase que lo
 * presenta no: sin vídeo quedaría huérfana. Si un día la 5 lo tiene, se cambia aquí y se relanza.
 *
 * Por comando y no por SQL: `descripcion` está publicada en siete idiomas y el traductor la
 * regenera al guardar (como `app:pms:guia:corregir-duchas`). El `agente_contenido` sigue la misma
 * estructura —el asistente y la guía no pueden contarlo distinto— y no se traduce.
 *
 * Idempotente: sólo escribe los ítems cuyo texto cambia, para no retraducir lo que ya está.
 *
 * ⚠️ **El traductor no sabe dónde está**: tradujo «al pie de las gradas» como «at the foot of the
 * stands» en los seis idiomas. Lo arregla {@see \App\Service\Translate\CorreccionesDeTraduccion} al
 * traducir, para estas fichas y para cualquier otra; con `--retraducir` se rehacen las siete aunque
 * el español no cambie.
 */
#[AsCommand(
    name: 'app:pms:guia:formato-puertas',
    description: 'Reescribe las instrucciones de la puerta de las siete casitas con un mismo formato.',
    hidden: true,
)]
final class PmsGuiaFormatoPuertasCommand extends Command
{
    /**
     * @var array<int, array{cerradura: string, llegar: list<string>, puerta: string, video: bool, nota: ?string}>
     */
    private const array CASAS = [
        1 => [
            'cerradura' => 'tiene una sola cerradura; gira la llave en sentido antihorario.',
            'llegar' => ['la entrada está en la calle, no en el pasaje.'],
            'puerta' => 'es la puerta verde a nivel de calle, justo a la izquierda del portón.',
            'video' => true,
            'nota' => null,
        ],
        2 => [
            'cerradura' => 'verás dos cerraduras. Usa la de abajo, girando la llave en sentido antihorario. La de arriba te la dejamos abierta: a tu llegada sólo necesitas la de abajo. Tienes llave de las dos por si durante tu estancia prefieres cerrar también la de arriba.',
            'llegar' => ['la entrada está en la calle, no en el pasaje.'],
            'puerta' => 'es de madera, de color verde, a nivel de calle.',
            'video' => true,
            'nota' => null,
        ],
        3 => [
            'cerradura' => 'tiene una sola cerradura; gira la llave en sentido horario.',
            'llegar' => ['Entra al pasaje.', 'Es la segunda puerta, justo al pie de las gradas.'],
            'puerta' => 'de color verde.',
            'video' => true,
            'nota' => null,
        ],
        4 => [
            'cerradura' => 'tiene una sola cerradura; gira la llave en sentido horario. Un truco: tira suavemente de la puerta hacia ti mientras giras la llave y abrirá sin forzar.',
            'llegar' => ['Entra al pasaje.', 'Es la primera puerta a la izquierda, muy cerca de la caja fuerte donde recogiste las llaves.'],
            'puerta' => 'de color verde.',
            'video' => true,
            'nota' => null,
        ],
        5 => [
            'cerradura' => 'tiene una sola cerradura; gira la llave en sentido antihorario.',
            'llegar' => ['Sube las gradas del final del pasaje.', 'Al terminarlas, la tienes de frente.'],
            'puerta' => 'de color verde.',
            'video' => false,
            'nota' => null,
        ],
        6 => [
            'cerradura' => 'al llegar encontrarás dos puertas. Sólo la exterior está cerrada: gira la llave en sentido horario. La interior te la dejamos sin asegurar para que entres directo; si durante tu estancia prefieres cerrarla, las llaves que tienes también sirven.',
            'llegar' => ['Sube las gradas del final del pasaje.', 'Gira a la izquierda.', 'Luego, gira a la derecha.', 'De nuevo a la izquierda, y sube las gradas.'],
            'puerta' => 'la puerta verde de la derecha. Al cruzarla entras al recibidor; la puerta del departamento es la roja, a la izquierda.',
            'video' => true,
            'nota' => 'frente a la puerta roja está el depósito de uso del personal. Por favor, mantén cerradas la puerta verde y la roja.',
        ],
        7 => [
            'cerradura' => 'tiene una sola cerradura; gira la llave en sentido horario.',
            'llegar' => ['Sube las gradas del final del pasaje.', 'Gira a la derecha.'],
            'puerta' => 'la primera del lado derecho, de color rojo.',
            'video' => true,
            'nota' => null,
        ],
    ];


    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué cambiaría, sin escribir.');
        $this->addOption('ver', null, InputOption::VALUE_REQUIRED, 'Imprime los dos textos de esa casa (1-7).');
        $this->addOption('retraducir', null, InputOption::VALUE_NONE, 'Rehace las traducciones aunque el español no cambie, y vuelve a corregirlas.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $ver = $input->getOption('ver');
        // Rehacer aunque el español no cambie: para ver qué dice hoy el traductor.
        $retraducir = (bool) $input->getOption('retraducir');
        $tocados = 0;

        foreach (self::CASAS as $n => $casa) {
            $publicado = $this->publicado($n, $casa);
            $agente = $this->agente($casa);

            if (is_string($ver) && (int) $ver === $n) {
                $io->section(sprintf('Casa %d', $n));
                $io->writeln(['Publicado:', $publicado, '', 'Agente:', $agente]);
            }

            $nombre = sprintf('Puerta (casa %d)', $n);
            $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => $nombre]);

            if (!$item instanceof PmsGuiaItem) {
                $io->warning(sprintf('No encuentro «%s».', $nombre));
                continue;
            }

            $actualEs = null;
            foreach ($item->getDescripcion() ?? [] as $fila) {
                if (($fila['language'] ?? null) === 'es') {
                    $actualEs = $fila['content'] ?? null;
                }
            }

            if ($actualEs === $publicado && $item->getAgenteContenido() === $agente) {
                if ($retraducir && !$seco) {
                    $item->setSobreescribirTraduccion(true);
                }
                $io->text(sprintf('· %s — %s', $nombre, $retraducir ? 'se retraduce' : 'ya está'));
                continue;
            }

            ++$tocados;
            $io->text(sprintf('· %s — %s', $nombre, $seco ? 'se reescribiría' : 'reescrita'));

            if (!$seco) {
                // Sólo el español: el traductor rehace los otros seis al guardar.
                $item->setDescripcion([['language' => 'es', 'content' => $publicado]])
                    ->setAgenteContenido($agente);
            }
        }

        if (!$seco) {
            // Al guardar se traduce, y lo traducido sale ya corregido.
            $this->em->flush();
        }

        $io->success(sprintf('%d puerta(s) %s.', $tocados, $seco ? 'cambiarían' : 'reescritas y retraducidas'));

        return Command::SUCCESS;
    }


    /** @param array{cerradura: string, llegar: list<string>, puerta: string, video: bool, nota: ?string} $casa */
    private function publicado(int $n, array $casa): string
    {
        $llegar = count($casa['llegar']) === 1
            ? sprintf('<p>🧭 <strong>Cómo llegar:</strong> %s</p>', $casa['llegar'][0])
            : '<p>🧭 <strong>Cómo llegar:</strong></p><ol>'
                . implode('', array_map(static fn (string $p): string => sprintf('<li>%s</li>', $p), $casa['llegar']))
                . '</ol>';

        return implode("\n", array_filter([
            sprintf('<p>🔑 <strong>Cómo se abre:</strong> %s</p>', $casa['cerradura']),
            $llegar,
            sprintf('<p>🚪 <strong>Tu puerta:</strong> %s</p>', $casa['puerta']),
            sprintf('<p>#️⃣ <strong>En el croquis es la N.º %d:</strong></p>', $n),
            '<p>{{ croquis }}</p>',
            '<p>📸 <strong>Así se ve</strong> (la flecha la señala):</p>',
            '<p>{{ foto_puerta }}</p>',
            $casa['video'] ? '<p>🎥 <strong>En este vídeo verás el recorrido hasta tu puerta:</strong></p>' : null,
            $casa['video'] ? '<p>{{ video_ingreso }}</p>' : null,
            $casa['nota'] !== null ? sprintf('<p>ℹ️ <strong>A tener en cuenta:</strong> %s</p>', $casa['nota']) : null,
        ]));
    }

    /**
     * Lo mismo, en texto plano y con `{{ unit_name }}`: el asistente no repite «la casa N» a mano.
     *
     * @param array{cerradura: string, llegar: list<string>, puerta: string, video: bool, nota: ?string} $casa
     */
    private function agente(array $casa): string
    {
        $pasos = count($casa['llegar']) === 1
            ? $casa['llegar'][0]
            : implode(' ', array_map(static fn (int $i, string $p): string => sprintf('%d) %s', $i + 1, $p), array_keys($casa['llegar']), $casa['llegar']));

        return implode("\n\n", array_filter([
            '🔑 ' . ucfirst($casa['cerradura']),
            'Cómo llegar a {{ unit_name }}: ' . $pasos,
            'Su puerta: ' . $casa['puerta'],
            'Su puerta va señalada en este croquis: {{ croquis }}',
            $casa['nota'] !== null ? ucfirst($casa['nota']) : null,
        ]));
    }
}
