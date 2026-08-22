<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelLugar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Etiqueta los componentes del catálogo con sus lugares, por lo que dice su nombre.
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * El vocabulario de lugares existía desde el principio y **no se usaba**: 224 de 225
 * componentes sin una sola etiqueta. Un filtro que nadie llenó no filtra; el cuadro de tráfico
 * lo ofrece y devuelve todo.
 *
 * ── La regla: las etiquetas SE ACUMULAN hacia arriba ────────────────────────
 * Es la decisión de producto, y es lo que hace útil el filtro:
 *
 * ```
 * Valle Sagrado  ─┐
 * Machu Picchu   ─┼─► también CUSCO      (se despachan desde Cusco)
 * Valle Sur      ─┤
 * Vinicunca…     ─┘
 *
 * Ica (y Paracas) ─┬─► también LIMA      (se venden y salen desde Lima)
 * Nasca           ─┘
 * ```
 *
 * Así, filtrar por «Cusco» trae también el Valle y Machu Picchu —que es lo que espera quien
 * opera desde Cusco— sin que haya que inventar una jerarquía en el modelo. El vocabulario sigue
 * PLANO y multivaluado, exactamente como lo describe {@see TravelLugar}: quien acumula es esta
 * tabla de reglas, no una relación padre-hijo.
 *
 * ⚠️ **Paracas NO es una etiqueta**: sus componentes van a Ica (y de ahí a Lima). Decisión
 * explícita — hay 16 componentes de Paracas, pero se operan dentro del circuito de Ica.
 *
 * ── Idempotente, y no pisa lo puesto a mano ─────────────────────────────────
 * Sólo AÑADE etiquetas que falten. Un componente que alguien etiquetó a mano conserva lo suyo y
 * gana lo que le corresponda; correrlo dos veces no cambia nada la segunda. Por eso se puede
 * volver a lanzar cuando entren componentes nuevos, que es justo cuando hace falta.
 *
 * ⚠️ **No quita etiquetas.** Si una regla cambia y deja de aplicar, la vieja se queda: quitarla
 * automáticamente borraría el trabajo manual de alguien sin preguntar. Eso se hace a mano.
 */
#[AsCommand(
    name: 'app:travel:etiquetar-lugares',
    description: 'Etiqueta los componentes del catálogo con sus lugares, según su nombre.',
)]
final class TravelEtiquetarLugaresCommand extends Command
{
    /**
     * Nombre del lugar → patrón, y a qué OTROS lugares arrastra.
     *
     * El orden importa para leerlo, no para aplicarlo: un componente puede casar con varias
     * reglas y se queda con todas.
     *
     * @var array<string, array{patron: string, arrastra: list<string>}>
     */
    private const array REGLAS = [
        // ── Eje Cusco ───────────────────────────────────────────────────────
        'Cusco' => [
            // Los últimos son productos de la casa cuyo nombre no dice dónde ocurren —«Super
            // Valle», «Combinada», «Escénico», «By Car», «ruta del misterio»—. Se despachan
            // desde Cusco, y sin ellos se quedaban fuera del filtro para siempre.
            'patron' => '/(cusco|sacsayhuaman|koricancha|catedral|san pedro|poroy|wanchaq|paradero|inti raymi|action valley|humedal de huasao|cochawasi|\bparque\b|valle de los duendes|morada'
                . '|super valle|combinada|esc[eé]nico|by car|ruta del misterio'
                . '|bungee|slingshot|cuatrimoto'
                . '|\bbtc\b|\bbtps\b)/iu',
            'arrastra' => [],
        ],
        'Valle Sagrado' => [
            'patron' => '/(valle sagrado|urubamba|ollanta|pisac|maras|moray|chinchero|skylodge|starlodge|piuray|via ferrata|zip ?line|inkariy|\bbtpv\b)/iu',
            'arrastra' => ['Cusco'],
        ],
        'Machu Picchu' => [
            'patron' => '/(machu ?picchu|\bmapi\b|consettur|aguas calientes|hidroel|mandor|santa teresa|camino inca|salkantay)/iu',
            'arrastra' => ['Cusco'],
        ],
        'Valle Sur' => [
            'patron' => '/(valle sur|andahuaylillas|raqchi|tip[oó]n|pikillacta|chuquicahuana|\bbtpvs\b)/iu',
            'arrastra' => ['Cusco'],
        ],
        // Alta montaña: se opera DESDE Cusco y ahí se queda, sin etiqueta propia.
        'Cusco:altura' => [
            'patron' => '/(vinicunca|humantay|palcoyo|7 lagunas|siete lagunas|quelcc?aya|pacchanta|ausangate)/iu',
            'arrastra' => ['Cusco'],
        ],

        // ── Eje Lima ────────────────────────────────────────────────────────
        'Lima' => [
            'patron' => '/(\blima\b|san francisco)/iu',
            'arrastra' => [],
        ],
        // ⚠️ Paracas cae en Ica a propósito: se opera dentro de ese circuito.
        'Ica' => [
            'patron' => '/(\bica\b|huacachina|buggy|paracas|ballestas|\bpisco\b)/iu',
            'arrastra' => ['Lima'],
        ],
        'Nasca' => [
            'patron' => '/(nasca|nazca|sobrevuelo)/iu',
            'arrastra' => ['Lima'],
        ],

        // ── Sur ─────────────────────────────────────────────────────────────
        'Puno' => [
            'patron' => '/(puno|juliaca|uros|taquile|amantani|pucar[aá]|titicaca|corredor sur|ruta del sol)/iu',
            'arrastra' => [],
        ],
        'Arequipa' => [
            'patron' => '/(arequipa|colca|salinas y aguada blanca|chivay|santa catalina)/iu',
            'arrastra' => [],
        ],

        /**
         * La Convención, a 6 h de Cusco por la ruta de Amparaes.
         *
         * ⚠️ Arrastra Cusco porque **desde ahí se despacha**, que es el eje que decide en este
         * vocabulario. Si algún día se opera con base propia, se le quita el arrastre… pero los
         * componentes ya etiquetados conservarían Cusco: el comando no quita nada.
         */
        'Quillabamba' => [
            'patron' => '/quillabamba/iu',
            'arrastra' => ['Cusco'],
        ],

        // ── Fuera de Perú ───────────────────────────────────────────────────
        'Riviera Maya' => [
            'patron' => '/(canc[uú]n|playa del carmen|chichen|xcaret|zona hotelera)/iu',
            'arrastra' => [],
        ],
        // Sin arrastre: no hay hub desde el que se opere. Hoy es un alojamiento suelto.
        'Aruba' => [
            'patron' => '/aruba/iu',
            'arrastra' => [],
        ],
    ];

