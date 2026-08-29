<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelLugar;
use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\PuntoModoEnum;
use App\Travel\Enum\PuntoTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le da a cada componente de vuelo su segmento narrativo, y los mete todos en el servicio.
 *
 * ## El reparto, que es el mismo de siempre
 *
 * El COMPONENTE es lo que se compra —el ticket de esa ruta, con sus tarifas de equipaje— y el
 * SEGMENTO es lo que el pasajero lee. Ya existían `VUELO-LIM-CUZ` y `VUELO-CUZ-LIM` montados así;
 * las otras 30 rutas tenían componente y no tenían quién las contara.
 *
 * ⚠️ Y sin segmento, una ruta **no se puede meter en un itinerario**: lo que se arrastra a un día
 * es el segmento, no el componente.
 *
 * ## Los extremos son puntos FIJOS, y de ahí sale el recojo
 *
 * Un vuelo empieza y termina en un aeropuerto concreto, nunca en «el alojamiento del pasajero».
 * De esos puntos sale el «dónde recojo / dónde dejo» de la orden de servicio, así que hay que
 * crearlos: sólo existían los de Lima y Cusco.
 *
 * ⚠️ **El texto que se escribe aquí es un marcador de posición.** Los dos segmentos que ya
 * existían llevan una narración de verdad —«el ascenso a los Andes», «dejaremos atrás la brisa del
 * Pacífico»— y eso no lo escribe un comando: es trabajo de quien vende el viaje. Aquí se deja una
 * línea factual para que el segmento exista y se pueda usar, y se marca en la salida cuáles
 * quedan pendientes de redactar.
 *
 * Idempotente por slug: los dos que ya estaban no se tocan.
 */
#[AsCommand(
    name: 'app:travel:crear-segmentos-vuelo',
    description: 'Crea el segmento narrativo de cada ruta de vuelo y los asigna al servicio VUELO.',
)]
final class CrearSegmentosVueloCommand extends Command
{
    private const SERVICIO = 'VUELO';

    /**
     * Ciudad → [código IATA, nombre del lugar]. El IATA es lo que arma el slug, que es la
     * convención que ya siguen `VUELO-LIM-CUZ` y su inverso.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CIUDADES = [
        'Lima' => ['LIM', 'Lima'],
        'Cusco' => ['CUZ', 'Cusco'],
        'Arequipa' => ['AQP', 'Arequipa'],
        'Bogotá' => ['BOG', 'Bogotá'],
        'Cancún' => ['CUN', 'Cancún'],
        'Iquitos' => ['IQT', 'Iquitos'],
        'Juliaca' => ['JUL', 'Juliaca'],
        'Panamá' => ['PTY', 'Panamá'],
        'Piura' => ['PIU', 'Piura'],
        'Punta Cana' => ['PUJ', 'Punta Cana'],
        'Puerto Maldonado' => ['PEM', 'Puerto Maldonado'],
        'Río de Janeiro' => ['GIG', 'Río de Janeiro'],
        'Santiago' => ['SCL', 'Santiago'],
        'Sao Paulo' => ['GRU', 'Sao Paulo'],
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

        $servicio = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => self::SERVICIO]);

        if ($servicio === null) {
            $io->error(sprintf('No existe el servicio %s.', self::SERVICIO));

            return Command::FAILURE;
        }

        $io->title('Segmentos de vuelo');

        /** @var list<TravelComponente> $componentes */
        $componentes = $this->em->getRepository(TravelComponente::class)
            ->findBy(['tipo' => ComponenteTipoEnum::VUELO], ['nombreInterno' => 'ASC']);

        $io->section('Puntos de aeropuerto');
        $puntos = $this->asegurarPuntos($componentes, $io, $simula);

        $io->section('Segmentos');
        $creados = 0;
        $sinRuta = [];

