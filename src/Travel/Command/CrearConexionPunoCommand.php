<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\PuntoModoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La conexión por tierra Cusco ↔ Puno, y el servicio de traslados de Puno que no existía.
 *
 * ── Por qué va aparte de las excursiones ────────────────────────────────────
 *
 * Porque no se compra con ellas. Hoy quien hace el Titicaca llega de Cusco en bus nocturno
 * —no hay vuelo que aterrice en Juliaca a tiempo para un tour que arranca a las 07:00—, pero
 * eso es una limitación del calendario aéreo, no del producto. El día que haya vuelo de mañana,
 * la excursión no se toca: se deja de sumar la conexión y ya. Es la regla de
 * `docs/TravelCargaDeCatalogo.md` §2: *un servicio no es un lugar, es lo que se compra junto*.
 *
 * ── Los seis tramos, y los cuatro que ya estaban ────────────────────────────
 *
 *   1 Traslado del hotel de Cusco al Terminal Terrestre   20:00   componente NUEVO
 *   2 Bus nocturno Cusco → Puno                           22:00   ya existía
 *   3 Recepción en el terminal de Puno y espera                   ya existía
 *     ── la excursión ──
 *   5 Bus Puno → Cusco                                            el mismo de 2, es bidireccional
 *   6 Llegada a Cusco y traslado al hotel                 05:00   el mismo de 1
 *
 * ⚠️ **Un componente de transporte es bidireccional y lleva un segmento por SENTIDO.** Es la
 * convención del catálogo, no un invento: `Transporte Aeropuerto Cusco ↔ Cusco (ida o vuelta)`
 * cuelga de `TRANS-APT_CUZ-HOTEL_CUZ` y de `TRANS-HTL_CUZ-AEROPUERTO_CUZ`. Así el precio se
 * mantiene en un sitio y no divergen la ida y la vuelta — el fallo que motivó
 * `app:travel:fusionar-transportes-bidireccionales`.
 *
 * ⚠️ **El traslado hotel ↔ terminal es de CUSCO**, así que vive en `TRF_CUZ` y no en Puno. El
 * nombre del componente es lo único que le dice al proveedor a dónde ir.
 *
 * El contenido de la recepción en el terminal —servicios higiénicos, custodia de equipaje,
 * desayuno aparte— sale de la ficha pública de un operador de Puno: es lo que de verdad pasa en
 * esa espera de hora y media entre que llega el bus y arranca el tour.
 */
#[AsCommand(
    name: 'app:travel:crear-conexion-puno',
    description: 'Crea TRF_PUN y la conexión por tierra Cusco ↔ Puno con sus segmentos.',
    hidden: true,
)]
final class CrearConexionPunoCommand extends Command
{
    private const MONEDA = 'PEN';

    private const SERVICIO_PUNO = ['codigo' => 'TRF_PUN', 'nombre' => 'Transporte en Puno'];
    private const SERVICIO_CUSCO = 'TRF_CUZ';

    /** Puntos que faltaban. Son los únicos de la red con índice único en la base. */
    private const PUNTOS = [
        'Terminal Terrestre de Cusco',
        'Terminal Terrestre de Puno',
    ];

    /**
     * El traslado del hotel al punto de salida en Cusco. **Ya existía**, con su cuadro de flota.
     *
     * ⚠️ Este comando llegó a crear un `Transporte Htl Cusco ↔ Term Cusco (ida o vuelta)` con una
     * sola tarifa inventada, sin ver que el catálogo ya tenía éste —Bus 20, Master 14, Sprinter
     * 16, Van 35, todas `privado`—. Es el duplicado silencioso contra el que avisa
     * `docs/TravelCargaDeCatalogo.md` §4 bis: `travel_componente` no tiene clave única y nada lo
     * habría impedido. {@see self::DUPLICADO} lo deshace.
     */
    private const COMPONENTE_TRASLADO = 'Transporte Htl Cusco ↔ Paradero Cusco (ida o vuelta)';

