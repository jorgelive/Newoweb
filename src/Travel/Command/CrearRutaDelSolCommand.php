<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelComponenteItem;
use App\Travel\Entity\TravelItemDiccionario;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelLugar;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\ItemModoEnum;
use App\Travel\Enum\PuntoModoEnum;
use App\Travel\Enum\PuntoTipoEnum;
use App\Travel\Enum\TarifaModalidadEnum;
use App\Entity\Maestro\MaestroMoneda;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La Ruta del Sol: el bus turístico de un día entre Cusco y Puno, en los dos sentidos (23/10/2026).
 *
 * ── Qué es ──────────────────────────────────────────────────────────────────
 * Un bus con guía que hace el altiplano de día —07:00 a 17:30— parando en Andahuaylillas, Raqchi,
 * Sicuani (almuerzo), La Raya y Pukará. Lo operan varias empresas que van prácticamente en
 * caravana; Jorge trabaja desde hace años con **Turismo Mer**, y por ahora es el único prestador.
 * Precio: US$ 50 (Jorge). Fuentes: las webs de Turismo Mer, Inka Express y Avalos Tours.
 *
 * ── Cómo se arma ────────────────────────────────────────────────────────────
 *
 *   plantilla Cusco → Puno     SAL Cusco · Andahuaylillas · Raqchi · Almuerzo · La Raya · Pukará · LLEG Puno
 *   plantilla Puno → Cusco     SAL Puno · Pukará · La Raya · Almuerzo · Raqchi · Andahuaylillas · LLEG Cusco
 *
 * - Las paradas son segmentos COMPARTIDOS; cada plantilla los ordena según su sentido.
 * - El bus («Pool Ruta del Sol», que ya existía huérfano con una tarifa «Turismo Mer» sin prestador)
 *   cuelga del segmento de salida de cada sentido, con la hora de toda la excursión promovida. Su
 *   tarifa pasa a tener a Mer como prestador y comprador, y US$ 50.
 * - **Las paradas no llevan hora**: sólo Inka Express publica la de cada una, las empresas salen a
 *   06:10, 06:45 o 07:00 y van en caravana. Se colocan por el orden de la plantilla, y el horario
 *   que se promete es el de la excursión entera.
 * - **Las entradas NO están incluidas** en ninguna de las tres empresas: van como «No incluye»,
 *   atadas a cada plantilla y sin tarifa por defecto (depende de la procedencia). Se reutilizan las
 *   de Andahuaylillas y Raqchi, que existían huérfanas, y se crea la del Museo Lítico de Pukará.
 * - El almuerzo buffet va DENTRO del precio del bus, como un ítem, igual que el del Titicaca.
 * - Los terminales de Turismo Mer —en San Sebastián (Cusco) y en la avenida Costanera (Puno), no
 *   el Terminal Terrestre— y los cuatro traslados hotel ↔ terminal, que ninguna empresa incluye.
 *
 * Idempotente: servicio por código, plantillas y segmentos por slug, puntos por nombre, entradas por
 * nombre interno, ítems por (componente, término). Cada cosa en su pasada (§4 quater del doc).
 */
#[AsCommand(
    name: 'app:travel:crear-ruta-del-sol',
    description: 'Crea la Ruta del Sol Cusco ↔ Puno: servicio, segmentos, plantillas, entradas y traslados.',
    hidden: true,
)]
final class CrearRutaDelSolCommand extends Command
{
    private const string SERVICIO = 'RUTA_SOL';
    private const string SERVICIO_NOMBRE = 'Ruta del Sol (bus turístico Cusco ↔ Puno)';
    private const string SERVICIO_TITULO = 'Ruta del Sol';
    private const string PROVEEDOR = 'Turismo Mer';
    private const string BUS = 'Pool Ruta del Sol';
    private const string BUS_MONTO = '50.00';
    private const string HORA = '07:00';
    private const string HORA_FIN = '17:30';

