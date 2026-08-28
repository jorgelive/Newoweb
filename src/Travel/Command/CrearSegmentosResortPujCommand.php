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
 * Los segmentos de una estancia en resort de Punta Cana.
 *
 * ── Por qué son piezas y no un texto por día ────────────────────────────────
 * Una estancia de 7 u 8 días redactada día a día son ocho textos casi idénticos que hay que
 * mantener a la vez. Aquí el día se **arma**: llegada, días completos, excursiones intercaladas,
 * salida. Por eso el desayuno y la cena existen sueltos — los días de Isla Saona el resort sólo
 * pone esas dos comidas, y el resto del día lo ocupa la excursión.
 *
 * ── El día completo, en dos versiones ───────────────────────────────────────
 * `FULL` lleva el texto entero y se usa **el primer día completo**; `FULL_BREVE` es título y
 * resumen para los siguientes. Repetir seis veces el mismo párrafo no informa: cansa, y hace que
 * se deje de leer justo donde sí cambia algo.
 *
 * ── Slugs ───────────────────────────────────────────────────────────────────
 * `TIPO-CONTEXTO-VARIANTE`, la convención de `docs/Travel.md` §slug: mayúsculas, guion entre
 * partes y guion bajo dentro de una. `PUJ` es el código de Punta Cana, como `MAPI`, `OLL` o `URU`.
 *
 * ⚠️ Se estrena el tipo **`DES`** (desayuno), que no existía: había `ALM` y `CENA` pero ninguna
 * estancia había necesitado el desayuno suelto.
 *
 * ⚠️ **Por comando y no por SQL**: `titulo` y `contenido` llevan `#[AutoTranslate]`. Un `INSERT`
 * directo los dejaría sólo en español sin que nada lo denunciara.
 *
 * Idempotente por `slug`: repetirlo no duplica ni pisa lo que ya exista.
 */
