<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionServicio;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Arma el repertorio de actividades dentro de un resort all-inclusive.
 *
 * ── El reparto: el segmento es genérico, la tarifa es la que sabe de qué resort ──
 *
 * Es la arquitectura de `docs/Cotizaciones.md` §6.t llevada al catálogo. Un desayuno buffet
 * se cuenta igual en Punta Cana que en Cancún, así que el SEGMENTO y el COMPONENTE se
 * escriben una sola vez y no llevan el resort en el nombre ni en el slug. Lo que cambia de
 * un resort a otro es la TARIFA: ella apunta al prestador y a su servicio, y de ese servicio
 * salen las fotos que verá el cliente.
 *
 *   segmento genérico ──► componente genérico ──► tarifa POR RESORT ──► servicio del
 *   «Piscina y playa»     «Piscina y playa»       Occidental: 0 USD     prestador (fotos)
 *
 * Añadir el resort número dos NO crea ningún segmento: crea su organización, sus servicios
 * con sus fotos, y una tarifa más colgando de los mismos doce componentes.
 *
 * ── Por qué siete servicios de prestador y no doce ──────────────────────────
 *
 * Varias tarifas pueden compartir el mismo servicio del prestador, y conviene que lo hagan:
 * el lobby es el mismo en el check-in de la mañana, el de la tarde y los dos check-out. Con
 * un servicio por actividad habría que subir las mismas fotos del lobby cuatro veces, y esas
 * cuatro copias se separan con el tiempo.
 *
 * Además la guía las enseñaría una sola vez de todos modos: `galeriaPorBloque` deduplica por
 * foto, así que los duplicados serían trabajo manual invisible. Si más adelante un desayuno
 * merece foto propia, se crea su servicio y se repunta esa tarifa: es barato.
 *
 * ── Por qué por comando y no por migración ──────────────────────────────────
 *
 * `titulo` y `contenido` llevan `#[AutoTranslate]`, que se engancha a `prePersist`. Un
 * `INSERT` en SQL se lo salta y dejaría las fichas sólo en español. Ver CLAUDE.md.
 *
 * Idempotente por clave natural (slug, nombre interno, nombre comercial): repetirlo no
 * duplica nada ni pisa lo que ya exista.
 */
#[AsCommand(
    name: 'app:travel:crear-actividades-resort',
    description: 'Crea el servicio «Actividades en resort» con sus segmentos, componentes y tarifas a 0.',
)]
final class CrearActividadesResortCommand extends Command
{
    private const SERVICIO_CODIGO = 'ACT_RESORT';
    private const SERVICIO_NOMBRE = 'Actividades en resort';
    private const ORG_NOMBRE = 'Occidental Caribe - Punta Cana';
    private const MONEDA = 'USD';

    /**
     * Los servicios del prestador: donde se cuelgan las fotos.
     *
     * @var array<string, string>
     */
    private const SERVICIOS_PRESTADOR = [
        'lobby'      => 'Lobby y recepción',
        'buffet'     => 'Restaurante buffet',
        'tematicos'  => 'Restaurantes temáticos',
        'piscinas'   => 'Piscinas y playa',
        'deportes'   => 'Actividades y deportes',
        'shows'      => 'Espectáculos nocturnos',
        'resort'     => 'El resort',
    ];