    /** Nombre del punto → [ciudad, dirección]. */
    private const array PUNTOS = [
        'Terminal Turismo Mer Cusco' => ['Cusco', 'Av. Pacífico J-9-1, Urb. San Antonio, San Sebastián, Cusco'],
        'Terminal Turismo Mer Puno' => ['Puno', 'Av. Costanera 430, Puno'],
    ];

    /**
     * @var array<string, array{nombre: string, titulo: string, contenido: string, inicio: PuntoModoEnum,
     *                          fin: PuntoModoEnum, inicioPunto?: string, finPunto?: string}>
     */
    private const array SEGMENTOS = [
        'TRANS_BUS-RSOL-CUZ_PUN' => [
            'nombre' => 'Ruta del Sol: salida en bus turístico de Cusco a Puno',
            'titulo' => 'En bus turístico de Cusco a Puno',
            'contenido' => 'Salimos temprano desde el terminal de la empresa, en San Sebastián, en un bus turístico '
                . 'con guía bilingüe, para recorrer de día el altiplano entre Cusco y Puno con paradas en los '
                . 'sitios más importantes del camino. A bordo hay bebidas calientes, wifi satelital y oxígeno. '
                . 'Conviene estar en el terminal unos 20 minutos antes para embarcar el equipaje.',
            'inicio' => PuntoModoEnum::FIJO, 'fin' => PuntoModoEnum::SIN_DEFINIR,
            'inicioPunto' => 'Terminal Turismo Mer Cusco',
        ],
        'TRANS_BUS-RSOL-PUN_CUZ' => [
            'nombre' => 'Ruta del Sol: salida en bus turístico de Puno a Cusco',
            'titulo' => 'En bus turístico de Puno a Cusco',
            'contenido' => 'Salimos temprano desde el terminal de la empresa, en la avenida Costanera de Puno, en '
                . 'un bus turístico con guía bilingüe, para recorrer de día el altiplano entre Puno y Cusco con '
                . 'paradas en los sitios más importantes del camino. A bordo hay bebidas calientes, wifi '
                . 'satelital y oxígeno. Conviene estar en el terminal unos 30 minutos antes para embarcar el '
                . 'equipaje.',
            'inicio' => PuntoModoEnum::FIJO, 'fin' => PuntoModoEnum::SIN_DEFINIR,
            'inicioPunto' => 'Terminal Turismo Mer Puno',
        ],
        'VIS-RSOL-ANDAHUAYLILLAS' => [
            'nombre' => 'Ruta del Sol: iglesia de Andahuaylillas',
            'titulo' => 'Andahuaylillas, la Capilla Sixtina de América',
            'contenido' => 'Visita guiada a la iglesia de San Pedro Apóstol de Andahuaylillas, del siglo XVII, '
                . 'cuyos muros y techos cubiertos de pintura mural y pan de oro le han valido el nombre de '
                . '«Capilla Sixtina de América». Dentro no se permiten fotos.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR, 'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-RSOL-RAQCHI' => [
            'nombre' => 'Ruta del Sol: complejo arqueológico de Raqchi',
            'titulo' => 'Raqchi y el Templo de Wiracocha',
            'contenido' => 'Visita guiada al complejo arqueológico de Raqchi, donde se levanta el Templo de '
                . 'Wiracocha, uno de los edificios incas más grandes que se conservan, con su gran muro central '
                . 'de adobe sobre base de piedra, junto a los antiguos depósitos y viviendas.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR, 'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ALM-RSOL-SICUANI' => [
            'nombre' => 'Ruta del Sol: almuerzo buffet en Sicuani',
            'titulo' => 'Almuerzo buffet en Sicuani',
            'contenido' => 'Paramos a almorzar en un restaurante turístico a las afueras de Sicuani, con un buffet '
                . 'de cocina peruana y regional. Las bebidas frías del almuerzo no están incluidas.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR, 'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-RSOL-LA_RAYA' => [
            'nombre' => 'Ruta del Sol: abra La Raya',
            'titulo' => 'Abra La Raya, a 4335 metros',
            'contenido' => 'Breve parada en el punto más alto del recorrido, el abra La Raya, a 4335 metros, límite '
                . 'entre Cusco y Puno, con vistas a los nevados de la cordillera y a las llamas y alpacas que '
                . 'pastan en la zona.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR, 'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-RSOL-PUKARA' => [
            'nombre' => 'Ruta del Sol: Museo Lítico de Pukará',
            'titulo' => 'Museo Lítico de Pukará',
            'contenido' => 'Visita guiada al museo de Pukará, que reúne las esculturas de piedra de la cultura '
                . 'Pukará, anterior a los incas y una de las primeras del altiplano. Dentro no se permiten fotos.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR, 'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'TRANS_BUS-RSOL-LLEG_PUN' => [
            'nombre' => 'Ruta del Sol: llegada a Puno',
            'titulo' => 'Llegada a Puno',
            'contenido' => 'Llegamos a Puno a media tarde, al terminal de la empresa en la avenida Costanera, '
                . 'junto al lago Titicaca.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR, 'fin' => PuntoModoEnum::FIJO,
            'finPunto' => 'Terminal Turismo Mer Puno',
        ],
        'TRANS_BUS-RSOL-LLEG_CUZ' => [
            'nombre' => 'Ruta del Sol: llegada a Cusco',
            'titulo' => 'Llegada a Cusco',
            'contenido' => 'Llegamos a Cusco a media tarde, al terminal de la empresa en San Sebastián.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR, 'fin' => PuntoModoEnum::FIJO,
            'finPunto' => 'Terminal Turismo Mer Cusco',
        ],
    ];

    /**
     * @var list<array{slug: string, nombre: string, titulo: string, segmentos: list<string>}>
     */
    private const array PLANTILLAS = [
        [
            'slug' => '1D RSOL: CUZ PUN',
            'nombre' => 'Ruta del Sol: Cusco → Puno',
            'titulo' => 'Ruta del Sol: de Cusco a Puno',
            'segmentos' => ['TRANS_BUS-RSOL-CUZ_PUN', 'VIS-RSOL-ANDAHUAYLILLAS', 'VIS-RSOL-RAQCHI', 'ALM-RSOL-SICUANI',
                'VIS-RSOL-LA_RAYA', 'VIS-RSOL-PUKARA', 'TRANS_BUS-RSOL-LLEG_PUN'],
        ],
        [
            'slug' => '1D RSOL: PUN CUZ',
            'nombre' => 'Ruta del Sol: Puno → Cusco',
            'titulo' => 'Ruta del Sol: de Puno a Cusco',
            'segmentos' => ['TRANS_BUS-RSOL-PUN_CUZ', 'VIS-RSOL-PUKARA', 'VIS-RSOL-LA_RAYA', 'ALM-RSOL-SICUANI',
                'VIS-RSOL-RAQCHI', 'VIS-RSOL-ANDAHUAYLILLAS', 'TRANS_BUS-RSOL-LLEG_CUZ'],
        ],
    ];

    /**
     * Entrada → segmento de la parada. La de Pukará se crea; las otras dos existían.
     *
     * @var array<string, string>
     */
    private const array ENTRADAS = [
        'Ingreso a Andahuaylillas' => 'VIS-RSOL-ANDAHUAYLILLAS',
        'Ingreso a Raqchi' => 'VIS-RSOL-RAQCHI',
        'Ingreso al Museo Lítico de Pukará' => 'VIS-RSOL-PUKARA',
    ];

    private const string PUKARA_MONTO = '15.00';

    /** Lo que incluye el bus, del diccionario existente. */
    private const array ITEMS = [
        'Transporte en Bus con A/C', 'Guia Profesional', 'Almuerzo Buffet', 'Snacks', 'Tanque de Oxígeno',
        'Wifi a bordo (solo en lugares con señal de datos) y cargador de celular',
    ];

    /**
     * Los traslados hotel ↔ terminal de Mer, con el componente que ya cobra cada uno en su ciudad.
     *
     * @var list<array{slug: string, nombre: string, titulo: string, contenido: string, servicio: string,
     *                 componente: string, hora: string, inicio: PuntoModoEnum, fin: PuntoModoEnum,
     *                 inicioPunto?: string, finPunto?: string}>
     */
    private const array TRASLADOS = [
        [
            'slug' => 'TRANS-HTL_CUZ-TERM_MER_CUZ', 'servicio' => 'TRF_CUZ', 'hora' => '06:10',
            'nombre' => 'Traslado del hotel al terminal de la Ruta del Sol en Cusco',
            'titulo' => 'Al terminal de la Ruta del Sol',
            'contenido' => 'Les recogemos en su hotel temprano y les dejamos en el terminal del bus turístico, en '
                . 'San Sebastián, con tiempo para embarcar el equipaje.',
            'componente' => 'Transporte Htl Cusco ↔ Paradero Cusco (ida o vuelta)',
            'inicio' => PuntoModoEnum::ALOJAMIENTO, 'fin' => PuntoModoEnum::FIJO, 'finPunto' => 'Terminal Turismo Mer Cusco',
        ],
        [
            'slug' => 'TRANS-TERM_MER_CUZ-HTL_CUZ', 'servicio' => 'TRF_CUZ', 'hora' => '17:30',
            'nombre' => 'Traslado del terminal de la Ruta del Sol en Cusco al hotel',
            'titulo' => 'Llegada a Cusco y traslado al hotel',
            'contenido' => 'A la llegada del bus turístico les esperamos en el terminal, en San Sebastián, y les '
                . 'llevamos a su hotel.',
            'componente' => 'Transporte Htl Cusco ↔ Paradero Cusco (ida o vuelta)',
            'inicio' => PuntoModoEnum::FIJO, 'fin' => PuntoModoEnum::ALOJAMIENTO, 'inicioPunto' => 'Terminal Turismo Mer Cusco',
        ],
        [
            'slug' => 'TRANS-HTL_PUN-TERM_MER_PUN', 'servicio' => 'TRF_PUN', 'hora' => '06:10',
            'nombre' => 'Traslado del hotel al terminal de la Ruta del Sol en Puno',
            'titulo' => 'Al terminal de la Ruta del Sol',
            'contenido' => 'Les recogemos en su hotel temprano y les dejamos en el terminal del bus turístico, en la '
                . 'avenida Costanera, con tiempo para embarcar el equipaje.',
            'componente' => 'Transporte urbano en Puno',
            'inicio' => PuntoModoEnum::ALOJAMIENTO, 'fin' => PuntoModoEnum::FIJO, 'finPunto' => 'Terminal Turismo Mer Puno',
        ],
        [
            'slug' => 'TRANS-TERM_MER_PUN-HTL_PUN', 'servicio' => 'TRF_PUN', 'hora' => '17:30',
            'nombre' => 'Traslado del terminal de la Ruta del Sol en Puno al hotel',
            'titulo' => 'Llegada a Puno y traslado al hotel',
            'contenido' => 'A la llegada del bus turístico les esperamos en el terminal, en la avenida Costanera, y '
                . 'les llevamos a su hotel.',
            'componente' => 'Transporte urbano en Puno',
            'inicio' => PuntoModoEnum::FIJO, 'fin' => PuntoModoEnum::ALOJAMIENTO, 'inicioPunto' => 'Terminal Turismo Mer Puno',
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
        $seco = (bool) $input->getOption('dry-run');

        $mer = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::PROVEEDOR])
            ?? throw new \RuntimeException('Falta la organización ' . self::PROVEEDOR);
        $bus = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => self::BUS])
            ?? throw new \RuntimeException('Falta el componente ' . self::BUS);

        // ── Puntos ───────────────────────────────────────────────────────────────────
        $io->section('Terminales de Turismo Mer');
        /** @var array<string, TravelPunto> $puntos */
        $puntos = [];
        foreach (self::PUNTOS as $nombre => [$ciudad, $direccion]) {
            $punto = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => $nombre]);
            if ($punto instanceof TravelPunto) {
                $io->text(sprintf('  ya existe · %s', $nombre));
                $puntos[$nombre] = $punto;
                continue;
            }

            $io->text(sprintf('  %s · %s (%s)', $seco ? 'crearía' : 'creado ', $nombre, $direccion));
            $punto = (new TravelPunto())
                ->setNombre($nombre)
                ->setTipo(PuntoTipoEnum::TERMINAL_BUS)
                ->setLugar($this->lugar($ciudad))
                ->setDireccion($direccion)
                ->setOrganizacion($mer);
            if (!$seco) {
                $this->em->persist($punto);
            }
            $puntos[$nombre] = $punto;
        }

        // ── Servicio ─────────────────────────────────────────────────────────────────
        $io->section('Servicio');
        $servicio = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => self::SERVICIO]);
        if ($servicio instanceof TravelServicio) {
            $io->text(sprintf('  ya existe · %s', self::SERVICIO));
        } else {
            $io->text(sprintf('  %s · %s — %s', $seco ? 'crearía' : 'creado ', self::SERVICIO, self::SERVICIO_NOMBRE));
            $servicio = (new TravelServicio())
                ->setCodigo(self::SERVICIO)
                ->setNombreInterno(self::SERVICIO_NOMBRE)
                ->setTitulo([['language' => 'es', 'content' => self::SERVICIO_TITULO]]);
            if (!$seco) {
                $this->em->persist($servicio);
            }
        }

        // ── La tarifa del bus: Mer como prestador y comprador, US$ 50 ─────────────────
        $io->section('Tarifa del bus');
        $tarifaBus = null;
        foreach ($bus->getTarifas() as $t) {
            $tarifaBus ??= $t;
        }
        if (!$tarifaBus instanceof TravelTarifa) {
            $io->error('«Pool Ruta del Sol» no tiene tarifa.');

            return Command::FAILURE;
        }
        if ($tarifaBus->getPrestador() !== $mer || $tarifaBus->getMonto() !== self::BUS_MONTO) {
            $io->text(sprintf('  %s · «%s» %s %s → Turismo Mer, US$ %s, compartido',
                $seco ? 'cambiaría' : 'cambiada', $tarifaBus->getNombreInterno(), $tarifaBus->getMoneda()?->getId(),
                $tarifaBus->getMonto(), self::BUS_MONTO));
            if (!$seco) {
                // El proveedor va en la tarifa, no en el nombre (§4 quater del doc de carga).
                $tarifaBus->setNombreInterno(self::BUS . ' · Compartido');
                $tarifaBus->setTitulo([['language' => 'es', 'content' => 'Compartido']]);
                $tarifaBus->setMonto(self::BUS_MONTO);
                $tarifaBus->setModalidad(TarifaModalidadEnum::COMPARTIDO);
                $tarifaBus->setPrestador($mer);
                $tarifaBus->setComprador($mer);
                $tarifaBus->setNombreParaPrestador('Ruta del Sol Cusco ↔ Puno');
            }
        } else {
            $io->text('  ya está · Turismo Mer, US$ ' . self::BUS_MONTO);
        }

        // ── Entradas ─────────────────────────────────────────────────────────────────
        $io->section('Entradas (no incluidas)');
        /** @var array<string, TravelComponente> $entradas */
        $entradas = [];
        foreach (array_keys(self::ENTRADAS) as $nombre) {
            $entrada = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre]);
            if ($entrada instanceof TravelComponente) {
                $io->text(sprintf('  ya existe · %s', $nombre));
                $entradas[$nombre] = $entrada;
                continue;
            }

            $io->text(sprintf('  %s · %s, S/ %s', $seco ? 'crearía' : 'creado ', $nombre, self::PUKARA_MONTO));
            $entrada = (new TravelComponente())
                ->setNombreInterno($nombre)
                ->setTitulo([['language' => 'es', 'content' => $nombre]])
                ->setTipo(ComponenteTipoEnum::TICKET_HORARIO_VAR);
            $tarifa = new TravelTarifa();
            $tarifa->setComponente($entrada);
            $tarifa->setNombreInterno($nombre . ' · General');
            $tarifa->setTitulo([['language' => 'es', 'content' => 'General']]);
            $tarifa->setMoneda($this->moneda('PEN'));
            $tarifa->setMonto(self::PUKARA_MONTO);
            if (!$seco) {
                $this->em->persist($entrada);
                $this->em->persist($tarifa);
            }
            $entradas[$nombre] = $entrada;
        }

        // ── Segmentos ────────────────────────────────────────────────────────────────
        $io->section('Segmentos');
        /** @var array<string, TravelSegmento> $segmentos */
        $segmentos = [];
        foreach (self::SEGMENTOS as $slug => $def) {
            $segmentos[$slug] = $this->segmento($slug, $def, $puntos, $seco, $io);
        }

        // Los pools: todos los segmentos y componentes que usa el servicio. La pareja es la PK, así
        // que repetir no duplica — y va en su pasada, aunque el servicio ya existiera.
        if (!$seco) {
            foreach ($segmentos as $segmento) {
                $servicio->addSegmento($segmento);
            }
            $servicio->addComponente($bus);
            foreach ($entradas as $entrada) {
                $servicio->addComponente($entrada);
            }
            $this->em->flush();
        }

        // ── Plantillas ───────────────────────────────────────────────────────────────
        $io->section('Plantillas');
        foreach (self::PLANTILLAS as $def) {
            if ($this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => $def['slug']]) !== null) {
                $io->text(sprintf('  ya existe · %s', $def['nombre']));
                continue;
            }

            $io->text(sprintf('  %s · %s — %s a %s, %d segmentos', $seco ? 'crearía' : 'creada ', $def['nombre'],
                self::HORA, self::HORA_FIN, \count($def['segmentos'])));
            if ($seco) {
                continue;
            }

            $plantilla = (new TravelItinerario())
                ->setServicio($servicio)
                ->setSlug($def['slug'])
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setDuracionDias(1);
            $this->em->persist($plantilla);

            foreach ($def['segmentos'] as $pos => $slug) {
                $this->em->persist((new TravelItinerarioSegmentoRel())
                    ->setItinerario($plantilla)->setSegmento($segmentos[$slug])
                    ->setDia(1)->setOrden($pos + 1));
            }

            $ancla = $segmentos[$def['segmentos'][0]];

            // El bus en el ancla: la relación GLOBAL sin hora —la hora es de cada plantilla— y la de
            // la plantilla con el horario de toda la excursión promovido.
            $global = $this->em->getRepository(TravelSegmentoComponente::class)->findOneBy([
                'segmento' => $ancla, 'componente' => $bus, 'itinerarioContexto' => null, 'dia' => null,
            ]);
            if ($global === null) {
                $this->em->persist((new TravelSegmentoComponente())
                    ->setSegmento($ancla)->setComponente($bus)
                    ->setTarifaPredeterminada($tarifaBus)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setOrden(1));
            }
            $this->em->persist((new TravelSegmentoComponente())
                ->setSegmento($ancla)->setComponente($bus)
                ->setTarifaPredeterminada($tarifaBus)
                ->setItinerarioContexto($plantilla)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setDia(1)->setOrden(1)
                ->setHora(new \DateTimeImmutable(self::HORA))
                ->setHoraFin(new \DateTimeImmutable(self::HORA_FIN))
                ->setHoraServicioCompleto(true));

            // Las entradas, «No incluye», atadas a esta plantilla: sin hora ni tarifa por defecto.
            foreach (self::ENTRADAS as $nombre => $slug) {
                $this->em->persist((new TravelSegmentoComponente())
                    ->setSegmento($segmentos[$slug])->setComponente($entradas[$nombre])
                    ->setItinerarioContexto($plantilla)
                    ->setModo(ComponenteModoEnum::NO_INCLUIDO)
                    ->setOrden(1));
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        // ── Ítems del bus, en su pasada ──────────────────────────────────────────────
        $io->section('Qué incluye el bus');
        foreach (self::ITEMS as $orden => $nombre) {
            $termino = $this->em->getRepository(TravelItemDiccionario::class)->findOneBy(['nombreInterno' => $nombre])
                ?? throw new \RuntimeException('Falta el término «' . $nombre . '»');
            if ($this->em->getRepository(TravelComponenteItem::class)->findOneBy(['componente' => $bus, 'diccionario' => $termino]) !== null) {
                continue;
            }
            $io->text(sprintf('  %s · %s', $seco ? 'añadiría' : 'añadido ', $nombre));
            if (!$seco) {
                $this->em->persist((new TravelComponenteItem())
                    ->setComponente($bus)->setDiccionario($termino)
                    ->setModo(ItemModoEnum::INCLUIDO)->setOrden($orden + 1));
            }
        }

        // ── Traslados hotel ↔ terminal de Mer ────────────────────────────────────────
        $io->section('Traslados al terminal');
        foreach (self::TRASLADOS as $def) {
            if ($this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']]) !== null) {
                $io->text(sprintf('  ya existe · %s', $def['slug']));
                continue;
            }
            $contenedor = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => $def['servicio']])
                ?? throw new \RuntimeException('Falta el servicio ' . $def['servicio']);
            $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $def['componente']])
                ?? throw new \RuntimeException('Falta el componente ' . $def['componente']);

            $segmento = $this->segmento($def['slug'], $def, $puntos, $seco, $io, $def['servicio'] . ', ' . $def['hora']);
            if ($seco) {
                continue;
            }
            $contenedor->addSegmento($segmento);
            // Global, como los demás traslados a un terminal: sin plantilla ni día.
            $this->em->persist((new TravelSegmentoComponente())
                ->setSegmento($segmento)->setComponente($componente)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setOrden(1)
                ->setHora(new \DateTimeImmutable($def['hora'])));
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }

    /**
     * El segmento por slug, creándolo si no existe.
     *
     * @param array{nombre: string, titulo: string, contenido: string, inicio: PuntoModoEnum, fin: PuntoModoEnum,
     *              inicioPunto?: string, finPunto?: string} $def
     * @param array<string, TravelPunto> $puntos
     */
    private function segmento(string $slug, array $def, array $puntos, bool $seco, SymfonyStyle $io, string $extra = ''): TravelSegmento
    {
        $existente = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]);
        if ($existente instanceof TravelSegmento) {
            $io->text(sprintf('  ya existe · %s', $slug));

            return $existente;
        }

        $io->text(sprintf('  %s · %-28s %s%s', $seco ? 'crearía' : 'creado ', $slug, $def['nombre'], $extra !== '' ? ' (' . $extra . ')' : ''));
        $segmento = (new TravelSegmento())
            ->setSlug($slug)
            ->setNombreInterno($def['nombre'])
            ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
            ->setContenido([['language' => 'es', 'content' => $def['contenido']]])
            ->setInicioModo($def['inicio'])
            ->setFinModo($def['fin']);
        if (isset($def['inicioPunto'])) {
            $segmento->setInicioPunto($puntos[$def['inicioPunto']]);
        }
        if (isset($def['finPunto'])) {
            $segmento->setFinPunto($puntos[$def['finPunto']]);
        }
        if (!$seco) {
            $this->em->persist($segmento);
        }

        return $segmento;
    }

    private function lugar(string $nombre): TravelLugar
    {
        return $this->em->getRepository(TravelLugar::class)->findOneBy(['nombre' => $nombre])
            ?? throw new \RuntimeException(sprintf('No existe el lugar «%s».', $nombre));
    }

    private function moneda(string $codigo): MaestroMoneda
    {
        return $this->em->find(MaestroMoneda::class, $codigo)
            ?? throw new \RuntimeException('No existe la moneda ' . $codigo);
    }
}
