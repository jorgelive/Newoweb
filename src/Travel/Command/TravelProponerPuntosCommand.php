<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelLugar;
use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Enum\PuntoModoEnum;
use App\Travel\Enum\PuntoTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Propone el punto de inicio y de fin de cada segmento, leyendo su nombre.
 *
 * ── Por qué se puede hacer por nombre ───────────────────────────────────────
 * Porque los nombres de segmento de este catálogo son excepcionalmente explícitos: «Traslado a
 * la estación de Poroy», «Viaje en tren de retorno a Ollantaytambo», «Transporte desde el hotel
 * en Lima al Aeropuerto de Lima». No es una heurística sobre texto libre — es leer un dato que
 * ya estaba escrito, sólo que en la columna equivocada para poder consultarlo.
 *
 * Lo que NO se propone se queda `SIN_DEFINIR`, y eso es la respuesta correcta: «Visita a
 * Chinchero» no recoge a nadie, y rellenarle un punto por simetría sería inventarse un dato que
 * después saldría en una orden.
 *
 * ── Idempotente y aditivo ───────────────────────────────────────────────────
 * Sólo escribe sobre extremos que estén `SIN_DEFINIR`. Lo que alguien puso a mano no se toca,
 * ni siquiera cuando la regla diría otra cosa: quien lo puso estaba mirando el caso concreto y
 * este comando sólo mira una cadena.
 *
 * ── El informe importa tanto como la escritura ──────────────────────────────
 * Al final lista los **primeros y últimos segmentos de cada (plantilla, día) que tienen un
 * servicio abarcador** y siguen sin declarar. Esos son exactamente los que lee
 * {@see \App\Travel\Service\TravelPuntosDelServicio} para decir dónde empieza y termina un pool,
 * así que son los que hay que rellenar a mano primero. El resto puede esperar.
 */
#[AsCommand(
    name: 'app:travel:proponer-puntos',
    description: 'Propone inicio y fin geográfico de cada segmento a partir de su nombre.',
)]
final class TravelProponerPuntosCommand extends Command
{
    /**
     * Los puntos que este comando necesita para poder proponer nada.
     *
     * Se crean sólo con `--crear-puntos`, y con la dirección puesta: un punto sin dirección
     * pasa la validación y luego sale en la orden como un nombre a secas, que es justo lo que
     * obliga al proveedor a llamar por teléfono.
     *
     * @var array<string, array{tipo: PuntoTipoEnum, lugar: string, direccion: string}>
     */
    private const array PUNTOS = [
        'Aeropuerto de Cusco' => [
            'tipo' => PuntoTipoEnum::AEROPUERTO,
            'lugar' => 'Cusco',
            'direccion' => 'Aeropuerto Internacional Alejandro Velasco Astete, Av. Velasco Astete s/n, Cusco',
        ],
        'Aeropuerto de Lima' => [
            'tipo' => PuntoTipoEnum::AEROPUERTO,
            'lugar' => 'Lima',
            'direccion' => 'Aeropuerto Internacional Jorge Chávez, Av. Elmer Faucett s/n, Callao',
        ],
        'Estación de Ollantaytambo' => [
            'tipo' => PuntoTipoEnum::ESTACION_TREN,
            'lugar' => 'Valle Sagrado',
            'direccion' => 'Av. Ferrocarril s/n, Ollantaytambo, Urubamba',
        ],
        'Estación de Poroy' => [
            'tipo' => PuntoTipoEnum::ESTACION_TREN,
            'lugar' => 'Cusco',
            'direccion' => 'Estación de Poroy, Poroy, Cusco',
        ],
        'Estación San Pedro' => [
            'tipo' => PuntoTipoEnum::ESTACION_TREN,
            'lugar' => 'Cusco',
            'direccion' => 'Estación San Pedro, Cascaparo s/n, Cusco',
        ],
        // ⚠️ El nombre de la calle es lo que hay; el propio operador avisó de que no todos los
        // trenes llegan al mismo andén. Se deja la referencia vacía para que la rellene quien
        // sepa, en vez de escribir aquí una precisión que no tenemos.
        'Estación de Machu Picchu' => [
            'tipo' => PuntoTipoEnum::ESTACION_TREN,
            'lugar' => 'Machu Picchu',
            'direccion' => 'Av. Imperio de los Incas s/n, Machu Picchu Pueblo',
        ],
        'Machu Picchu Pueblo' => [
            'tipo' => PuntoTipoEnum::PLAZA,
            'lugar' => 'Machu Picchu',
            'direccion' => 'Plaza Manco Cápac, Machu Picchu Pueblo (Aguas Calientes)',
        ],
        'Santuario de Machu Picchu' => [
            'tipo' => PuntoTipoEnum::OTRO,
            'lugar' => 'Machu Picchu',
            'direccion' => 'Puerta de ingreso al Santuario Histórico de Machu Picchu',
        ],
        'Plaza de Armas de Cusco' => [
            'tipo' => PuntoTipoEnum::PLAZA,
            'lugar' => 'Cusco',
            'direccion' => 'Plaza de Armas, Cusco',
        ],
        'Central Hidroeléctrica' => [
            'tipo' => PuntoTipoEnum::OTRO,
            'lugar' => 'Machu Picchu',
            'direccion' => 'Central Hidroeléctrica, Santa Teresa, La Convención',
        ],
        'Vertical Sky Suites' => [
            'tipo' => PuntoTipoEnum::HOTEL,
            'lugar' => 'Valle Sagrado',
            'direccion' => 'Vertical Sky Suites, Valle Sagrado',
        ],
    ];