    /** Las que no son etiqueta de verdad: sólo sirven para arrastrar. */
    private const array VIRTUALES = ['Cusco:altura'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría.');
        $this->addOption('crear-lugares', null, InputOption::VALUE_NONE, 'Da de alta los lugares que falten.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        $lugares = $this->lugares($io, (bool) $input->getOption('crear-lugares'), $seco);

        if ($lugares === null) {
            return Command::FAILURE;
        }

        $puestas = 0;
        $tocados = 0;
        $filas = [];

        foreach ($this->em->getRepository(TravelComponente::class)->findAll() as $comp) {
            $nombre = (string) $comp->getNombre();
            $quiere = $this->lugaresDe($nombre);

            if ($quiere === []) {
                continue;
            }

            $yaTiene = [];
            foreach ($comp->getLugares() as $l) {
                $yaTiene[] = (string) $l->getNombre();
            }

            $faltan = array_values(array_diff($quiere, $yaTiene));

            if ($faltan === []) {
                continue;
            }

            $tocados++;
            $puestas += count($faltan);
            $filas[] = [$nombre, implode(', ', $faltan)];

            if (!$seco) {
                foreach ($faltan as $nombreLugar) {
                    $comp->addLugar($lugares[$nombreLugar]);
                }
            }
        }

        if ($filas !== []) {
            $io->table(['Componente', 'Etiquetas que se añaden'], array_slice($filas, 0, 40));

            if (count($filas) > 40) {
                $io->comment(sprintf('… y %d componentes más.', count($filas) - 40));
            }
        }

        if ($seco) {
            $io->warning(sprintf('SECO: se habrían etiquetado %d componentes con %d etiquetas.', $tocados, $puestas));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d componentes etiquetados, %d etiquetas puestas.', $tocados, $puestas));

        return Command::SUCCESS;
    }

    /**
     * Qué lugares le tocan a este nombre, con los arrastres ya resueltos.
     *
     * @return list<string>
     */
    private function lugaresDe(string $nombre): array
    {
        $salida = [];

        foreach (self::REGLAS as $lugar => $regla) {
            if (preg_match($regla['patron'], $nombre) !== 1) {
                continue;
            }

            if (!in_array($lugar, self::VIRTUALES, true)) {
                $salida[] = $lugar;
            }

            foreach ($regla['arrastra'] as $arrastrado) {
                $salida[] = $arrastrado;
            }
        }

        return array_values(array_unique($salida));
    }

    /**
     * Los lugares que hacen falta, indexados por nombre.
     *
     * ⚠️ **No los crea salvo que se pida.** Un comando de etiquetado que además da de alta
     * vocabulario a la primera es el que acaba llenando el maestro de etiquetas con faltas de
     * ortografía; el `unique` del nombre las convertiría en filas distintas para siempre.
     *
     * @return array<string, TravelLugar>|null null si falta alguno y no se pidió crearlos
     */
    private function lugares(SymfonyStyle $io, bool $crear, bool $seco): ?array
    {
        $repo = $this->em->getRepository(TravelLugar::class);
        $mapa = [];

        foreach ($repo->findAll() as $lugar) {
            $mapa[(string) $lugar->getNombre()] = $lugar;
        }

        $necesarios = array_values(array_diff(array_keys(self::REGLAS), self::VIRTUALES));
        $faltan = array_values(array_diff($necesarios, array_keys($mapa)));

        if ($faltan === []) {
            return $mapa;
        }

        if (!$crear) {
            $io->error(sprintf(
                'Faltan estos lugares en el maestro: %s. Lánzalo con --crear-lugares, o créalos en el panel.',
                implode(', ', $faltan)
            ));

            return null;
        }

        $orden = 0;
        foreach ($mapa as $lugar) {
            $orden = max($orden, $lugar->getOrden());
        }

        foreach ($faltan as $nombre) {
            $lugar = new TravelLugar();
            $lugar->setNombre($nombre);
            $lugar->setOrden(++$orden);
            $lugar->setActivo(true);

            $mapa[$nombre] = $lugar;

            if (!$seco) {
                $this->em->persist($lugar);
            }
        }

        $io->note(sprintf('Lugares dados de alta: %s.', implode(', ', $faltan)));

        return $mapa;
    }
}
