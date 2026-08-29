<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelSegmento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le pone texto de itinerario a los segmentos de vuelo, en vez del marcador de posición.
 *
 * ## ⚠️ Ni nostalgia ni despedidas
 *
 * El texto original de `VUELO-CUZ-LIM` decía «con el corazón lleno de recuerdos… una aventura
 * inolvidable»: daba por hecho que el viaje **se acaba ahí**. Pero el mismo tramo lo usa quien
 * empieza en Cusco y baja a Lima para seguir a otro sitio, y entonces el párrafo miente.
 *
 * La regla: **un segmento de vuelo describe el trayecto, no el momento del viaje.** Lo que sí es
 * siempre cierto es la geografía —de dónde se sale, a dónde se llega y cuánto se sube o se baja—
 * y eso sirve igual en el primer día que en el último.
 *
 * ## De dónde sale el texto
 *
 * De la ficha de cada ciudad: su altitud y una frase de qué es ese sitio. La diferencia de altura
 * sólo se menciona cuando pasa de 800 m, que es cuando de verdad se nota —el resto del tiempo
 * decirlo es relleno—.
 *
 * ⚠️ **Es un primer borrador, no la última palabra.** Un texto generado no tiene el oficio de
 * quien vende el viaje; lo que hace es que ningún segmento se quede con «Presentación en el
 * aeropuerto con la antelación que indique la aerolínea».
 *
 * Va por comando porque `contenido` lleva `#[AutoTranslate]`: ver `docs/TravelCargaDeCatalogo.md`.
 */
#[AsCommand(
    name: 'app:travel:redactar-segmentos-vuelo',
    description: 'Redacta el contenido de los segmentos de vuelo a partir de la geografía de cada ciudad.',
)]
final class RedactarSegmentosVueloCommand extends Command
{
    /**
     * Ciudad → [IATA, altitud, qué es ese sitio, cómo se sale de allí, qué se ve al llegar].
     *
     * ⚠️ La quinta hace falta porque sin ella los vuelos SIN salto de altura —Lima–Iquitos,
     * Lima–Punta Cana— se quedaban en «Volaremos, y a la llegada…», que no dice nada. La altitud
     * no puede ser lo único que se cuente de un trayecto.
     *
     * @var array<string, array{0: string, 1: int, 2: string, 3: string, 4: string}>
     */
    private const CIUDADES = [
        'Lima'             => ['LIM', 150,  'la capital peruana, tendida sobre el desierto costero del Pacífico', 'la bruma del Pacífico', 'el desierto y el mar aparecen juntos en la ventanilla'],
        'Cusco'            => ['CUZ', 3400, 'la antigua capital del Imperio Inca, encajada en su valle andino', 'las montañas del Cusco', 'el valle se abre entre montañas justo antes de tocar tierra'],
        'Arequipa'         => ['AQP', 2335, 'la ciudad blanca, al pie del volcán Misti', 'el valle del Misti', 'el cono del Misti domina la aproximación'],
        'Juliaca'          => ['JUL', 3825, 'el altiplano, a un paso del lago Titicaca', 'el altiplano', 'el altiplano se extiende plano hasta el horizonte'],
        'Puerto Maldonado' => ['PEM', 200,  'la puerta de la Amazonía sur, donde el Madre de Dios abre la selva', 'la selva de Madre de Dios', 'los meandros del río dibujan la selva desde el aire'],
        'Iquitos'          => ['IQT', 106,  'la mayor ciudad del mundo sin carretera que llegue hasta ella, sobre el Amazonas', 'la Amazonía', 'el Amazonas aparece como una carretera de agua entre el verde'],
        'Piura'            => ['PIU', 30,   'el norte cálido, entre el desierto de Sechura y las playas del Pacífico', 'el norte peruano', 'el desierto de Sechura se extiende hasta la costa'],
        'Bogotá'           => ['BOG', 2640, 'la sabana andina colombiana', 'la sabana de Bogotá', 'la sabana verde rodea la ciudad al descender'],
        'Santiago'         => ['SCL', 520,  'el valle central chileno, con la cordillera de telón de fondo', 'el valle de Santiago', 'la cordillera nevada acompaña toda la aproximación'],
        'Sao Paulo'        => ['GRU', 760,  'la mayor metrópoli de Sudamérica, sobre la meseta paulista', 'la meseta paulista', 'la ciudad se extiende sin final a la vista'],
        'Río de Janeiro'   => ['GIG', 5,    'la bahía de Guanabara, entre morros y playas', 'la bahía de Guanabara', 'los morros y la bahía se ordenan bajo el ala'],
        'Panamá'           => ['PTY', 10,   'el istmo que une las dos Américas, junto al canal', 'el istmo', 'los dos océanos casi se tocan bajo la ruta'],
        'Cancún'           => ['CUN', 10,   'el Caribe mexicano, de agua turquesa y arena blanca', 'el Caribe mexicano', 'el turquesa del Caribe se ve mucho antes de aterrizar'],
        'Punta Cana'       => ['PUJ', 10,   'el extremo oriental del Caribe dominicano', 'el Caribe dominicano', 'la línea de playa aparece justo antes del descenso'],
    ];

