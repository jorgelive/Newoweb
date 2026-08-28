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
 * Los segmentos de una estancia en resort de Punta Cana, **una actividad por segmento**.
 *
 * ── Por qué atómicos y no un texto por día ──────────────────────────────────
 * La primera versión agrupaba el día entero en un segmento («llegada + almuerzo + tarde + cena»).
 * Se descartó: repetía conceptos —el mismo almuerzo buffet descrito dentro de cuatro segmentos
 * distintos— y, sobre todo, **dejaba sin fotos a cada actividad**. Las imágenes cuelgan del
 * segmento, así que un segmento que contiene cinco cosas sólo puede ilustrar una.
 *
 * Atómicos, el día se compone: llegada, comidas, playa, espectáculo. Y cada pieza lleva su galería
 * y se reutiliza en cualquier estancia del Caribe, no sólo en ésta.
 *
 * ── La excepción: el resumen ────────────────────────────────────────────────
 * `FULL_BREVE` es el único que agrupa, y existe por el motivo contrario. Una estancia de ocho días
 * son seis días idénticos: montarlos con siete segmentos cada uno da cuarenta y dos bloques que
 * dicen lo mismo. El **primer día completo** se arma con las piezas —con sus fotos— y los
 * siguientes usan este resumen de un párrafo.
 *
 * ── Slugs ───────────────────────────────────────────────────────────────────
 * `TIPO-CONTEXTO-VARIANTE`, la convención de `docs/Travel.md` §slug: mayúsculas, guion entre
 * partes y guion bajo dentro de una. `PUJ` es el código de Punta Cana, como `MAPI`, `OLL` o `URU`.
 *
 * ⚠️ Se estrena el tipo **`DES`** (desayuno), que no existía: había `ALM` y `CENA` pero ninguna
 * estancia había necesitado el desayuno suelto.
 *
 * ⚠️ **Check-in y check-out no tienen variante de mañana o tarde.** Con piezas sueltas la hora la
 * dice el orden del día: si el check-in va seguido del almuerzo, es por la mañana. Duplicarlos
 * sería volver a meter el día dentro del segmento.
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
        // ── Llegada y salida ────────────────────────────────────────────────
        [
            'slug'   => 'ACT-PUJ_RESORT-CHECKIN',
            'nombre' => 'Check-in en el resort',
            'titulo' => 'Llegada y check-in en el resort',
            'contenido' =>
                '<p>🛎️ <strong>Bienvenida:</strong> Llegada al resort y registro en recepción. Le entregarán '
                . 'la pulsera del todo incluido, que desde ese momento le da acceso a restaurantes, bares y '
                . 'actividades.</p>'
                . '<p>🧳 <strong>Si la habitación no está lista:</strong> El hotel guarda su equipaje y usted '
                . 'ya puede empezar a usar las instalaciones sin esperar.</p>',
        ],
        [
            'slug'   => 'ACT-PUJ_RESORT-CHECKOUT',
            'nombre' => 'Check-out del resort',
            'titulo' => 'Check-out del resort',
            'contenido' =>
                '<p>🧳 <strong>Salida:</strong> Entrega de la habitación en recepción. El hotel puede guardar '
                . 'su equipaje si el traslado sale más tarde, así que puede seguir disfrutando de la playa y '
                . 'las piscinas hasta la hora de partir.</p>',
        ],

        // ── Comidas ─────────────────────────────────────────────────────────
        [
            'slug'   => 'DES-BUFFET-PUJ',
            'nombre' => 'Desayuno buffet en el resort',
            'titulo' => 'Desayuno buffet',
            'contenido' =>
                '<p>🌅 <strong>Desayuno buffet:</strong> Fruta tropical, estaciones calientes preparadas al '
                . 'momento, panadería y repostería recién hecha, y zumos naturales.</p>',
        ],
        [
            'slug'   => 'ALM-BUFFET-PUJ',
            'nombre' => 'Almuerzo buffet en el resort',
            'titulo' => 'Almuerzo buffet',
            'contenido' =>
                '<p>🍽️ <strong>Almuerzo buffet:</strong> Sin reserva y sin horario estricto, con cocina '
                . 'internacional y especialidades dominicanas. También hay servicio junto a la piscina si '
                . 'prefiere no interrumpir el día de playa.</p>',
        ],
        [
            'slug'   => 'CENA-BUFFET-PUJ',
            'nombre' => 'Cena buffet en el resort',
            'titulo' => 'Cena buffet',
            'contenido' =>
                '<p>🍽️ <strong>Cena buffet:</strong> En el restaurante principal del resort, con estaciones '
                . 'temáticas que cambian cada noche. No necesita reservar.</p>',
        ],
        [
            'slug'   => 'CENA-TEMATICO-PUJ',
            'nombre' => 'Cena en restaurante temático (reserva previa)',
            'titulo' => 'Cena en restaurante temático',
            'contenido' =>
                '<p>🍷 <strong>Cena a la carta:</strong> El resort cuenta con restaurantes temáticos incluidos '
                . 'en su régimen.</p>'
                . '<p>📅 <strong>Requieren reserva previa:</strong> Conviene apuntarse en recepción al llegar, '
                . 'porque las plazas de cada noche se agotan.</p>',
        ],

        // ── El día ──────────────────────────────────────────────────────────
        [
            'slug'   => 'ACT-PUJ_RESORT-PISCINA_PLAYA',
            'nombre' => 'Piscina y playa en el resort',
            'titulo' => 'Piscina y playa',
            'contenido' =>
                '<p>🏖️ <strong>Playa y piscinas:</strong> Arena blanca y aguas turquesas a pie de hotel, con '
                . 'hamacas y sombrillas. Las piscinas del resort tienen zona de bar y áreas tranquilas.</p>',
        ],
        [
            'slug'   => 'ACT-PUJ_RESORT-RECREATIVAS',
            'nombre' => 'Actividades recreativas del hotel',
            'titulo' => 'Actividades recreativas',
            'contenido' =>
                '<p>🏐 <strong>Programa del hotel:</strong> El equipo de animación organiza actividades a lo '
                . 'largo del día —deportes en la playa, juegos en la piscina, clases de baile— con la '
                . 'participación siempre opcional.</p>',
        ],
        [
            'slug'   => 'ACT-PUJ_RESORT-SHOW',
            'nombre' => 'Espectáculo nocturno del hotel',
            'titulo' => 'Espectáculo nocturno',
            'contenido' =>
                '<p>🎭 <strong>Cierre del día:</strong> El resort cierra la jornada con su espectáculo en el '
                . 'teatro, con un programa distinto cada noche de la semana.</p>',
        ],

        // ── El resumen, para los días que se repiten ─────────────────────────
        [
            'slug'   => 'ACT-PUJ_RESORT-FULL_BREVE',
            'nombre' => 'Día completo en el resort (resumen)',
            'titulo' => 'Día libre en el resort',
            'contenido' =>
                '<p>🏖️ <strong>Todo incluido:</strong> Desayuno, almuerzo y cena buffet, playa y piscinas a su '
                . 'ritmo, y las actividades recreativas del hotel. Por la noche, espectáculo según el programa '
                . 'de la semana. Los restaurantes temáticos siguen disponibles con reserva previa.</p>',
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
