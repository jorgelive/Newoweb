<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
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
 * La escala de día completo en Lima, para quien va o vuelve de Punta Cana.
 *
 * ── ⚠️ Aquí los segmentos NO llevan un componente cada uno ──────────────────
 *
 * Es lo contrario de `ACT_RESORT`, y la diferencia no es de estilo: **es lo que se compra**.
 *
 * En el resort cada actividad es una cosa distinta con su propio prestador —el buffet no lo
 * sirve quien lleva la piscina—, así que cada segmento carga su componente y su tarifa.
 *
 * Una excursión se compra ENTERA. Nadie vende «el Parque Kennedy» por separado: se contrata un
 * programa que incluye traslados y guía, y el Parque Kennedy es un capítulo del relato. Por eso
 * el catálogo lo modela así, y es lo que ya hace `CITY_LIM`:
 *
 *   SAL_EXC-…-RECOJO   ──►  componente ANCLA (el pool que se compra)   ← el único con logística
 *   VIS-…-KENNEDY           sin componente: sólo cuenta
 *   VIS-…-MALECON           sin componente: sólo cuenta
 *   RET_EXC-…               sin componente: el retorno va dentro del ancla
 *
 * Comprobado en los siete segmentos de `CITY_LIM`: **seis con cero componentes** y uno con el
 * pool. El ancla lleva `horaServicioCompleto`, que es lo que promueve su hora a horario de toda
 * la excursión en la guía del pasajero (ver `promoPorServicio` en `PaxCotizacionGuiaView.vue`).
 *
 * Las EXCEPCIONES son las comidas, y por el mismo criterio: se compran aparte y pueden ir
 * incluidas o no. El desayuno del aeropuerto y el almuerzo de Larcomar sí llevan componente.
 *
 * ⚠️ Un segmento sin componente **no tiene hora**, así que en la guía se ordena por `orden`.
 * De ahí que esto sí cree su itinerario: es un programa con guion, y sin él habría que recordar
 * a mano que el malecón va después del Parque Kennedy.
 *
 * Idempotente por clave natural. Por comando y no por migración: `titulo` y `contenido` llevan
 * `#[AutoTranslate]`.
 */
#[AsCommand(
    name: 'app:travel:crear-escala-miraflores',
    description: 'Crea el servicio «Walking en Miraflores» con la escala de día completo en Lima.',
)]
final class CrearEscalaMirafloresCommand extends Command
{
    private const SERVICIO_CODIGO = 'WALK_MIR';
    private const SERVICIO_NOMBRE = 'Walking en Miraflores';
    private const ITINERARIO = 'Escala de día completo en Lima';
    private const PUNTO_AEROPUERTO = 'Aeropuerto de Lima';
    private const MONEDA = 'USD';