    /**
     * Los que ya tienen texto escrito a mano.
     *
     * ⚠️ Se les respeta el CONTENIDO, no el nombre: el nombre interno es de uso interno —se busca
     * y se ordena por él— y «Vuelo desde la ciudad de Lima a la ciudad de Cusco» no cabe en
     * ninguna lista. Son dos cosas distintas y se tratan aparte.
     */
    private const REDACTADOS = ['VUELO-LIM-CUZ'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('todos', null, InputOption::VALUE_NONE, 'Reescribe también los ya redactados a mano.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');
        $todos = (bool) $input->getOption('todos');

        $porIata = [];

        foreach (self::CIUDADES as $ciudad => $datos) {
            $porIata[$datos[0]] = $ciudad;
        }

        $tocados = 0;

        foreach ($this->em->getRepository(TravelSegmento::class)->findAll() as $segmento) {
            $slug = (string) $segmento->getSlug();

            if (!preg_match('/^VUELO-([A-Z]{3})-([A-Z]{3})$/', $slug, $m)) {
                continue;
            }

            $origen = $porIata[$m[1]] ?? null;
            $destino = $porIata[$m[2]] ?? null;

            if ($origen === null || $destino === null) {
                $io->text(sprintf('  sin ficha de ciudad · %s', $slug));
                continue;
            }

            ++$tocados;
            $nombre = sprintf('Vuelo %s – %s', $origen, $destino);
            $aMano = !$todos && in_array($slug, self::REDACTADOS, true);

            $io->text(sprintf(
                '  %s · %-18s %s%s',
                $simula ? 'redactaría' : 'redactado ',
                $slug,
                $nombre,
                $aMano ? '   (sólo el nombre: su texto está escrito a mano)' : '',
            ));

            if ($output->isVerbose() && !$aMano) {
                $io->text('      ' . strip_tags($this->redactar($origen, $destino)));
            }

            if ($simula) {
                continue;
            }

            // Sólo el español: `#[AutoTranslate]` rellena los otros seis al guardar.
            $segmento
                ->setNombreInterno($nombre)
                ->setTitulo([['language' => 'es', 'content' => $nombre]]);

            if (!$aMano) {
                $segmento->setContenido([['language' => 'es', 'content' => $this->redactar($origen, $destino)]]);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf('%s %d segmento(s).', $simula ? 'Se redactarían' : 'Redactados', $tocados));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo. Con -v se ve el texto.');
        }

        return Command::SUCCESS;
    }

    private function redactar(string $origen, string $destino): string
    {
        [, $altO, , $salidaO, ] = self::CIUDADES[$origen];
        [, $altD, $queEsD, , $llegadaD] = self::CIUDADES[$destino];

        $salto = $altD - $altO;

        // ⚠️ Dos redacciones, no una con hueco. Cuando hay salto de altura, ÉSE es el relato del
        // trayecto; cuando no lo hay, el relato es la aproximación. Meter las dos cosas siempre
        // daba frases con relleno —«Volaremos, y a la llegada…»— y las 31 salían calcadas.
        if (abs($salto) >= 800) {
            $movimiento = $salto > 0
                ? sprintf('Iremos ganando altura hasta los %s metros', $this->miles($altD))
                : sprintf('Iremos descendiendo desde los %s metros', $this->miles($altO));

            return sprintf(
                '<p>✈️ <b>De %s a %s:</b> Dejamos %s rumbo a %s. %s, y al final del trayecto nos espera %s.</p>',
                $origen,
                $destino,
                $salidaO,
                $destino,
                $movimiento,
                $queEsD,
            );
        }

        return sprintf(
            '<p>✈️ <b>De %s a %s:</b> Volamos desde %s hasta %s, y %s.</p>',
            $origen,
            $destino,
            $salidaO,
            $queEsD,
            $llegadaD,
        );
    }

    private function miles(int $altitud): string
    {
        return number_format($altitud, 0, ',', ' ');
    }
}