    /**
     * El repertorio. `prestador` es la clave del servicio de arriba del que saldrán las fotos.
     *
     * @var list<array{slug: string, nombre: string, titulo: string, contenido: string,
     *                 tipo: ComponenteTipoEnum, prestador: string, hora: string|null}>
     */
    private const SEGMENTOS = [
        [
            'slug' => 'ACT-RESORT-CHECKIN_AM',
            'nombre' => 'Check-in en el resort (mañana)',
            'titulo' => 'Llegada y check-in',
            'contenido' => 'Llegada al resort y registro en recepción. Si la habitación todavía '
                . 'no está lista, el equipaje queda en custodia y desde ese momento ya se puede '
                . 'usar el resort: piscinas, playa y restaurantes.',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'prestador' => 'lobby',
            'hora' => '10:00',
        ],
        [
            'slug' => 'ACT-RESORT-CHECKIN_PM',
            'nombre' => 'Check-in en el resort (tarde)',
            'titulo' => 'Llegada y check-in',
            'contenido' => 'Llegada al resort por la tarde y registro en recepción, con la '
                . 'habitación ya disponible. Queda tiempo para instalarse antes de la cena.',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'prestador' => 'lobby',
            'hora' => '15:00',
        ],
        [
            'slug' => 'ACT-RESORT-CHECKOUT_AM',
            'nombre' => 'Check-out del resort (mañana)',
            'titulo' => 'Check-out',
            'contenido' => 'Desayuno y salida del resort por la mañana. El equipaje puede quedar '
                . 'en custodia en recepción hasta la hora del traslado.',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'prestador' => 'lobby',
            'hora' => '08:00',
        ],
        [
            'slug' => 'ACT-RESORT-CHECKOUT_PM',
            'nombre' => 'Check-out del resort (tarde)',
            'titulo' => 'Check-out',
            'contenido' => 'Salida del resort por la tarde. Hasta la hora del traslado se puede '
                . 'seguir usando las instalaciones; el equipaje queda en custodia en recepción.',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'prestador' => 'lobby',
            'hora' => '15:00',
        ],
        [
            'slug' => 'DES-RESORT-BUFFET',
            'nombre' => 'Desayuno buffet en resort',
            'titulo' => 'Desayuno buffet',
            'contenido' => 'Desayuno buffet en el restaurante principal del resort, con estaciones '
                . 'de cocina caliente, fruta fresca, panadería y bebidas.',
            'tipo' => ComponenteTipoEnum::ALIMENTACION_HORARIO_VAR,
            'prestador' => 'buffet',
            'hora' => '07:00',
        ],
        [
            'slug' => 'ALM-RESORT-BUFFET',
            'nombre' => 'Almuerzo buffet en resort',
            'titulo' => 'Almuerzo buffet',
            'contenido' => 'Almuerzo buffet en el resort, con platos internacionales y de cocina '
                . 'local. Según el hotel, también hay servicio junto a la piscina o en la playa.',
            'tipo' => ComponenteTipoEnum::ALIMENTACION_HORARIO_VAR,
            'prestador' => 'buffet',
            'hora' => '12:30',
        ],
        [
            'slug' => 'CEN-RESORT-BUFFET',
            'nombre' => 'Cena buffet en resort',
            'titulo' => 'Cena buffet',
            'contenido' => 'Cena buffet en el restaurante principal del resort, con estaciones de '
                . 'cocina en vivo y una selección de postres.',
            'tipo' => ComponenteTipoEnum::ALIMENTACION_HORARIO_VAR,
            'prestador' => 'buffet',
            'hora' => '19:00',
        ],
        [
            'slug' => 'CEN-RESORT-TEMATICO',
            'nombre' => 'Cena en restaurante temático de resort',
            'titulo' => 'Cena en restaurante temático',
            'contenido' => 'Cena a la carta en uno de los restaurantes temáticos del resort. '
                . 'Requieren reserva previa y la disponibilidad se gestiona en recepción al llegar.',
            'tipo' => ComponenteTipoEnum::ALIMENTACION_HORARIO_VAR,
            'prestador' => 'tematicos',
            'hora' => '19:30',
        ],
        [
            'slug' => 'ACT-RESORT-PISCINA_PLAYA',
            'nombre' => 'Piscina y playa en resort',
            'titulo' => 'Piscina y playa',
            'contenido' => 'Tiempo libre en las piscinas y en la playa del resort, con hamacas y '
                . 'servicio de bar incluido.',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'prestador' => 'piscinas',
            'hora' => '10:00',
        ],
        [
            'slug' => 'ACT-RESORT-RECREATIVAS',
            'nombre' => 'Actividades recreativas de resort',
            'titulo' => 'Actividades recreativas',
            'contenido' => 'Programa de actividades del resort: deportes acuáticos sin motor, '
                . 'clases y juegos dirigidos por el equipo de animación. El programa del día se '
                . 'publica cada mañana en el hotel.',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'prestador' => 'deportes',
            'hora' => '16:00',
        ],
        [
            'slug' => 'ACT-RESORT-SHOW',
            'nombre' => 'Espectáculo nocturno de resort',
            'titulo' => 'Espectáculo nocturno',
            'contenido' => 'Espectáculo nocturno en el teatro del resort, con música en vivo y '
                . 'shows temáticos. La programación varía cada noche.',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'prestador' => 'shows',
            'hora' => '21:30',
        ],
        [
            'slug' => 'ACT-RESORT-DIA_LIBRE',
            'nombre' => 'Día libre en resort (resumen)',
            'titulo' => 'Día libre en el resort',
            'contenido' => 'Día libre para disfrutar del resort en régimen todo incluido: '
                . 'piscinas, playa, restaurantes, actividades del equipo de animación y '
                . 'espectáculo nocturno.',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'prestador' => 'resort',
            'hora' => null,
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

        $io->title(sprintf('Actividades en resort — %s', self::ORG_NOMBRE));

        // 1. La organización. `visibleParaCliente` es lo que siembra `prestadorVisible` en la
        //    cotización, y sin él el normalizer público NO inyecta las fotos: ver §6.t.
        $org = $this->em->getRepository(TravelOrganizacion::class)
            ->findOneBy(['nombreComercial' => self::ORG_NOMBRE]);

        if ($org === null) {
            $io->section('Organización');
            $io->text(sprintf('  %s · %s (visible para el cliente)', $simula ? 'crearía' : 'creada ', self::ORG_NOMBRE));

            if (!$simula) {
                $org = (new TravelOrganizacion())
                    ->setNombreComercial(self::ORG_NOMBRE)
                    ->setTitulo([['language' => 'es', 'content' => 'Occidental Caribe']])
                    ->setVisibleParaCliente(true);
                $this->em->persist($org);
                $this->em->flush();
            }
        } else {
            $io->text(sprintf('Organización · ya existe%s', $org->isVisibleParaCliente() ? '' : ' ⚠ NO es visible para el cliente'));
        }

        // 2. Los servicios del prestador: los buzones donde se cuelgan las fotos.
        $io->section('Servicios del prestador (aquí van las fotos)');
        $servicios = [];

        foreach (self::SERVICIOS_PRESTADOR as $clave => $nombre) {
            $existente = $org === null ? null : $this->em->getRepository(TravelOrganizacionServicio::class)
                ->findOneBy(['organizacion' => $org, 'nombre' => $nombre]);

            if ($existente !== null) {
                $servicios[$clave] = $existente;
                $io->text(sprintf('  ya existe · %s', $nombre));
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $nombre));

            if (!$simula && $org !== null) {
                $servicio = (new TravelOrganizacionServicio())
                    ->setOrganizacion($org)
                    ->setNombre($nombre)
                    ->setTitulo([['language' => 'es', 'content' => $nombre]]);
                $this->em->persist($servicio);
                $servicios[$clave] = $servicio;
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        // 3. El servicio contenedor. Sin itinerario: se arma a medida desde los pools.
        $servicioTravel = $this->em->getRepository(TravelServicio::class)
            ->findOneBy(['codigo' => self::SERVICIO_CODIGO]);

        if ($servicioTravel === null) {
            $io->section('Servicio');
            $io->text(sprintf('  %s · %s (%s)', $simula ? 'crearía' : 'creado ', self::SERVICIO_NOMBRE, self::SERVICIO_CODIGO));

            if (!$simula) {
                $servicioTravel = (new TravelServicio())
                    ->setCodigo(self::SERVICIO_CODIGO)
                    ->setNombreInterno(self::SERVICIO_NOMBRE)
                    ->setTitulo([['language' => 'es', 'content' => self::SERVICIO_NOMBRE]]);
                $this->em->persist($servicioTravel);
                $this->em->flush();
            }
        } else {
            $io->text(sprintf('Servicio · ya existe (%s)', self::SERVICIO_CODIGO));
        }

        // 4. Segmento + componente + tarifa, y los tres al pool.
        $io->section('Segmentos, componentes y tarifas');
        $creados = 0;

        foreach (self::SEGMENTOS as $def) {
            $segRepo = $this->em->getRepository(TravelSegmento::class);

            if ($segRepo->findOneBy(['slug' => $def['slug']]) !== null) {
                $io->text(sprintf('  ya existe · %s', $def['slug']));
                continue;
            }

            ++$creados;
            $io->text(sprintf(
                '  %s · %-26s %-38s → %s',
                $simula ? 'crearía' : 'creado ',
                $def['slug'],
                $def['nombre'],
                self::SERVICIOS_PRESTADOR[$def['prestador']],
            ));

            if ($simula) {
                continue;
            }

            $segmento = (new TravelSegmento())
                ->setSlug($def['slug'])
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setContenido([['language' => 'es', 'content' => $def['contenido']]]);
            $this->em->persist($segmento);

            $componente = (new TravelComponente())
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setTipo($def['tipo']);
            $this->em->persist($componente);

            // La tarifa lleva el resort en el nombre porque es la pieza que cambia por resort.
            // Sin filtros: ni procedencia, ni edades, ni capacidad — se aplica a todo el mundo.
            // Ojo: los setters de TravelTarifa devuelven void, así que aquí no se encadena.
            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            // ⚠️ Desde el nombre INTERNO, no desde el título: el título público de los dos
            // check-in es el mismo («Llegada y check-in»), así que nombrar por ahí dejaba dos
            // tarifas distintas con idéntico nombre y sin forma de distinguirlas en el panel.
            $tarifa->setNombreInterno(sprintf('%s · %s', self::ORG_NOMBRE, $def['nombre']));
            $tarifa->setTitulo([['language' => 'es', 'content' => $def['titulo']]]);
            $tarifa->setMoneda($moneda);
            $tarifa->setMonto('0.00');
            $tarifa->setPrestador($org);
            $tarifa->setPrestadorServicio($servicios[$def['prestador']] ?? null);
            $this->em->persist($tarifa);

            $rel = (new TravelSegmentoComponente())
                ->setSegmento($segmento)
                ->setComponente($componente)
                ->setTarifaPredeterminada($tarifa)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setDia(1)
                ->setOrden(1);

            if ($def['hora'] !== null) {
                $rel->setHora(new \DateTimeImmutable($def['hora']));
            }
            $this->em->persist($rel);

            if ($servicioTravel !== null) {
                $servicioTravel->addSegmento($segmento);
                $servicioTravel->addComponente($componente);
            }
        }

        $io->section('Nombres de tarifa');
        $renombradas = 0;

        foreach (self::SEGMENTOS as $def) {
            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']]);
            if ($segmento === null) {
                continue;
            }

            $esperado = sprintf('%s · %s', self::ORG_NOMBRE, $def['nombre']);

            foreach ($segmento->getSegmentoComponentes() as $rel) {
                $tarifa = $rel->getTarifaPredeterminada();
                if ($tarifa === null || $tarifa->getNombreInterno() === $esperado) {
                    continue;
                }

                ++$renombradas;
                $io->text(sprintf('  %s · %s', $simula ? 'renombraría' : 'renombrada  ', $esperado));

                if (!$simula) {
                    $tarifa->setNombreInterno($esperado);
                }
            }
        }

        if ($renombradas === 0) {
            $io->text('  todas bien nombradas.');
        }

        // Las horas son lo que coloca cada bloque en la cronología del día: sin ellas la guía
        // cae al desempate por `orden`, que es frágil cuando un mismo servicio aparece dos veces
        // en un día —justo lo que pasa al partir el día para intercalar una excursión—.
        // Se sincronizan también en los que ya existían, pero SIN pisar una hora ya puesta.
        $io->section('Horas');
        $puestas = 0;

        foreach (self::SEGMENTOS as $def) {
            if ($def['hora'] === null) {
                continue;
            }

            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']]);
            if ($segmento === null) {
                continue;
            }

            foreach ($segmento->getSegmentoComponentes() as $rel) {
                if ($rel->getHora() !== null) {
                    continue;
                }

                ++$puestas;
                $io->text(sprintf('  %s · %-26s %s', $simula ? 'pondría' : 'puesta ', $def['slug'], $def['hora']));

                if (!$simula) {
                    $rel->setHora(new \DateTimeImmutable($def['hora']));
                }
            }
        }

        if ($puestas === 0) {
            $io->text('  todas tenían hora ya.');
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%s %d segmento(s) con su componente y su tarifa a 0 %s.',
            $simula ? 'Se crearían' : 'Creados',
            $creados,
            self::MONEDA,
        ));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
