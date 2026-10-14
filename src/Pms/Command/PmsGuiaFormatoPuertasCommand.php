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
 * stands» (y «Tribüne», «tribunes», «arquibancadas»…) en los seis idiomas, «Al terminarlas, la
 * tienes de frente» como «verás el resultado», y en italiano «gira a la derecha» como «haz la
 * llamada». El español es de Jorge y no se reescribe para complacer al traductor: los arreglos van
 * en {@see self::CORRECCIONES}, que se aplican después de traducir, en cada pasada, conservando
 * el `origenHash` —así el traductor da la fila por buena y no la pisa—. Si un día se retraduce
 * y una corrección ya no encuentra su frase, el comando lo avisa: hay que mirarla otra vez.
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

    /**
     * Frase traducida → frase corregida, por idioma (revisadas el 14/10/2026). Se buscan en las
     * siete puertas: cada frase es lo bastante concreta para no casar donde no toca.
     *
     * Además de los errores de sentido, aquí van las de género: la puerta es femenina en todos
     * los idiomas que lo marcan y el traductor, sin el sustantivo delante, la hizo masculina
     * («Il possède», «Come aprirlo», «la seta aponta para ele»).
     *
     * ⚠️ Cada reemplazo NO puede contener su búsqueda, o cada pasada la volvería a aplicar.
     *
     * @var array<string, array<string, string>>
     */
    private const array CORRECCIONES = [
        'en' => [
            'the second gate, right at the foot of the stands.' => 'the second door, right at the foot of the steps.',
            'When you finish them, you have it right in front of you.' => 'At the top of the steps, your door is right in front of you.',
            'the first one on the right, in red.' => 'the first one on the right, the red one.',
        ],
        'de' => [
            'So öffnen Sie es:</strong> Es hat ein einzelnes Schloss' => 'So öffnen Sie die Tür:</strong> Sie hat ein einzelnes Schloss',
            'nicht in der Passage.' => 'nicht im Durchgang.',
            'am Ende des Ganges' => 'am Ende des Durchgangs',
            'Es ist das zweite Tor, direkt am Fuße der Tribüne.' => 'Es ist die zweite Tür, direkt am Fuß der Treppe.',
            'Wenn du sie fertig hast, hast du es direkt vor dir.' => 'Oben angekommen, befindet sie sich direkt vor Ihnen.',
            'die erste rechts, rot.' => 'die erste rechts, die rote.',
        ],
        'fr' => [
            'Il possède une seule serrure' => 'Elle a une seule serrure',
            'cadenas' => 'serrures',
            'celui du' => 'celle du',
            'celle du haut ouvert :' => 'celle du haut ouverte :',
            'tout au pied des tribunes.' => 'juste au pied des marches.',
            'Une fois que vous les aurez terminés, vous l&#39;aurez juste devant vous.' => 'En haut des marches, elle est juste devant vous.',
            'Tourner à gauche.' => 'Tournez à gauche.',
            'la première à droite, en rouge.' => 'la première à droite, la rouge.',
            '(la flèche le désigne)' => '(la flèche la désigne)',
        ],
        'it' => [
            'Come aprirlo:' => 'Come aprirla:',
            'È il secondo ingresso, proprio ai piedi delle tribune.' => 'È la seconda porta, proprio ai piedi della scalinata.',
            'in fondo al corridoio' => 'in fondo al passaggio',
            'Quando li avrai finiti, li avrai proprio davanti a te.' => 'In cima alle scale, la troverete proprio di fronte.',
            'Girare a ' => 'Girate a ',
            'Poi, fai la chiamata.' => 'Poi girate a destra.',
            'la prima a destra, in rosso.' => 'la prima a destra, quella rossa.',
            '(la freccia lo indica)' => '(la freccia la indica)',
        ],
        'nl' => [
            'Hoe open je het:</strong> Het heeft één slot' => 'Hoe open je hem:</strong> Er zit één slot op',
            'Het is de tweede ingang, helemaal aan de voet van de tribunes.' => 'Het is de tweede deur, direct onderaan de trap.',
            'aan het einde van de gang' => 'aan het einde van de doorgang',
            'Als je ze af hebt, heb je ze recht voor je liggen.' => 'Boven aan de trap zie je hem recht voor je.',
            'de eerste aan de rechterkant, in het rood.' => 'de eerste aan de rechterkant, de rode.',
            'dan gaat hij vanzelf open.' => 'dan gaat hij open zonder te forceren.',
        ],
        'pt' => [
            'É o segundo portão, bem no pé das arquibancadas.' => 'É a segunda porta, bem ao pé da escada.',
            'Quando você terminar, terá o resultado bem na sua frente.' => 'No alto da escada, ela fica bem à sua frente.',
            'a primeira à direita, em vermelho.' => 'a primeira à direita, a vermelha.',
            '(a seta aponta para ele)' => '(a seta aponta para ela)',
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $ver = $input->getOption('ver');
        $tocados = 0;
        /** @var list<PmsGuiaItem> $items */
        $items = [];

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

            $items[] = $item;

            $actualEs = null;
            foreach ($item->getDescripcion() ?? [] as $fila) {
                if (($fila['language'] ?? null) === 'es') {
                    $actualEs = $fila['content'] ?? null;
                }
            }

            if ($actualEs === $publicado && $item->getAgenteContenido() === $agente) {
                $io->text(sprintf('· %s — ya está', $nombre));
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
            // Primero se traduce (al guardar); después se corrige lo que tradujo.
            $this->em->flush();
        }

        [$corregidas, $huerfanas] = $this->corregir($items, $seco);

        if (!$seco) {
            $this->em->flush();
        }

        foreach ($huerfanas as $huerfana) {
            $io->warning(sprintf('Corrección sin efecto, la traducción cambió: %s', $huerfana));
        }

        $io->success(sprintf(
            '%d puerta(s) %s; %d traducción(es) %s.',
            $tocados,
            $seco ? 'cambiarían' : 'reescritas y retraducidas',
            $corregidas,
            $seco ? 'se corregirían' : 'corregidas a mano',
        ));

        return Command::SUCCESS;
    }

    /**
     * Aplica {@see self::CORRECCIONES} sobre lo traducido, sin tocar el `origenHash` de la fila.
     *
     * @param list<PmsGuiaItem> $items
     *
     * @return array{int, list<string>} filas corregidas, y las correcciones que no encontraron su frase
     */
    private function corregir(array $items, bool $seco): array
    {
        $corregidas = 0;
        $vistas = [];

        foreach ($items as $item) {
            $filas = $item->getDescripcion() ?? [];
            $cambio = false;

            foreach ($filas as $i => $fila) {
                $idioma = $fila['language'] ?? '';
                $texto = $fila['content'] ?? null;
                if (!is_string($texto)) {
                    continue;
                }

                $nuevo = $texto;
                foreach (self::CORRECCIONES[$idioma] ?? [] as $buscar => $reemplazo) {
                    if (str_contains($nuevo, $buscar) || str_contains($nuevo, $reemplazo)) {
                        $vistas[$idioma . "\0" . $buscar] = true;
                    }
                    $nuevo = str_replace($buscar, $reemplazo, $nuevo);
                }

                if ($nuevo !== $texto) {
                    $filas[$i]['content'] = $nuevo;
                    $cambio = true;
                    ++$corregidas;
                }
            }

            if ($cambio && !$seco) {
                // Sin esto el listener podría volver a traducir encima de lo corregido.
                $item->setEjecutarTraduccion(false)->setDescripcion($filas);
            }
        }

        $huerfanas = [];
        foreach (self::CORRECCIONES as $idioma => $pares) {
            foreach (array_keys($pares) as $buscar) {
                if (!isset($vistas[$idioma . "\0" . $buscar])) {
                    $huerfanas[] = sprintf('[%s] %s', $idioma, $buscar);
                }
            }
        }

        return [$corregidas, $huerfanas];
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