#[AsCommand(
    name: 'app:travel:crear-segmentos-resort-puj',
    description: 'Crea los segmentos de estancia en resort de Punta Cana (llegada, días, salida, comidas).',
)]
final class CrearSegmentosResortPujCommand extends Command
{
    /** @var list<array{slug: string, nombre: string, titulo: string, contenido: string}> */
    private const SEGMENTOS = [
        [
            'slug'   => 'ACT-PUJ_RESORT-FULL',
            'nombre' => 'Día completo en el resort (texto completo)',
            'titulo' => 'Día libre en el resort',
            'contenido' =>
                '<p>🌅 <strong>Comenzamos el día:</strong> Abrimos la jornada con el desayuno buffet, '
                . 'con fruta tropical, estaciones calientes y repostería recién hecha.</p>'
                . '<p>🏖️ <strong>Playa y piscina:</strong> El resto de la mañana es suyo. Puede alternar '
                . 'entre las piscinas y la playa de arena blanca, o sumarse a las actividades '
                . 'recreativas que el hotel organiza a lo largo del día.</p>'
                . '<p>🍽️ <strong>Almuerzo buffet:</strong> A mediodía, almuerzo buffet sin necesidad de '
                . 'reservar. Después, más playa y piscina a su ritmo.</p>'
                . '<p>🍷 <strong>La cena:</strong> Por la noche puede cenar en el buffet o en uno de los '
                . 'restaurantes temáticos del resort. <strong>Los temáticos requieren reserva previa</strong>, '
                . 'que conviene hacer con un día de antelación en recepción.</p>'
                . '<p>🎭 <strong>Espectáculo nocturno:</strong> El hotel cierra el día con su espectáculo, '
                . 'según el programa de la semana.</p>',
        ],
        [
            'slug'   => 'ACT-PUJ_RESORT-FULL_BREVE',
            'nombre' => 'Día completo en el resort (resumen, días siguientes)',
            'titulo' => 'Día libre en el resort',
            'contenido' =>
                '<p>🏖️ <strong>Todo incluido:</strong> Desayuno, almuerzo y cena buffet, playa y piscinas '
                . 'a su ritmo, actividades recreativas y espectáculo nocturno según el programa del hotel. '
                . 'Los restaurantes temáticos requieren reserva previa.</p>',
        ],
        [
            'slug'   => 'ACT-PUJ_RESORT-CHECKIN_AM',
            'nombre' => 'Llegada al resort por la mañana',
            'titulo' => 'Llegada al resort',
            'contenido' =>
                '<p>🛎️ <strong>Check-in:</strong> Llegada al resort y registro. Si la habitación aún no '
                . 'está lista, el hotel guarda el equipaje y ya puede empezar a usar las instalaciones.</p>'
                . '<p>🍽️ <strong>Almuerzo buffet:</strong> El régimen todo incluido arranca desde su llegada.</p>'
                . '<p>🏖️ <strong>Tarde libre:</strong> Playa, piscinas y actividades del hotel. Por la noche, '
                . 'cena buffet o restaurante temático con reserva previa, y espectáculo nocturno.</p>',
        ],
        [
            'slug'   => 'ACT-PUJ_RESORT-CHECKIN_PM',
            'nombre' => 'Llegada al resort por la tarde',
            'titulo' => 'Llegada al resort',
            'contenido' =>
                '<p>🛎️ <strong>Check-in:</strong> Llegada al resort por la tarde y registro.</p>'
                . '<p>🏖️ <strong>Primer contacto:</strong> Le quedará tarde para reconocer las instalaciones '
                . 'y disfrutar de la playa o las piscinas antes de que caiga el sol.</p>'
                . '<p>🍷 <strong>Cena y espectáculo:</strong> Cena buffet o restaurante temático con reserva '
                . 'previa, y espectáculo nocturno según el programa del hotel.</p>',
        ],
        [
            'slug'   => 'ACT-PUJ_RESORT-CHECKOUT_AM',
            'nombre' => 'Salida del resort tras el desayuno',
            'titulo' => 'Salida del resort',
            'contenido' =>
                '<p>🌅 <strong>Desayuno buffet:</strong> Última mañana en el resort.</p>'
                . '<p>🧳 <strong>Check-out:</strong> Entrega de la habitación y salida. El hotel puede guardar '
                . 'el equipaje si su vuelo sale más tarde.</p>',
        ],
        [
            'slug'   => 'ACT-PUJ_RESORT-CHECKOUT_PM',
            'nombre' => 'Salida del resort tras el almuerzo',
            'titulo' => 'Salida del resort',
            'contenido' =>
                '<p>🌅 <strong>Mañana libre:</strong> Desayuno buffet y una última mañana de playa o piscina.</p>'
                . '<p>🍽️ <strong>Almuerzo buffet:</strong> Antes de despedirse del resort.</p>'
                . '<p>🧳 <strong>Check-out:</strong> Entrega de la habitación y salida. El hotel puede guardar '
                . 'el equipaje hasta la hora del traslado.</p>',
        ],
        [
            'slug'   => 'DES-BUFFET-PUJ',
            'nombre' => 'Desayuno buffet en el resort',
            'titulo' => 'Desayuno buffet en el resort',
            'contenido' =>
                '<p>🌅 <strong>Desayuno buffet:</strong> En el resort, con fruta tropical, estaciones '
                . 'calientes y repostería recién hecha, antes de salir.</p>',
        ],
        [
            'slug'   => 'CENA-BUFFET-PUJ',
            'nombre' => 'Cena buffet en el resort',
            'titulo' => 'Cena buffet en el resort',
            'contenido' =>
                '<p>🍽️ <strong>Cena buffet:</strong> De vuelta en el resort, cena buffet incluida. Si prefiere '
                . 'uno de los restaurantes temáticos, recuerde que requieren reserva previa.</p>',
        ],
        [
            'slug'   => 'ACT-PUJ_RESORT-SIN_CENA',
            'nombre' => 'Día completo en el resort sin cena',
            'titulo' => 'Día libre en el resort',
            'contenido' =>
                '<p>🏖️ <strong>Todo incluido:</strong> Desayuno y almuerzo buffet, playa y piscinas a su '
                . 'ritmo y actividades recreativas del hotel.</p>'
                . '<p>🌙 <strong>La noche, fuera:</strong> La cena no se toma en el resort — está prevista '
                . 'dentro de la actividad nocturna de este día.</p>',
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');
        $repo   = $this->em->getRepository(TravelSegmento::class);

        $creados = $existen = 0;

        foreach (self::SEGMENTOS as $def) {
            if ($repo->findOneBy(['slug' => $def['slug']]) !== null) {
                $io->text(sprintf('  ya existe · %s', $def['slug']));
                ++$existen;
                continue;
            }

            $io->text(sprintf('  %s · %-32s %s', $simula ? 'crearía' : 'creado ', $def['slug'], $def['nombre']));
            ++$creados;

            if ($simula) {
                continue;
            }

            $segmento = (new TravelSegmento())
                ->setSlug($def['slug'])
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setContenido([['language' => 'es', 'content' => $def['contenido']]]);

            $this->em->persist($segmento);
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('%s: %d · ya existían: %d', $simula ? 'Se crearían' : 'Creados', $creados, $existen));

        if ($simula) {
            $io->warning('SIMULACRO: no se escribió nada.');
        }

        return Command::SUCCESS;
    }
}
