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
 * ## Cuatro vehículos por sentido, todos por grupo y a 0
 *
 * ⚠️ **Por grupo, no por persona.** Un traslado se contrata por vehículo: con el precio por
 * pasajero, un grupo de 40 en un bus saldría cuarenta veces el precio del bus.
 *
 * La capacidad sí va puesta —Auto 4, Van 8, Minibús 25, Bus 45— porque es lo que decide qué
 * vehículo se usa para un grupo de 131. Lo que falta es el precio.
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
     * Cuántas tarifas se crearon (o se crearían) en esta pasada.
     *
     * ⚠️ No se puede contar el array que devuelve `asegurarFlota()`: en ensayo no hay entidad que
     * meter dentro, así que salía «la flota ya estaba completa» después de listar ocho.
     */
    private int $flotaCreada = 0;

    /**
     * La flota, una tarifa por vehículo y sentido.
     *
     * ⚠️ Todas **por grupo**, no por persona: un traslado se contrata por vehículo. Con el precio
     * por pasajero, un grupo de 40 en un bus saldría cuarenta veces el precio del bus.
     *
     * La capacidad es lo que decide qué vehículo se usa para un grupo de 131, así que no es un
     * adorno. De dónde sale cada número:
     *
     * | Vehículo | Capacidad | Origen |
     * |---|---|---|
     * | Auto    |  4 | las tarifas de Lima, que ya la tenían |
     * | Van     |  8 | las tarifas de Lima |
     * | Minibús | 25 | el operador de Punta Cana |
     * | Bus     | 45 | el operador de Punta Cana |
     *
     * @var array<string, int>
     */
    private const FLOTA = ['Auto' => 4, 'Van' => 8, 'Minibús' => 25, 'Bus' => 45];

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

            $porDefecto = $this->asegurarFlota($componente, $moneda, $io, false)[0] ?? null;

            $this->em->persist(
                (new TravelSegmentoComponente())
                    ->setSegmento($segmento)
                    ->setComponente($componente)
                    ->setTarifaPredeterminada($porDefecto)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setOrden(1),
            );

            $servicio?->addSegmento($segmento);
            $servicio?->addComponente($componente);
        }

        // Los segmentos que ya existían se saltan arriba, así que la flota se asegura aparte:
        // la primera versión de este comando creaba UNA tarifa de relleno por sentido.
        $io->section('Flota');

        foreach (self::SEGMENTOS as $def) {
            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $def['nombre']]);

            if ($componente === null) {
                continue;
            }

            $this->asegurarFlota($componente, $moneda, $io, $simula);

        }

        if ($this->flotaCreada === 0) {
            $io->text('  la flota ya estaba completa.');
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf('%s %d segmento(s).', $simula ? 'Se crearían' : 'Creados', $creados));

        if ($creados > 0) {
            $io->warning(sprintf(
                'Las %d tarifas nacen a 0: el precio lo pone el operador. La capacidad sí va '
                . 'puesta (Auto 4, Van 8, Minibús 25, Bus 45).',
                count(self::FLOTA) * count(self::SEGMENTOS),
            ));
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
    /**
     * Una tarifa por vehículo, todas privadas y **por grupo**.
     *
     * Devuelve las que había o acaba de crear, en el orden de {@see FLOTA}, para que quien crea
     * el enlace pueda tomar la primera como predeterminada.
     *
     * ⚠️ Se retira de paso la tarifa de relleno que creó la primera versión de este comando —la
     * que se llamaba igual que el componente—: dejarla sería un quinto vehículo sin nombre que
     * alguien acabaría cotizando.
     *
     * @return list<TravelTarifa>
     */
    private function asegurarFlota(
        TravelComponente $componente,
        MaestroMoneda $moneda,
        SymfonyStyle $io,
        bool $simula,
    ): array {
        $existentes = [];

        foreach ($componente->getTarifas() as $t) {
            $existentes[(string) $t->getNombreInterno()] = $t;
        }

        $titulo = $this->textoEspanol($componente->getTitulo());
        $flota = [];

        foreach (self::FLOTA as $vehiculo => $capacidad) {
            $nombre = sprintf('%s · %s', $vehiculo, $titulo);

            if (isset($existentes[$nombre])) {
                $tarifa = $existentes[$nombre];
                $flota[] = $tarifa;

                // La capacidad se sincroniza también en las que ya existían: nacieron sin ella.
                if ($tarifa->getCapacidadMaxima() !== $capacidad) {
                    $io->text(sprintf('  %s · %s hasta %d pax', $simula ? 'pondría' : 'puesta ', $nombre, $capacidad));

                    if (!$simula) {
                        $tarifa->setCapacidadMaxima($capacidad);
                    }
                }

                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creada ', $nombre));
            ++$this->flotaCreada;

            if ($simula) {
                continue;
            }

            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            $tarifa->setNombreInterno($nombre);
            $tarifa->setTitulo([['language' => 'es', 'content' => $vehiculo]]);
            $tarifa->setMoneda($moneda);
            $tarifa->setMonto('0.00');
            $tarifa->setModalidad(TarifaModalidadEnum::PRIVADO);
            $tarifa->setCostoPorGrupo(true);
            $tarifa->setCapacidadMaxima($capacidad);
            $this->em->persist($tarifa);

            $flota[] = $tarifa;
        }

        $relleno = $existentes[(string) $componente->getNombreInterno()] ?? null;

        // ⚠️ El repunte va AQUÍ y no en el llamador, y compara contra `$relleno` en vez de mirar
        // si la predeterminada es null.
        //
        // `em->remove()` no vacía la referencia hasta el flush: al preguntar después,
        // `getTarifaPredeterminada()` seguía devolviendo la tarifa que está a punto de morir, así
        // que el enlace se quedaba sin ninguna. En local no se vio porque el comando se ejecutó
        // dos veces —la segunda encontraba el null y lo arreglaba—; en producción, a la primera,
        // los dos enlaces quedaron vacíos.
        foreach ($componente->getSegmentoComponentesInyectados() as $rel) {
            $actual = $rel->getTarifaPredeterminada();

            if ($flota === [] || ($actual !== null && $actual !== $relleno)) {
                continue;
            }

            $io->text(sprintf('  predeterminada · %s', (string) $flota[0]->getNombreInterno()));

            if (!$simula) {
                $rel->setTarifaPredeterminada($flota[0]);
            }
        }

        if ($relleno !== null && !$simula) {
            $io->text(sprintf('  retirada · tarifa de relleno «%s»', (string) $relleno->getNombreInterno()));
            $this->em->remove($relleno);
        }

        return $flota;
    }

    /** @param list<array{language?: string, content?: string|null}> $i18n */
    private function textoEspanol(array $i18n): string
    {
        foreach ($i18n as $t) {
            if (($t['language'] ?? '') === 'es') {
                return (string) ($t['content'] ?? '');
            }
        }

        return '';
    }
}