    /** El que sobra. Se le reapunta lo que cuelgue y se borra. */
    private const DUPLICADO = 'Transporte Htl Cusco ↔ Term Cusco (ida o vuelta)';

    /**
     * Componentes que ya existen sueltos y pasan al pool de `TRF_PUN`.
     *
     * Ninguno tenía servicio: estaban cargados y sin forma de ofrecerlos.
     *
     * @var list<string>
     */
    private const AL_POOL_PUNO = [
        'Transporte Puno ↔ Cusco (ida o vuelta)',
        'Transporte Term Puno - Punto de Inicio',
        'Transporte Aeropuerto Juliaca ↔ Hotel Puno (ida o vuelta)',
        'Transporte Hotel Puno - Aeropuerto Juliaca',
        'Transporte Htl Puno - Estacion Tren Puno',
        'Transporte Juliaca ↔ Cusco (ida o vuelta)',
        'Transporte urbano en Puno',
    ];

    /**
     * Un segmento por sentido. `punto` fija el extremo que no es el alojamiento.
     *
     * @var list<array{slug: string, nombre: string, titulo: string, contenido: string,
     *                 componente: string, hora: string|null, inicio: PuntoModoEnum,
     *                 fin: PuntoModoEnum, punto: string|null, servicio: string}>
     */
    private const SEGMENTOS = [
        [
            'slug' => 'TRANS-HTL_CUZ-TERM_CUZ',
            'nombre' => 'Traslado del hotel al Terminal Terrestre de Cusco',
            'titulo' => 'Al Terminal Terrestre de Cusco',
            'contenido' => 'Les recogemos en su hotel y les dejamos en el Terminal Terrestre con '
                . 'tiempo para el embarque del bus nocturno a Puno.',
            'componente' => self::COMPONENTE_TRASLADO,
            'hora' => '20:00',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::FIJO,
            'punto' => 'Terminal Terrestre de Cusco',
            'servicio' => self::SERVICIO_CUSCO,
        ],
        [
            'slug' => 'TRANS-TERM_CUZ-HTL_CUZ',
            'nombre' => 'Traslado del Terminal Terrestre de Cusco al hotel',
            'titulo' => 'Llegada a Cusco y traslado al hotel',
            'contenido' => 'A la llegada del bus les esperamos en el Terminal Terrestre y les '
                . 'llevamos a su hotel. Es de madrugada, así que conviene tener la habitación '
                . 'reservada desde la noche anterior si quieren entrar de inmediato.',
            'componente' => self::COMPONENTE_TRASLADO,
            'hora' => '05:00',
            'inicio' => PuntoModoEnum::FIJO,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
            'punto' => 'Terminal Terrestre de Cusco',
            'servicio' => self::SERVICIO_CUSCO,
        ],
        [
            'slug' => 'TRANS-CUZ-PUNO',
            'nombre' => 'Bus nocturno Cusco – Puno',
            'titulo' => 'Bus nocturno a Puno',
            'contenido' => 'Salida de Cusco a las diez de la noche. El bus cruza el altiplano '
                . 'mientras duermen y llega a Puno de madrugada.',
            'componente' => 'Transporte Puno ↔ Cusco (ida o vuelta)',
            'hora' => '22:00',
            'inicio' => PuntoModoEnum::FIJO,
            'fin' => PuntoModoEnum::FIJO,
            'punto' => null,
            'servicio' => self::SERVICIO_PUNO['codigo'],
        ],
        [
            'slug' => 'TRANS-PUNO-CUZ',
            'nombre' => 'Bus Puno – Cusco',
            'titulo' => 'Bus de regreso a Cusco',
            'contenido' => 'Regreso a Cusco por carretera, cruzando el altiplano de vuelta.',
            'componente' => 'Transporte Puno ↔ Cusco (ida o vuelta)',
            'hora' => null,
            'inicio' => PuntoModoEnum::FIJO,
            'fin' => PuntoModoEnum::FIJO,
            'punto' => null,
            'servicio' => self::SERVICIO_PUNO['codigo'],
        ],
        [
            'slug' => 'REC-TITICACA-TERM_PUN',
            'nombre' => 'Recepción en el Terminal Terrestre de Puno',
            'titulo' => 'Llegada a Puno y espera',
            'contenido' => 'Les esperamos a la llegada del bus. Mientras arranca el tour pueden '
                . 'asearse y dejar el equipaje en custodia; si quieren desayunar, hay dónde hacerlo '
                . 'y va por su cuenta. Después les llevamos al punto de inicio de la excursión.',
            'componente' => 'Transporte Term Puno - Punto de Inicio',
            'hora' => '05:30',
            'inicio' => PuntoModoEnum::FIJO,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
            'punto' => 'Terminal Terrestre de Puno',
            'servicio' => self::SERVICIO_PUNO['codigo'],
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $moneda = $this->em->getRepository(MaestroMoneda::class)->find(self::MONEDA);
        if ($moneda === null) {
            $io->error(sprintf('No existe la moneda %s.', self::MONEDA));

            return Command::FAILURE;
        }

        $io->title('Conexión Cusco ↔ Puno');

        $io->section('Puntos');
        $puntos = [];

        foreach (self::PUNTOS as $nombre) {
            $punto = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => $nombre]);

            if ($punto !== null) {
                $io->text(sprintf('  ya existe · %s', $nombre));
                $puntos[$nombre] = $punto;
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $nombre));