    /**
     * El repertorio, en el orden en que ocurre.
     *
     * `componente` es null en los segmentos que sólo cuentan. `ancla` marca el que carga el
     * programa entero y presta su hora a toda la excursión.
     *
     * @var list<array{slug: string, nombre: string, titulo: string, contenido: string,
     *                 componente: string|null, tipo: ComponenteTipoEnum|null, hora: string|null,
     *                 ancla: bool, inicio: PuntoModoEnum, fin: PuntoModoEnum}>
     */
    private const SEGMENTOS = [
        [
            'slug' => 'DES-WALK_MIR-AEROPUERTO',
            'nombre' => 'Desayuno en el aeropuerto de Lima',
            'titulo' => 'Desayuno en el aeropuerto',
            'contenido' => 'Desayuno en el aeropuerto Jorge Chávez antes de salir hacia la ciudad, '
                . 'con tiempo para acomodar el equipaje de mano y estirar las piernas después del vuelo.',
            'componente' => 'Desayuno en el aeropuerto de Lima',
            'tipo' => ComponenteTipoEnum::ALIMENTACION_HORARIO_VAR,
            'hora' => '07:00',
            'ancla' => false,
            'inicio' => PuntoModoEnum::FIJO,
            'fin' => PuntoModoEnum::FIJO,
        ],
        [
            'slug' => 'SAL_EXC-WALK_MIR',
            'nombre' => 'Traslado del aeropuerto a Miraflores',
            'titulo' => 'Del aeropuerto a Miraflores',
            'contenido' => 'Traslado desde el aeropuerto hasta Miraflores, bordeando la costa. '
                . 'El trayecto ronda los cuarenta y cinco minutos y depende del tráfico de la hora.',
            'componente' => 'Escala en Lima · traslados y caminata por Miraflores',
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'hora' => '08:30',
            'ancla' => true,
            'inicio' => PuntoModoEnum::FIJO,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        [
            'slug' => 'VIS-WALK_MIR-KENNEDY',
            'nombre' => 'Parque Kennedy a pie',
            'titulo' => 'Parque Kennedy',
            'contenido' => 'El corazón de Miraflores: dos parques encadenados con puestos de artesanos, '
                . 'pintores al aire libre y los gatos que viven allí desde hace años. Enfrente queda la '
                . 'iglesia de la Virgen Milagrosa.',
            'componente' => null,
            'tipo' => null,
            'hora' => null,
            'ancla' => false,
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        [
            'slug' => 'VIS-WALK_MIR-MALECON',
            'nombre' => 'Malecón de Miraflores a pie',
            'titulo' => 'El malecón y el Pacífico',
            'contenido' => 'Caminata por el malecón, sobre el acantilado y con el Pacífico enfrente. '
                . 'Se cruza el Parque del Amor y casi siempre hay parapentes despegando del borde.',
            'componente' => null,
            'tipo' => null,
            'hora' => null,
            'ancla' => false,
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        [
            'slug' => 'ALM-WALK_MIR-LARCOMAR',
            'nombre' => 'Almuerzo en el patio de comidas de Larcomar',
            'titulo' => 'Almuerzo en Larcomar',
            'contenido' => 'Almuerzo en el patio de comidas de Larcomar, un centro comercial encajado '
                . 'en el acantilado y abierto al mar. Hay cocina peruana y opciones para todos los gustos, '
                . 'y cada quien elige lo suyo.',
            'componente' => 'Almuerzo en Larcomar',
            'tipo' => ComponenteTipoEnum::ALIMENTACION_HORARIO_VAR,
            'hora' => '12:30',
            'ancla' => false,
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        [
            'slug' => 'VIS-WALK_MIR-LARCOMAR_LIBRE',
            'nombre' => 'Tarde libre en Larcomar',
            'titulo' => 'Tarde libre en Larcomar',
            'contenido' => 'Tarde libre en Larcomar: tiendas, terrazas mirando al mar y el propio '
                . 'acantilado. Es el sitio para las últimas compras antes de volver al aeropuerto.',
            'componente' => null,
            'tipo' => null,
            'hora' => null,
            'ancla' => false,
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        [
            'slug' => 'RET_EXC-WALK_MIR-AEROPUERTO',
            'nombre' => 'Traslado de retorno al aeropuerto',
            'titulo' => 'Regreso al aeropuerto',
            'contenido' => 'Traslado de regreso al aeropuerto Jorge Chávez, con margen suficiente '
                . 'para el trámite de embarque del vuelo de salida.',
            'componente' => null,
            'tipo' => null,
            'hora' => null,
            'ancla' => false,
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::FIJO,
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

        $aeropuerto = $this->em->getRepository(TravelPunto::class)
            ->findOneBy(['nombre' => self::PUNTO_AEROPUERTO]);

        if ($aeropuerto === null) {
            $io->error(sprintf('No existe el punto «%s». Créalo antes.', self::PUNTO_AEROPUERTO));

            return Command::FAILURE;
        }

        $io->title(self::ITINERARIO);

        $servicio = $this->em->getRepository(TravelServicio::class)
            ->findOneBy(['codigo' => self::SERVICIO_CODIGO]);

        if ($servicio === null) {
            $io->text(sprintf('Servicio · %s %s (%s)', $simula ? 'crearía' : 'creado', self::SERVICIO_NOMBRE, self::SERVICIO_CODIGO));

            if (!$simula) {
                $servicio = (new TravelServicio())
                    ->setCodigo(self::SERVICIO_CODIGO)
                    ->setNombreInterno(self::SERVICIO_NOMBRE)
                    ->setTitulo([['language' => 'es', 'content' => self::SERVICIO_NOMBRE]]);
                $this->em->persist($servicio);
                $this->em->flush();
            }
        } else {
            $io->text(sprintf('Servicio · ya existe (%s)', self::SERVICIO_CODIGO));
        }

        $io->section('Segmentos');
        $creados = 0;
        /** @var array{segmento: TravelSegmento, componente: TravelComponente, tarifa: TravelTarifa, hora: string}|null $ancla */
        $ancla = null;
        $orden = 0;
        $paraItinerario = [];

        foreach (self::SEGMENTOS as $def) {
            ++$orden;
            $existente = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']]);

            if ($existente !== null) {
                $paraItinerario[$orden] = $existente;
                $io->text(sprintf('  ya existe · %s', $def['slug']));
                continue;
            }

            ++$creados;
            $io->text(sprintf(
                '  %s · %-30s %-42s %s',
                $simula ? 'crearía' : 'creado ',
                $def['slug'],
                $def['nombre'],
                $def['componente'] === null
                    ? 'sólo cuenta (sin componente)'
                    : ($def['ancla'] ? '⚓ ANCLA · ' : '') . $def['componente'],
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

            if ($def['inicio'] === PuntoModoEnum::FIJO) {
                $segmento->setInicioPunto($aeropuerto);
            }

            if ($def['fin'] === PuntoModoEnum::FIJO) {
                $segmento->setFinPunto($aeropuerto);
            }

            $this->em->persist($segmento);
            $servicio?->addSegmento($segmento);
            $paraItinerario[$orden] = $segmento;

            // Los segmentos que sólo cuentan terminan aquí: sin componente no hay tarifa, ni
            // hora, ni nada que comprar. Es el 60 % de una excursión y es lo correcto.
            if ($def['componente'] === null) {
                continue;
            }

            $componente = (new TravelComponente())
                ->setNombreInterno($def['componente'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setTipo($def['tipo']);
            $this->em->persist($componente);
            $servicio?->addComponente($componente);

            // Los setters de TravelTarifa devuelven void: aquí no se encadena.
            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            $tarifa->setNombreInterno($def['componente']);
            $tarifa->setTitulo([['language' => 'es', 'content' => $def['titulo']]]);
            $tarifa->setMoneda($moneda);
            $tarifa->setMonto('0.00');
            $this->em->persist($tarifa);

            $rel = (new TravelSegmentoComponente())
                ->setSegmento($segmento)
                ->setComponente($componente)
                ->setTarifaPredeterminada($tarifa)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setDia(1)
                ->setOrden(1);

            // ⚠️ El ancla NO lleva hora en la relación global: la hora de una excursión es propia
            // de cada plantilla, y puesta aquí se aplicaría a cualquier tour que reuse el
            // segmento. Es el mismo motivo por el que la promoción exige plantilla. Las comidas
            // sí la llevan: un desayuno es a las 07:00 lo use quien lo use.
            if (!$def['ancla']) {
                $rel->setHora(new \DateTimeImmutable($def['hora']));
            }

            $this->em->persist($rel);

            // El ancla necesita una SEGUNDA relación, atada a la plantilla: ver más abajo.
            if ($def['ancla']) {
                $ancla = ['segmento' => $segmento, 'componente' => $componente, 'tarifa' => $tarifa, 'hora' => $def['hora']];
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        // El itinerario es lo que fija el guion: sin componente no hay hora, así que el orden
        // de los seis segmentos narrativos no vive en ningún otro sitio.
        $io->section('Itinerario');
        $itinerario = $this->em->getRepository(TravelItinerario::class)
            ->findOneBy(['nombreInterno' => self::ITINERARIO]);

        if ($itinerario !== null) {
            $io->text('  ya existe.');
        } else {
            $io->text(sprintf('  %s · %s (%d segmentos, día 1)', $simula ? 'crearía' : 'creado ', self::ITINERARIO, count(self::SEGMENTOS)));

            if (!$simula && $servicio !== null) {
                $itinerario = (new TravelItinerario())
                    ->setServicio($servicio)
                    ->setSlug('ITIN-WALK_MIR-ESCALA_DIA')
                    ->setNombreInterno(self::ITINERARIO)
                    ->setTitulo([['language' => 'es', 'content' => 'Escala de día completo en Lima']])
                    ->setDuracionDias(1);
                $this->em->persist($itinerario);

                foreach ($paraItinerario as $pos => $segmento) {
                    $this->em->persist(
                        (new TravelItinerarioSegmentoRel())
                            ->setItinerario($itinerario)
                            ->setSegmento($segmento)
                            ->setDia(1)
                            ->setOrden($pos),
                    );
                }

                // ⚠️ La promoción a «horario de toda la excursión» EXIGE plantilla, y por eso va
                // en una relación aparte. Una promoción global aplicaría a cualquier tour que use
                // el segmento y chocaría con las horas propias de cada plantilla — lo dice la
                // validación `validarPromocionRequierePlantilla()` de TravelSegmentoComponente.
                //
                // De ahí que el ancla tenga DOS relaciones con el mismo componente: la global,
                // que es la que se lleva la tarifa por defecto, y ésta, que sólo fija el horario
                // dentro de este itinerario. Es exactamente lo que hace `CITY_LIM`.
                if ($ancla !== null) {
                    $this->em->persist(
                        (new TravelSegmentoComponente())
                            ->setSegmento($ancla['segmento'])
                            ->setComponente($ancla['componente'])
                            ->setTarifaPredeterminada($ancla['tarifa'])
                            ->setItinerarioContexto($itinerario)
                            ->setModo(ComponenteModoEnum::INCLUIDO)
                            ->setDia(1)
                            ->setOrden(1)
                            ->setHora(new \DateTimeImmutable($ancla['hora']))
                            ->setHoraServicioCompleto(true),
                    );
                    $io->text('  ⚓ hora de servicio completo (08:30) atada a esta plantilla.');
                }

                $this->em->flush();
            }
        }

        $io->newLine();
        $io->success(sprintf('%s %d segmento(s).', $simula ? 'Se crearían' : 'Creados', $creados));
        $io->note('Las comidas nacen a 0 USD y como INCLUIDO. Ajusta precio y modo según se venda.');

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