    /**
     * Dónde se deja a un servicio COMPARTIDO al terminar, por ciudad.
     *
     * Sólo Cusco por ahora, y a propósito: el día que se decida el punto de Lima se añade una
     * fila. Rellenarlo «por completar» pondría a un Lima City Tour dejando a la gente en la
     * Plaza de Armas de Cusco, que compila, pasa los tests y es una orden falsa.
     *
     * @var array<string, string>
     */
    private const array CENTRO_POR_LUGAR = ['Cusco' => 'Plaza de Armas de Cusco'];

    /** La marca de «el hotel del pasajero»: no es un punto, es un modo que resuelve la reserva. */
    private const string HOTEL = '@alojamiento';

    /**
     * Patrón del nombre → qué extremos declara. **Gana la PRIMERA que case**, no todas.
     *
     * Es al revés que en {@see TravelEtiquetarLugaresCommand}, donde las etiquetas se acumulan.
     * Aquí un segmento tiene un único origen y un único destino, así que acumular sería
     * sobrescribir, y el orden de la tabla decidiría en silencio. Con «la primera gana», el
     * orden es una decisión visible: las reglas específicas van arriba.
     *
     * `HOTEL` es la marca de {@see PuntoModoEnum::ALOJAMIENTO} — el hotel del pasajero, que se
     * resuelve al emitir. Cualquier otro valor es el nombre de un punto de {@see self::PUNTOS}.
     *
     * @var list<array{patron: string, inicio?: string, fin?: string}>
     */
    private const array REGLAS = [
        // ── Aeropuertos ─────────────────────────────────────────────────────
        ['patron' => '/^Transporte desde el Aeropuerto de (Cusco|Lima)/iu', 'inicio' => 'Aeropuerto de $1', 'fin' => self::HOTEL],
        ['patron' => '/^Transporte desde el hotel en (Cusco|Lima) al Aeropuerto/iu', 'inicio' => self::HOTEL, 'fin' => 'Aeropuerto de $1'],
        ['patron' => '/^Recepción y traslado (?:al hotel en |a )Cusco/iu', 'inicio' => 'Aeropuerto de Cusco', 'fin' => self::HOTEL],
        ['patron' => '/^Vuelo desde la ciudad de (Cusco|Lima) a la ciudad de (Lima|Cusco)/iu', 'inicio' => 'Aeropuerto de $1', 'fin' => 'Aeropuerto de $2'],

        // ── Tren: ida y vuelta son reglas distintas a propósito ─────────────
        ['patron' => '/^Viaje en tren de retorno a (Ollantaytambo|Poroy)/iu', 'inicio' => 'Estación de Machu Picchu', 'fin' => 'Estación de $1'],
        ['patron' => '/^Viaje en tren desde San Pedro/iu', 'inicio' => 'Estación San Pedro', 'fin' => 'Estación de Machu Picchu'],
        ['patron' => '/^Viaje en tren desde (Ollantaytambo|Poroy)/iu', 'inicio' => 'Estación de $1', 'fin' => 'Estación de Machu Picchu'],

        // ── Traslados a y desde estaciones ──────────────────────────────────
        ['patron' => '/^Traslado en el Valle Sagrado a la estaci[oó]n de Ollantaytambo/iu', 'inicio' => self::HOTEL, 'fin' => 'Estación de Ollantaytambo'],
        ['patron' => '/^Traslado desde la estaci[oó]n de Ollantaytambo/iu', 'inicio' => 'Estación de Ollantaytambo', 'fin' => self::HOTEL],
        ['patron' => '/^(?:Traslado|Conexión) a la estaci[oó]n San Pedro/iu', 'inicio' => self::HOTEL, 'fin' => 'Estación San Pedro'],
        ['patron' => '/^(?:Traslado|Conexión) a la estaci[oó]n de (Ollantaytambo|Poroy)/iu', 'inicio' => self::HOTEL, 'fin' => 'Estación de $1'],
        ['patron' => '/^Traslado Bimodal de Ida \(V[ií]a (Ollantaytambo|Poroy)\)/iu', 'inicio' => self::HOTEL, 'fin' => 'Estación de $1'],
        ['patron' => '/^Retorno Bimodal a Cusco \(V[ií]a (Ollantaytambo|Poroy)\)/iu', 'inicio' => 'Estación de $1', 'fin' => self::HOTEL],

        // ── Machu Picchu: el bus del Santuario ──────────────────────────────
        ['patron' => '/^Ascenso en bus al Santuario/iu', 'inicio' => 'Machu Picchu Pueblo', 'fin' => 'Santuario de Machu Picchu'],
        ['patron' => '/^Descenso en bus a Machu Picchu Pueblo/iu', 'inicio' => 'Santuario de Machu Picchu', 'fin' => 'Machu Picchu Pueblo'],
        ['patron' => '/^(?:Caminata|Descenso caminando) a Machu Picchu Pueblo/iu', 'fin' => 'Machu Picchu Pueblo'],
        ['patron' => '/^Ascenso caminando al Santuario/iu', 'inicio' => 'Machu Picchu Pueblo', 'fin' => 'Santuario de Machu Picchu'],
        ['patron' => '/^Ruta Amaz[oó]nica: Traslado a Hidroel[eé]ctrica/iu', 'inicio' => self::HOTEL, 'fin' => 'Central Hidroeléctrica'],

        // ── Recojos: la marca de que el extremo es el hotel del pasajero ────
        ['patron' => '/^(?:Recojo e inicio de excursión|Recojo en el Hotel|Inicio de Excursión: Recojo)/iu', 'inicio' => self::HOTEL],
        ['patron' => '/^El inicio de la ruta:/iu', 'inicio' => self::HOTEL],

        // ── Retornos ────────────────────────────────────────────────────────
        // «al centro de Cusco» es literal y va a la Plaza; los demás retornos devuelven al hotel.
        ['patron' => '/^Retorno al centro de Cusco/iu', 'fin' => 'Plaza de Armas de Cusco'],
        ['patron' => '/^Retorno (?:hacia la Ciudad de Cusco|Amaz[oó]nico hacia Cusco|a la Lima Moderna|a Lima al Atardecer|hacia el Valle Sagrado)/iu', 'fin' => self::HOTEL],
        ['patron' => '/^Descanso en el Valle Sagrado/iu', 'fin' => self::HOTEL],

        // ── Vertical Sky ────────────────────────────────────────────────────
        ['patron' => '/^Traslado y Arribo de Aventura a Vertical Sky/iu', 'inicio' => self::HOTEL, 'fin' => 'Vertical Sky Suites'],
        ['patron' => '/^Traslado de Salida desde Vertical Sky Suites/iu', 'inicio' => 'Vertical Sky Suites'],

        // ── Traslados largos entre ciudades: hotel a hotel ──────────────────
        ['patron' => '/^Traslado Costero de Lima a Paracas/iu', 'inicio' => self::HOTEL, 'fin' => self::HOTEL],
        ['patron' => '/^Traslado desde (?:el Valle Sagrado|la Ciudad de Cusco)/iu', 'inicio' => self::HOTEL],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No escribe nada: sólo enseña lo que haría.')
            ->addOption('crear-puntos', null, InputOption::VALUE_NONE, 'Crea los puntos maestros que falten.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        $puntos = $this->resolverPuntos($io, (bool) $input->getOption('crear-puntos'), $seco);

        $io->section('Segmentos');
        $resultado = $this->proponerEnSegmentos($io, $puntos, $seco);

        $io->section('Cierre por regla de negocio (privado → hotel · compartido → centro)');
        $porRegla = $this->cerrarAbarcadores($io, $puntos, $seco);

        if (!$seco) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%d extremos por el nombre + %d por regla de negocio %s · %d ya declarados (intactos) · %d segmentos sin regla',
            $resultado['escritos'],
            $porRegla,
            $seco ? 'se escribirían' : 'escritos',
            $resultado['respetados'],
            $resultado['sinRegla'],
        ));