            if ($simula) {
                continue;
            }

            $punto = (new TravelPunto())->setNombre($nombre);
            $this->em->persist($punto);
            $puntos[$nombre] = $punto;
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->section('Servicios');
        $trfPuno = $this->em->getRepository(TravelServicio::class)
            ->findOneBy(['codigo' => self::SERVICIO_PUNO['codigo']]);

        if ($trfPuno === null) {
            $io->text(sprintf('  %s · %s (%s)', $simula ? 'crearía' : 'creado ', self::SERVICIO_PUNO['nombre'], self::SERVICIO_PUNO['codigo']));

            if (!$simula) {
                $trfPuno = (new TravelServicio())
                    ->setCodigo(self::SERVICIO_PUNO['codigo'])
                    ->setNombreInterno(self::SERVICIO_PUNO['nombre'])
                    ->setTitulo([['language' => 'es', 'content' => self::SERVICIO_PUNO['nombre']]]);
                $this->em->persist($trfPuno);
                $this->em->flush();
            }
        } else {
            $io->text(sprintf('  ya existe · %s', self::SERVICIO_PUNO['codigo']));
        }

        $trfCusco = $this->em->getRepository(TravelServicio::class)
            ->findOneBy(['codigo' => self::SERVICIO_CUSCO]);

        if ($trfCusco === null) {
            $io->error(sprintf('No existe el servicio %s.', self::SERVICIO_CUSCO));

            return Command::FAILURE;
        }

        $io->text(sprintf('  ya existe · %s', self::SERVICIO_CUSCO));

        $io->section('Traslado del hotel al punto de salida');
        $traslado = $this->em->getRepository(TravelComponente::class)
            ->findOneBy(['nombreInterno' => self::COMPONENTE_TRASLADO]);

        if ($traslado === null) {
            $io->error(sprintf('No existe «%s».', self::COMPONENTE_TRASLADO));

            return Command::FAILURE;
        }

        $io->text(sprintf('  usa · %s', self::COMPONENTE_TRASLADO));

        // El duplicado que creó una versión anterior de este comando. Se le reapunta lo que
        // cuelgue y se borra, con su tarifa inventada: el bueno es el que trae el cuadro de flota.
        $duplicado = $this->em->getRepository(TravelComponente::class)
            ->findOneBy(['nombreInterno' => self::DUPLICADO]);

