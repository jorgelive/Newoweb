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
use App\Travel\Enum\TarifaModalidadEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Los traslados entre el aeropuerto de Punta Cana y el hotel, en los dos sentidos.
 *
 * ## ⚠️ Un extremo FIJO y el otro «alojamiento»
 *
 * Es lo que hace que estos segmentos sirvan para cualquier hotel, y está copiado de los de Lima:
 *
 *   llegada  ·  inicio = FIJO (aeropuerto)      fin = ALOJAMIENTO
 *   salida   ·  inicio = ALOJAMIENTO            fin = FIJO (aeropuerto)
 *
 * `ALOJAMIENTO` **no lleva punto**: se resuelve al emitir la orden, con el hotel que de verdad
 * reservó el pasajero. Poner un punto fijo ahí ataría el segmento a un resort concreto y habría
 * que duplicarlo por cada uno — que es justo lo que el modo existe para evitar.
 *
 * De ahí sale el «dónde recojo / dónde dejo» de la orden de servicio.
 *
 * ## Las tarifas nacen a 0
 *
 * Las de Lima son ocho —Auto, Van, Sprinter y Master, cada una en versión día y noche, con su
 * capacidad—. Esa flota es un dato del operador de Punta Cana que aquí no se conoce, y
 * **inventarla sería peor que dejarla vacía**: una tarifa con un vehículo que no existe se
 * cotiza igual. Se crea una por sentido, a 0, para que el componente sea usable.
 */
#[AsCommand(
    name: 'app:travel:crear-traslados-punta-cana',
    description: 'Crea los traslados aeropuerto ↔ hotel de Punta Cana, con su servicio y componentes.',
)]
final class CrearTrasladosPuntaCanaCommand extends Command
{
    private const SERVICIO = 'TRF_PUJ';
    private const SERVICIO_NOMBRE = 'Transporte en Punta Cana';
    private const AEROPUERTO = 'Aeropuerto de Punta Cana';
    private const MONEDA = 'USD';

    /**
     * @var list<array{slug: string, nombre: string, titulo: string, contenido: string,
     *                 desdeAeropuerto: bool}>
     */
    private const SEGMENTOS = [
        [
            'slug' => 'TRANS-APT_PUJ-HOTEL_PUJ',
            'nombre' => 'Transporte Aeropuerto Punta Cana - Hotel Punta Cana',
            'titulo' => 'Del aeropuerto de Punta Cana al alojamiento',
            'contenido' => '<p>🚐 <b>Llegada a Punta Cana:</b> A la salida de migraciones nos estará '
                . 'esperando nuestro transporte para llevarnos al resort. El trayecto bordea la costa '
                . 'y ronda la media hora, según dónde esté el alojamiento.</p>',
            'desdeAeropuerto' => true,
        ],
        [
            'slug' => 'TRANS-HTL_PUJ-AEROPUERTO_PUJ',
            'nombre' => 'Transporte Hotel Punta Cana - Aeropuerto de Punta Cana',
            'titulo' => 'Del alojamiento al aeropuerto de Punta Cana',
            'contenido' => '<p>🚐 <b>Rumbo al aeropuerto:</b> Recojo en el alojamiento con la '
                . 'antelación que pida la aerolínea y traslado al aeropuerto de Punta Cana.</p>',
            'desdeAeropuerto' => false,
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
        $aeropuerto = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => self::AEROPUERTO]);

        if ($moneda === null || $aeropuerto === null) {
            $io->error(sprintf('Falta la moneda %s o el punto «%s».', self::MONEDA, self::AEROPUERTO));

            return Command::FAILURE;
        }

        $io->title('Traslados de Punta Cana');

        $servicio = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => self::SERVICIO]);

        if ($servicio === null) {
            $io->text(sprintf('  %s · servicio %s (%s)', $simula ? 'crearía' : 'creado ', self::SERVICIO_NOMBRE, self::SERVICIO));

            if (!$simula) {
                $servicio = (new TravelServicio())
                    ->setCodigo(self::SERVICIO)
                    ->setNombreInterno(self::SERVICIO_NOMBRE)
                    ->setTitulo([['language' => 'es', 'content' => self::SERVICIO_NOMBRE]]);
                $this->em->persist($servicio);
                $this->em->flush();
            }
        } else {
            $io->text(sprintf('  servicio %s · ya existe', self::SERVICIO));
        }

        $creados = 0;

        foreach (self::SEGMENTOS as $def) {
            if ($this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']]) !== null) {
                $io->text(sprintf('  ya existe · %s', $def['slug']));
                continue;
            }

            ++$creados;
            $io->text(sprintf(
                '  %s · %-30s %s',
                $simula ? 'crearía' : 'creado ',
                $def['slug'],
                $def['desdeAeropuerto'] ? 'aeropuerto → alojamiento' : 'alojamiento → aeropuerto',
            ));

            if ($simula) {
                continue;
            }

            $segmento = (new TravelSegmento())
                ->setSlug($def['slug'])
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setContenido([['language' => 'es', 'content' => $def['contenido']]]);

            // El extremo del hotel va SIN punto: lo resuelve la emisión de la orden.
            if ($def['desdeAeropuerto']) {
                $segmento->setInicioModo(PuntoModoEnum::FIJO)->setInicioPunto($aeropuerto)
                         ->setFinModo(PuntoModoEnum::ALOJAMIENTO);
            } else {
                $segmento->setInicioModo(PuntoModoEnum::ALOJAMIENTO)
                         ->setFinModo(PuntoModoEnum::FIJO)->setFinPunto($aeropuerto);
            }

            $this->em->persist($segmento);

            $componente = (new TravelComponente())
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setTipo(ComponenteTipoEnum::TRANSPORTE);
            $this->em->persist($componente);

            // Los setters de TravelTarifa devuelven void: aquí no se encadena.
            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            $tarifa->setNombreInterno($def['nombre']);
            $tarifa->setTitulo([['language' => 'es', 'content' => $def['titulo']]]);
            $tarifa->setMoneda($moneda);
            $tarifa->setMonto('0.00');
            $tarifa->setModalidad(TarifaModalidadEnum::PRIVADO);
            $this->em->persist($tarifa);

            $this->em->persist(
                (new TravelSegmentoComponente())
                    ->setSegmento($segmento)
                    ->setComponente($componente)
                    ->setTarifaPredeterminada($tarifa)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setOrden(1),
            );

            $servicio?->addSegmento($segmento);
            $servicio?->addComponente($componente);
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf('%s %d segmento(s).', $simula ? 'Se crearían' : 'Creados', $creados));

        if ($creados > 0) {
            $io->warning(
                'Las tarifas nacen a 0 y con una sola por sentido: la flota de Punta Cana '
                . '—qué vehículos y a qué precio— es un dato del operador. Los de Lima tienen ocho.',
            );
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