        foreach ($componentes as $c) {
            $ruta = $this->rutaDe((string) $c->getNombreInterno());

            if ($ruta === null) {
                $sinRuta[] = (string) $c->getNombreInterno();
                continue;
            }

            [$origen, $destino] = $ruta;
            $slug = sprintf('VUELO-%s-%s', self::CIUDADES[$origen][0], self::CIUDADES[$destino][0]);

            if ($this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]) !== null) {
                $io->text(sprintf('  ya existe · %s', $slug));
                continue;
            }

            ++$creados;
            $io->text(sprintf('  %s · %-18s %s → %s', $simula ? 'crearía' : 'creado ', $slug, $origen, $destino));

            if ($simula) {
                continue;
            }

            $titulo = sprintf('Vuelo de %s a %s', $origen, $destino);

            $segmento = (new TravelSegmento())
                ->setSlug($slug)
                ->setNombreInterno($titulo)
                ->setTitulo([['language' => 'es', 'content' => $titulo]])
                ->setContenido([['language' => 'es', 'content' => sprintf(
                    'Vuelo de %s a %s. Presentación en el aeropuerto con la antelación que indique la aerolínea.',
                    $origen,
                    $destino,
                )]])
                ->setInicioModo(PuntoModoEnum::FIJO)
                ->setInicioPunto($puntos[$origen] ?? null)
                ->setFinModo(PuntoModoEnum::FIJO)
                ->setFinPunto($puntos[$destino] ?? null);
            $this->em->persist($segmento);

            $this->em->persist(
                (new TravelSegmentoComponente())
                    ->setSegmento($segmento)
                    ->setComponente($c)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setOrden(1),
            );

            $servicio->addSegmento($segmento);
        }

        // Todos los componentes de vuelo al servicio, no sólo los que estrenan segmento: es lo
        // que hace que aparezcan en el desplegable al armar una cotización.
        $io->section('Pool del servicio');
        $añadidos = 0;

        foreach ($componentes as $c) {
            if ($c->getServicios()->contains($servicio)) {
                continue;
            }

            ++$añadidos;

            if (!$simula) {
                $servicio->addComponente($c);
            }
        }

        $io->text(sprintf('  %s %d componente(s) al servicio %s.', $simula ? 'añadiría' : 'añadidos', $añadidos, self::SERVICIO));

        if (!$simula) {
            $this->em->flush();
        }

        if ($sinRuta !== []) {
            $io->newLine();
            $io->note(sprintf(
                "Sin ruta reconocible, se quedan sin segmento (van al pool igual): %s",
                implode(', ', $sinRuta),
            ));
        }

        $io->newLine();
        $io->success(sprintf('%s %d segmento(s).', $simula ? 'Se crearían' : 'Creados', $creados));

        if ($creados > 0) {
            $io->warning('El texto de los nuevos es un marcador de posición: la narración se escribe a mano.');
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * «Vuelo Puerto Maldonado Cusco» → ['Puerto Maldonado', 'Cusco'].
     *
     * ⚠️ No se parte por espacios: cuatro ciudades llevan más de una palabra y «Vuelo Río de
     * Janeiro Lima» daría cualquier cosa. Se busca cuál de las ciudades conocidas encaja al
     * principio, y el resto tiene que ser otra de la lista.
     *
     * @return array{0: string, 1: string}|null
     */
    private function rutaDe(string $nombre): ?array
    {
        $resto = trim(preg_replace('/^Vuelo\s+/u', '', $nombre) ?? '');

        foreach (array_keys(self::CIUDADES) as $origen) {
            if (!str_starts_with($resto, $origen . ' ')) {
                continue;
            }

            $destino = trim(substr($resto, strlen($origen)));

            if (isset(self::CIUDADES[$destino])) {
                return [$origen, $destino];
            }
        }

        return null;
    }

    /**
     * @param list<TravelComponente> $componentes
     * @return array<string, TravelPunto>
     */
    private function asegurarPuntos(array $componentes, SymfonyStyle $io, bool $simula): array
    {
        $necesarias = [];

        foreach ($componentes as $c) {
            $ruta = $this->rutaDe((string) $c->getNombreInterno());

            if ($ruta !== null) {
                $necesarias[$ruta[0]] = true;
                $necesarias[$ruta[1]] = true;
            }
        }

        $puntos = [];

        foreach (array_keys($necesarias) as $ciudad) {
            $nombre = 'Aeropuerto de ' . $ciudad;
            $punto = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => $nombre]);

            if ($punto !== null) {
                $puntos[$ciudad] = $punto;
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $nombre));

            if ($simula) {
                continue;
            }

            $punto = (new TravelPunto())
                ->setNombre($nombre)
                ->setTipo(PuntoTipoEnum::AEROPUERTO)
                ->setLugar($this->asegurarLugar(self::CIUDADES[$ciudad][1]));
            $this->em->persist($punto);
            $puntos[$ciudad] = $punto;
        }

        $this->em->flush();

        return $puntos;
    }

    private function asegurarLugar(string $nombre): TravelLugar
    {
        $lugar = $this->em->getRepository(TravelLugar::class)->findOneBy(['nombre' => $nombre]);

        if ($lugar === null) {
            $lugar = (new TravelLugar())->setNombre($nombre);
            $this->em->persist($lugar);
        }

        return $lugar;
    }
}