        if ($duplicado !== null) {
            /** @var list<TravelSegmentoComponente> $colgados */
            $colgados = $this->em->getRepository(TravelSegmentoComponente::class)
                ->findBy(['componente' => $duplicado]);

            foreach ($colgados as $pivote) {
                $io->text(sprintf(
                    '  %s · reapunta «%s» al bueno',
                    $simula ? 'haría ' : 'hecho ',
                    $pivote->getSegmento()?->getSlug() ?? '?',
                ));

                if (!$simula) {
                    $pivote->setComponente($traslado);
                    $pivote->setTarifaPredeterminada(null);
                }
            }

            $io->text(sprintf('  %s · borra el duplicado «%s»', $simula ? 'haría ' : 'hecho ', self::DUPLICADO));

            if (!$simula) {
                $this->em->flush();

                foreach ($duplicado->getTarifas() as $tarifa) {
                    $this->em->remove($tarifa);
                }

                $this->em->flush();
                $this->em->remove($duplicado);
                $this->em->flush();
            }
        }

        if (!$simula) {
            $trfCusco->addComponente($traslado);
            $this->em->flush();
        }

        $io->section('Pool de Transporte en Puno');
        $alPool = 0;

        foreach (self::AL_POOL_PUNO as $nombre) {
            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $nombre]);

            if ($componente === null) {
                $io->text(sprintf('  ⚠ no existe · %s', $nombre));
                continue;
            }

            ++$alPool;
            $io->text(sprintf('  %s · %s', $simula ? 'añadiría' : 'añadido ', $nombre));

            if (!$simula && $trfPuno !== null) {
                $trfPuno->addComponente($componente);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->section('Segmentos');
        $creados = 0;

        foreach (self::SEGMENTOS as $def) {
            $existente = $this->em->getRepository(TravelSegmento::class)
                ->findOneBy(['slug' => $def['slug']]);

            if ($existente !== null) {
                $io->text(sprintf('  ya existe · %s', $def['slug']));
                continue;
            }

            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $def['componente']]);

            if ($componente === null && !$simula) {
                $io->error(sprintf('Falta el componente «%s».', $def['componente']));

                return Command::FAILURE;
            }

            ++$creados;
            $io->text(sprintf(
                '  %s · %-26s %-46s %s',
                $simula ? 'crearía' : 'creado ',
                $def['slug'],
                $def['nombre'],
                $def['hora'] ?? '—',
            ));

            if ($simula) {
                continue;
            }

            $segmento = (new TravelSegmento())
                ->setSlug($def['slug'])
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setContenido([['language' => 'es', 'content' => $def['contenido']]])
                ->setInicioModo($def['inicio'])
                ->setFinModo($def['fin']);

            if ($def['punto'] !== null && isset($puntos[$def['punto']])) {
                if ($def['inicio'] === PuntoModoEnum::FIJO) {
                    $segmento->setInicioPunto($puntos[$def['punto']]);
                }
                if ($def['fin'] === PuntoModoEnum::FIJO) {
                    $segmento->setFinPunto($puntos[$def['punto']]);
                }
            }

            $this->em->persist($segmento);

            $servicio = $def['servicio'] === self::SERVICIO_CUSCO ? $trfCusco : $trfPuno;
            $servicio?->addSegmento($segmento);
            $servicio?->addComponente($componente);

            $rel = (new TravelSegmentoComponente())
                ->setSegmento($segmento)
                ->setComponente($componente)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setDia(1)
                ->setOrden(1);

            // Un traslado sí lleva hora global: el bus de las diez sale a las diez lo use quien
            // lo use. No es el caso del ancla de una excursión, cuya hora es de cada plantilla.
            if ($def['hora'] !== null) {
                $rel->setHora(new \DateTimeImmutable($def['hora']));
            }

            $this->em->persist($rel);
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%s %d segmento(s) y %d componente(s) al pool de Puno.',
            $simula ? 'Se crearían' : 'Creados',
            $creados,
            $alPool,
        ));

        $io->note([
            'TRF_PUN no lleva plantilla, como TRF_CUZ y TRF_LIM: es un repertorio del que se toma',
            'lo que haga falta en cada cotización, no un guion.',
            '',
'El traslado del hotel al punto de salida usa el componente que ya existía, con su cuadro',
            'de flota (Bus 20, Master 14, Sprinter 16, Van 35), no una tarifa nueva.',
        ]);

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