        $this->informeDeCobertura($io, $seco);
        $this->informeDeDiasSinPrincipal($io);

        return Command::SUCCESS;
    }

    /**
     * Los puntos maestros, por nombre. Los que falten se crean sólo si se pidió.
     *
     * @return array<string, TravelPunto>
     */
    private function resolverPuntos(SymfonyStyle $io, bool $crear, bool $seco): array
    {
        /** @var array<string, TravelPunto> $porNombre */
        $porNombre = [];

        foreach ($this->em->getRepository(TravelPunto::class)->findAll() as $punto) {
            $nombre = $punto->getNombre();

            if ($nombre !== null) {
                $porNombre[$nombre] = $punto;
            }
        }

        $faltan = array_values(array_diff(array_keys(self::PUNTOS), array_keys($porNombre)));

        if ($faltan === []) {
            return $porNombre;
        }

        if (!$crear) {
            $io->warning(sprintf(
                "Faltan %d puntos maestros y sin ellos no se puede proponer casi nada.\n"
                . "Vuelve a lanzarlo con --crear-puntos.\nFaltan: %s",
                count($faltan),
                implode(', ', $faltan)
            ));

            return $porNombre;
        }

        $io->section('Puntos maestros');

        foreach ($faltan as $nombre) {
            $definicion = self::PUNTOS[$nombre];
            $punto = new TravelPunto();
            $punto->setNombre($nombre);
            $punto->setTipo($definicion['tipo']);
            $punto->setDireccion($definicion['direccion']);

            $lugar = $this->em->getRepository(TravelLugar::class)->findOneBy(['nombre' => $definicion['lugar']]);

            if ($lugar === null) {
                // No se crea el lugar: el vocabulario de lugares es una decisión de producto y ya
                // tiene su propio comando. Aquí sólo se avisa, y el punto queda sin agrupar — que
                // no impide nada, porque el lugar sólo sirve para buscar en el desplegable.
                $io->writeln(sprintf('  <comment>· sin lugar «%s»</comment> para %s', $definicion['lugar'], $nombre));
            }

            $punto->setLugar($lugar);

            if (!$seco) {
                $this->em->persist($punto);
            }

            $porNombre[$nombre] = $punto;
            $io->writeln(sprintf('  <info>+</info> %s', $nombre));
        }

        if (!$seco) {
            $this->em->flush();
        }

        return $porNombre;
    }

    /**
     * @param array<string, TravelPunto> $puntos
     *
     * @return array{escritos: int, respetados: int, sinRegla: int}
     */
    private function proponerEnSegmentos(SymfonyStyle $io, array $puntos, bool $seco): array
    {
        $escritos = 0;
        $respetados = 0;
        $sinRegla = 0;

        /** @var list<TravelSegmento> $segmentos */
        $segmentos = $this->em->getRepository(TravelSegmento::class)->findBy([], ['nombreInterno' => 'ASC']);

        foreach ($segmentos as $segmento) {
            $nombre = (string) $segmento->getNombreInterno();
            $regla = $this->reglaPara($nombre);

            if ($regla === null) {
                ++$sinRegla;
                continue;
            }

            $cambios = [];

            foreach (['inicio', 'fin'] as $lado) {
                if (!isset($regla[$lado])) {
                    continue;
                }

                $modoActual = $lado === 'inicio' ? $segmento->getInicioModo() : $segmento->getFinModo();

                if ($modoActual->esDeclarado()) {
                    ++$respetados;
                    continue;
                }

                $destino = $this->expandir($regla[$lado], $regla['patron'], $nombre);

                if ($destino === self::HOTEL) {
                    $lado === 'inicio'
                        ? $segmento->setInicioModo(PuntoModoEnum::ALOJAMIENTO)
                        : $segmento->setFinModo(PuntoModoEnum::ALOJAMIENTO);
                    $cambios[] = $lado . ': hotel del pasajero';
                    ++$escritos;
                    continue;
                }

                $punto = $puntos[$destino] ?? null;

                if ($punto === null) {
                    $io->writeln(sprintf('  <error>✗</error> %s → falta el punto «%s»', $nombre, $destino));
                    continue;
                }

                if ($lado === 'inicio') {
                    $segmento->setInicioModo(PuntoModoEnum::FIJO);
                    $segmento->setInicioPunto($punto);
                } else {
                    $segmento->setFinModo(PuntoModoEnum::FIJO);
                    $segmento->setFinPunto($punto);
                }

                $cambios[] = $lado . ': ' . $destino;
                ++$escritos;
            }

            if ($cambios !== []) {
                $io->writeln(sprintf('  <info>·</info> %-58s %s', mb_substr($nombre, 0, 57), implode(' · ', $cambios)));
            }
        }

        return ['escritos' => $escritos, 'respetados' => $respetados, 'sinRegla' => $sinRegla];
    }

    /** @return array{patron: string, inicio?: string, fin?: string}|null */
    private function reglaPara(string $nombre): ?array
    {
        foreach (self::REGLAS as $regla) {
            if (preg_match($regla['patron'], $nombre) === 1) {
                return $regla;
            }
        }

        return null;
    }

    /**
     * Resuelve los `$1` del destino con lo que capturó el patrón.
     *
     * Es lo que permite que «Transporte desde el Aeropuerto de (Cusco|Lima)» sea UNA regla en
     * vez de dos, y sobre todo que no puedan divergir cuando alguien edite una y no la otra.
     */
    private function expandir(string $destino, string $patron, string $nombre): string
    {
        if (!str_contains($destino, '$')) {
            return $destino;
        }

        if (preg_match($patron, $nombre, $m) !== 1) {
            return $destino;
        }

        return (string) preg_replace_callback(
            '/\$(\d)/',
            static fn (array $c): string => mb_convert_case(mb_strtolower($m[(int) $c[1]] ?? ''), MB_CASE_TITLE, 'UTF-8'),
            $destino
        );
    }


    /**
     * Segunda pasada: cierra los extremos que el nombre no supo decir, por REGLA DE NEGOCIO.
     *
     * La primera pasada lee el nombre del segmento, y hay plantillas donde eso no dice nada del
     * sitio: «Full Day Humantay Estandar» tiene un único segmento —«Laguna de Humantay»— que es
     * a la vez el primero y el último del día. El nombre habla de la actividad, no de dónde
     * empieza ni dónde acaba.
     *
     * Ahí decide la regla que da el operador, y sale del tipo del servicio:
     *
     * ```
     * privado (transporte · privada)   recoge en el hotel  →  devuelve al hotel
     * compartido (pool)                recoge en el hotel  →  deja en el centro de la ciudad
     * ```
     *
     * ⚠️ **Es una regla, no una lectura**, y por eso va aparte y se anuncia como tal en la
     * salida. Sigue siendo aditiva —sólo escribe sobre `SIN_DEFINIR`— pero quien lea el informe
     * tiene que poder distinguir lo que se dedujo de un nombre explícito de lo que se dio por
     * supuesto.
     *
     * ⚠️ **Sin centro definido para esa ciudad, se devuelve al hotel y se dice.** Hoy sólo Cusco
     * lo tiene. Un Lima City Tour cayendo en «Plaza de Armas de Cusco» por completar la simetría
     * sería exactamente el tipo de dato plausible y falso que esto viene a evitar.
     *
     * @param array<string, TravelPunto> $puntos
     */
    private function cerrarAbarcadores(SymfonyStyle $io, array $puntos, bool $seco): int
    {
        $escritos = 0;

        /** @var list<TravelSegmentoComponente> $abarcadores */
        $abarcadores = $this->em->getRepository(TravelSegmentoComponente::class)
            ->findBy(['horaServicioCompleto' => true]);

        foreach ($abarcadores as $sc) {
            $itinerario = $sc->getItinerarioContexto();
            $tipo = $sc->getComponente()?->getTipo();

            if ($itinerario === null || $tipo === null) {
                continue;
            }

            $extremos = $this->extremosDe($itinerario, $sc->getDia());
            [$primero, $ultimo] = $extremos;

            if ($primero === null || $ultimo === null) {
                continue;
            }

            $etiqueta = sprintf(
                '%s [%s]',
                mb_substr((string) $itinerario->getNombreInterno(), 0, 40),
                $tipo->esCompartido() ? 'compartido' : 'privado'
            );

            // Los dos empiezan igual: pasando por el hotel de cada pasajero.
            if (!$primero->getInicioModo()->esDeclarado()) {
                $primero->setInicioModo(PuntoModoEnum::ALOJAMIENTO);
                ++$escritos;
                $io->writeln(sprintf('  <info>·</info> %-54s inicio: hotel del pasajero', $etiqueta));
            }

            if ($ultimo->getFinModo()->esDeclarado()) {
                continue;
            }

            $centro = $tipo->esCompartido() ? $this->centroDe($sc, $puntos) : null;

            if ($centro !== null) {
                $ultimo->setFinModo(PuntoModoEnum::FIJO);
                $ultimo->setFinPunto($centro);
                $io->writeln(sprintf('  <info>·</info> %-54s fin: %s', $etiqueta, $centro->getNombre()));
            } else {
                $ultimo->setFinModo(PuntoModoEnum::ALOJAMIENTO);
                $io->writeln(sprintf(
                    '  <info>·</info> %-54s fin: hotel del pasajero%s',
                    $etiqueta,
                    $tipo->esCompartido() ? ' <comment>(compartido, pero su ciudad no tiene centro definido)</comment>' : ''
                ));
            }

            ++$escritos;
        }

        return $escritos;
    }

    /**
     * El punto «centro de la ciudad» que le toca a un servicio, por sus lugares.
     *
     * @param array<string, TravelPunto> $puntos
     */
    private function centroDe(TravelSegmentoComponente $sc, array $puntos): ?TravelPunto
    {
        foreach ($sc->getComponente()?->getLugares() ?? [] as $lugar) {
            $centro = self::CENTRO_POR_LUGAR[(string) $lugar->getNombre()] ?? null;

            if ($centro !== null && isset($puntos[$centro])) {
                return $puntos[$centro];
            }
        }

        return null;
    }

    /**
     * El primer y el último segmento de una (plantilla, día).
     *
     * @return array{0: ?TravelSegmento, 1: ?TravelSegmento}
     */
    private function extremosDe(TravelItinerario $itinerario, ?int $dia): array
    {
        $qb = $this->em->getRepository(TravelItinerarioSegmentoRel::class)
            ->createQueryBuilder('r')
            ->andWhere('r.itinerario = :i')
            // Con el tipo explícito — ver TravelPuntosDelServicio::extremosDelDia().
            ->setParameter('i', $itinerario->getId(), UuidType::NAME)
            ->addOrderBy('r.dia', 'ASC')
            ->addOrderBy('r.orden', 'ASC');

        if ($dia !== null) {
            $qb->andWhere('r.dia = :d')->setParameter('d', $dia);
        }

        /** @var list<TravelItinerarioSegmentoRel> $rels */
        $rels = $qb->getQuery()->getResult();

        if ($rels === []) {
            return [null, null];
        }

        return [$rels[0]->getSegmento(), $rels[count($rels) - 1]->getSegmento()];
    }


    /**
     * Días de una plantilla de VARIOS DÍAS que no tienen servicio principal declarado.
     *
     * No es una lista de errores: un día compuesto por tren + bus + ingreso + guía no tiene un
     * servicio que lo abarque, y está bien que no lo tenga. Es una lista **para revisar**, y sale
     * porque el caso contrario —un paquete de proveedor externo que SÍ es el día entero— hoy no
     * está declarado en ninguna de las cinco plantillas de varios días del catálogo.
     *
     * ## El patrón que cubre
     *
     * Un Camino Inca de 4 días: se crea un componente por día —«Segundo día Camino Inca»—, aunque
     * sea de **costo 0**, sólo para que aporte hora de inicio y de fin, y se promueve aquí. La
     * unicidad es por `(plantilla, día)`, así que los cuatro conviven sin pisarse.
     *
     * ⚠️ **La Categoría Operativa de ese componente decide si sirve para algo.** Con `extras` o
     * `ticket`, {@see \App\Travel\Enum\ComponenteTipoEnum::puntosDeServicio()} devuelve `NINGUNO`
     * y la promoción no aporta ningún punto de recojo — sin dar error. Tiene que llevar el tipo
     * que refleje la realidad.
     */
    private function informeDeDiasSinPrincipal(SymfonyStyle $io): void
    {
        /** @var list<TravelItinerarioSegmentoRel> $rels */
        $rels = $this->em->getRepository(TravelItinerarioSegmentoRel::class)->findAll();

        /** @var array<string, array{nombre: string, dias: array<int, true>}> $porItinerario */
        $porItinerario = [];

        foreach ($rels as $rel) {
            $itinerario = $rel->getItinerario();
            $id = $itinerario->getId()?->toRfc4122();

            if ($id === null) {
                continue;
            }

            $porItinerario[$id]['nombre'] = (string) $itinerario->getNombreInterno();
            $porItinerario[$id]['dias'][$rel->getDia()] = true;
        }

        /** @var array<string, true> $cubiertos */
        $cubiertos = [];

        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['horaServicioCompleto' => true]) as $sc) {
            $id = $sc->getItinerarioContexto()?->getId()?->toRfc4122();

            if ($id === null) {
                continue;
            }

            // Día `null` significa «todos los días» — así lo trata el listener de unicidad—, así
            // que cubre la plantilla entera. Es la lectura conservadora: evita sacar en la lista
            // una plantilla que alguien ya resolvió de esa forma.
            $cubiertos[$id . '|' . ($sc->getDia() ?? '*')] = true;
        }

        $pendientes = [];

        foreach ($porItinerario as $id => $datos) {
            $dias = array_keys($datos['dias']);

            if (count($dias) < 2 || isset($cubiertos[$id . '|*'])) {
                continue;
            }

            sort($dias);
            $sinPrincipal = array_values(array_filter($dias, static fn (int $d): bool => !isset($cubiertos[$id . '|' . $d])));

            if ($sinPrincipal !== []) {
                $pendientes[] = sprintf(
                    '%s — días %s de %d sin servicio principal',
                    $datos['nombre'],
                    implode(', ', $sinPrincipal),
                    count($dias)
                );
            }
        }

        if ($pendientes === []) {
            return;
        }

        $io->section('Plantillas de varios días: días sin servicio principal (para revisar)');
        $io->listing($pendientes);
        $io->writeln(
            "  <comment>No es un error: un día de tren + bus + ingreso + guía no tiene un servicio que lo\n"
            . "  abarque. Sí lo es si ese día es un paquete de proveedor externo — un Camino Inca, un\n"
            . "  trekking—: ahí se crea un componente por día (aunque sea de costo 0) con su hora de\n"
            . "  inicio y fin, y se marca «Servicio principal del día».\n"
            . "  Ojo con la Categoría Operativa: con «extras» o «ticket» no aporta puntos de recojo.</comment>"
        );
    }

    /**
     * Qué queda por rellenar, ordenado por lo que de verdad hace falta.
     *
     * Sólo mira los primeros y últimos segmentos de las (plantilla, día) que tienen un servicio
     * abarcador: son los únicos cuyo hueco se convierte en una orden sin dirección. Un segmento
     * intermedio sin puntos no molesta a nadie, y meterlo en la lista la volvería inservible.
     */
    private function informeDeCobertura(SymfonyStyle $io, bool $seco): void
    {
        /** @var list<TravelSegmentoComponente> $abarcadores */
        $abarcadores = $this->em->getRepository(TravelSegmentoComponente::class)
            ->findBy(['horaServicioCompleto' => true]);

        $pendientes = [];
        $sinPlantilla = [];

        foreach ($abarcadores as $sc) {
            $itinerario = $sc->getItinerarioContexto();

            // ⚠️ Se saltan, pero NO en silencio. Sin plantilla no hay día que abarcar, así que
            // la marca no significa nada y el servicio cae a los extremos de su propio segmento.
            // Callarlos haría que el informe dijera «12 revisados» cuando hay 17, que es la forma
            // más fácil de dar por cubierto lo que no lo está.
            if ($itinerario === null) {
                $sinPlantilla[] = sprintf(
                    '%s — en «%s»',
                    $sc->getComponente()?->getNombre() ?? '?',
                    $sc->getSegmento()?->getNombreInterno() ?? '?'
                );
                continue;
            }

            $qb = $this->em->getRepository(TravelItinerarioSegmentoRel::class)
                ->createQueryBuilder('r')
                ->andWhere('r.itinerario = :i')
                // Con el tipo explícito — ver TravelPuntosDelServicio::extremosDelDia().
                ->setParameter('i', $itinerario->getId(), UuidType::NAME)
                ->addOrderBy('r.dia', 'ASC')
                ->addOrderBy('r.orden', 'ASC');

            if ($sc->getDia() !== null) {
                $qb->andWhere('r.dia = :d')->setParameter('d', $sc->getDia());
            }

            /** @var list<TravelItinerarioSegmentoRel> $rels */
            $rels = $qb->getQuery()->getResult();

            if ($rels === []) {
                continue;
            }

            $primero = $rels[0]->getSegmento();
            $ultimo = $rels[count($rels) - 1]->getSegmento();

            $falta = [];

            if ($primero !== null && !$primero->getInicioModo()->esDeclarado()) {
                $falta[] = 'inicio ← ' . $primero->getNombreInterno();
            }

            if ($ultimo !== null && !$ultimo->getFinModo()->esDeclarado()) {
                $falta[] = 'fin ← ' . $ultimo->getNombreInterno();
            }

            if ($falta !== []) {
                $pendientes[] = sprintf(
                    '%s (día %s) — %s',
                    $itinerario->getNombreInterno(),
                    $sc->getDia() ?? '·',
                    implode(' · ', $falta)
                );
            }
        }

        if ($sinPlantilla !== []) {
            $io->section('Marcados como «abarcan el día» pero SIN plantilla');
            $io->listing($sinPlantilla);
            $io->writeln(
                '  <comment>La marca ahí no significa nada: sin plantilla no hay día que abarcar. '
                . 'Se arregla asignándoles su itinerario de contexto.</comment>'
            );
        }

        $io->section(sprintf(
            'Servicios que abarcan el día y aún no saben dónde empiezan o terminan%s',
            // ⚠️ En seco las entidades YA están modificadas en memoria, así que esto no dice
            // cómo está la base sino cómo QUEDARÍA. Es la pregunta útil —«¿con esto basta?»—
            // pero leído como estado actual sería falso, y de ahí la coletilla.
            $seco ? ' (si se aplicara lo de arriba)' : ''
        ));

        if ($pendientes === []) {
            $io->writeln('  <info>Ninguno: todos los abarcadores tienen sus dos extremos declarados.</info>');

            return;
        }

        $io->listing($pendientes);
        $io->note(sprintf(
            '%d incompletos de los %d que tienen plantilla (%d en total, %d sin plantilla). '
            . 'Se rellenan editando el SEGMENTO que se indica, no el componente.',
            count($pendientes),
            count($abarcadores) - count($sinPlantilla),
            count($abarcadores),
            count($sinPlantilla)
        ));
    }
}
