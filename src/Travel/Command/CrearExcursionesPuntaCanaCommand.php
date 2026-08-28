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
 * Las dos excursiones que se intercalan en una estadía de Punta Cana.
 *
 * ── Por qué NO van en «Actividades en resort» ───────────────────────────────
 *
 * El pool de segmentos de un cotservicio es el de su servicio maestro, y en la guía los
 * bloques de un cotservicio se pintan SEGUIDOS (se agrupan por `servicio.id`). Meterlas en
 * `ACT_RESORT` pondría «Actividades en resort» de encabezado sobre una excursión a Isla Saona,
 * y ataría a Punta Cana un repertorio que sirve para cualquier resort.
 *
 * La forma de intercalarlas es partir el día del resort en dos cotservicios y colar la
 * excursión en medio; el orden lo da la hora. Ver `docs/Cotizaciones.md` §6.t.
 *
 * ── De dónde salen las fotos de cada una, y por qué NO es lo mismo ──────────
 *
 * **Isla Saona es un LUGAR**: la playa es la misma la opere quien la opere, así que sus fotos
 * van en el propio segmento — la regla 1 de la galería, el caso de Paracas. Por eso sus
 * tarifas nacen sin prestador: falta decidir el operador, y para las fotos no hace falta.
 *
 * **Coco Bongo es una MARCA con locales en varias ciudades**, así que sigue el patrón del
 * resort: el segmento es genérico y el local concreto entra por el servicio del prestador.
 *
 * ⚠️ Y aquí las dos modalidades NO comparten servicio de prestador, al revés que en el resort:
 * son alternativas —el pasajero contrata una o la otra, nunca las dos—, así que la
 * deduplicación de la galería no las taparía nunca y separarlas sí gana fotos distintas.
 *
 * ⚠️ Las tarifas nacen a 0: son el vehículo para colgar prestador y servicio, no un precio.
 * Hay que ponerles el suyo antes de cotizarlas.
 *
 * Idempotente por clave natural. Va por comando y no por migración porque `titulo` y
 * `contenido` llevan `#[AutoTranslate]`.
 */
#[AsCommand(
    name: 'app:travel:crear-excursiones-punta-cana',
    description: 'Crea Isla Saona y Coco Bongo con sus modalidades, componentes y tarifas.',
)]
final class CrearExcursionesPuntaCanaCommand extends Command
{
    private const ORG_COCO = 'Grupo Coco Bongo';
    private const MONEDA = 'USD';

    /**
     * Servicios contenedores. Uno por excursión: en la guía cada cotservicio se pinta como un
     * bloque propio, y así Saona por la mañana y Coco Bongo por la noche no se pegan.
     *
     * @var array<string, string>
     */
    private const SERVICIOS = [
        'SAONA' => 'Isla Saona',
        'COCO_BONGO' => 'Coco Bongo',
    ];

    /**
     * Los servicios del prestador de Coco Bongo: un buzón de fotos por modalidad.
     *
     * @var array<string, string>
     */
    private const SERVICIOS_COCO = [
        'general' => 'Coco Bongo Punta Cana',
        'blanca' => 'Coco Bongo Punta Cana · Fiesta Blanca',
    ];

    /**
     * @var list<array{slug: string, servicio: string, nombre: string, titulo: string,
     *                 contenido: string, tipo: ComponenteTipoEnum, hora: string,
     *                 prestadorCoco: string|null}>
     */
    private const SEGMENTOS = [
        [
            'slug' => 'VIS-SAONA-CLASICO',
            'servicio' => 'SAONA',
            'nombre' => 'Isla Saona (clásico)',
            'titulo' => 'Día completo en Isla Saona',
            'contenido' => 'Navegación hasta Isla Saona con parada en la piscina natural, un banco '
                . 'de arena en mitad del mar donde el agua no cubre. En la isla queda tiempo libre '
                . 'en la playa y se almuerza junto al agua antes del regreso.',
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'hora' => '08:00',
            'prestadorCoco' => null,
        ],
        [
            'slug' => 'VIS-SAONA-VIP',
            'servicio' => 'SAONA',
            'nombre' => 'Isla Saona (VIP)',
            'titulo' => 'Día completo en Isla Saona · VIP',
            'contenido' => 'Versión VIP del día en Isla Saona: grupo reducido, zona reservada en la '
                . 'playa y servicio a bordo durante la navegación. Mantiene la parada en la piscina '
                . 'natural y el almuerzo junto al agua.',
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'hora' => '08:00',
            'prestadorCoco' => null,
        ],
        [
            'slug' => 'VIS-COCO_BONGO-GENERAL',
            'servicio' => 'COCO_BONGO',
            'nombre' => 'Coco Bongo (entrada general)',
            'titulo' => 'Noche en Coco Bongo',
            'contenido' => 'Noche en Coco Bongo: un espectáculo continuo de tributos musicales, '
                . 'acróbatas colgados del techo y pantallas gigantes, con pista de baile entre un '
                . 'número y el siguiente.',
            'tipo' => ComponenteTipoEnum::TICKET_HORARIO_VAR,
            'hora' => '22:00',
            'prestadorCoco' => 'general',
        ],
        [
            'slug' => 'VIS-COCO_BONGO-FIESTA_BLANCA',
            'servicio' => 'COCO_BONGO',
            'nombre' => 'Coco Bongo (fiesta blanca)',
            'titulo' => 'Noche en Coco Bongo · Fiesta Blanca',
            'contenido' => 'La Fiesta Blanca de Coco Bongo, con código de vestimenta en blanco y '
                . 'una producción propia para esa noche. El resto mantiene el formato de la casa: '
                . 'espectáculo continuo, acróbatas y pista de baile.',
            'tipo' => ComponenteTipoEnum::TICKET_HORARIO_VAR,
            'hora' => '22:00',
            'prestadorCoco' => 'blanca',
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

        $io->title('Excursiones de Punta Cana');

        // 1. Grupo Coco Bongo, visible: sin ese flag el normalizer no inyecta ni una imagen.
        $orgCoco = $this->em->getRepository(TravelOrganizacion::class)
            ->findOneBy(['nombreComercial' => self::ORG_COCO]);

        if ($orgCoco === null) {
            $io->section('Organización');
            $io->text(sprintf('  %s · %s (visible para el cliente)', $simula ? 'crearía' : 'creada ', self::ORG_COCO));

            if (!$simula) {
                $orgCoco = (new TravelOrganizacion())
                    ->setNombreComercial(self::ORG_COCO)
                    ->setTitulo([['language' => 'es', 'content' => 'Coco Bongo']])
                    ->setVisibleParaCliente(true);
                $this->em->persist($orgCoco);
                $this->em->flush();
            }
        } else {
            $io->text(sprintf('Organización · ya existe%s', $orgCoco->isVisibleParaCliente() ? '' : ' ⚠ NO es visible para el cliente'));
        }

        // 2. Un buzón de fotos por modalidad: son alternativas, nunca coinciden en un viaje.
        $io->section('Servicios del prestador (aquí van las fotos de Coco Bongo)');
        $serviciosCoco = [];

        foreach (self::SERVICIOS_COCO as $clave => $nombre) {
            $existente = $orgCoco === null ? null : $this->em->getRepository(TravelOrganizacionServicio::class)
                ->findOneBy(['organizacion' => $orgCoco, 'nombre' => $nombre]);

            if ($existente !== null) {
                $serviciosCoco[$clave] = $existente;
                $io->text(sprintf('  ya existe · %s', $nombre));
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $nombre));

            if (!$simula && $orgCoco !== null) {
                $servicio = (new TravelOrganizacionServicio())
                    ->setOrganizacion($orgCoco)
                    ->setNombre($nombre)
                    ->setTitulo([['language' => 'es', 'content' => $nombre]]);
                $this->em->persist($servicio);
                $serviciosCoco[$clave] = $servicio;
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        // 3. Los dos servicios contenedores, sin itinerario: se arman desde los pools.
        $io->section('Servicios');
        $servicios = [];

        foreach (self::SERVICIOS as $codigo => $nombre) {
            $existente = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => $codigo]);

            if ($existente !== null) {
                $servicios[$codigo] = $existente;
                $io->text(sprintf('  ya existe · %s (%s)', $nombre, $codigo));
                continue;
            }

            $io->text(sprintf('  %s · %s (%s)', $simula ? 'crearía' : 'creado ', $nombre, $codigo));

            if (!$simula) {
                $servicio = (new TravelServicio())
                    ->setCodigo($codigo)
                    ->setNombreInterno($nombre)
                    ->setTitulo([['language' => 'es', 'content' => $nombre]]);
                $this->em->persist($servicio);
                $servicios[$codigo] = $servicio;
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        // 4. Segmento + componente + tarifa a 0, con hora para que la guía los coloque.
        $io->section('Segmentos, componentes y tarifas');
        $creados = 0;

        foreach (self::SEGMENTOS as $def) {
            if ($this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']]) !== null) {
                $io->text(sprintf('  ya existe · %s', $def['slug']));
                continue;
            }

            ++$creados;
            $origen = $def['prestadorCoco'] === null
                ? 'fotos en el propio segmento'
                : self::SERVICIOS_COCO[$def['prestadorCoco']];

            $io->text(sprintf(
                '  %s · %-30s %-30s %s → %s',
                $simula ? 'crearía' : 'creado ',
                $def['slug'],
                $def['nombre'],
                $def['hora'],
                $origen,
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

            // Los setters de TravelTarifa devuelven void: aquí no se encadena.
            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            $tarifa->setNombreInterno($def['nombre']);
            $tarifa->setTitulo([['language' => 'es', 'content' => $def['titulo']]]);
            $tarifa->setMoneda($moneda);
            $tarifa->setMonto('0.00');

            if ($def['prestadorCoco'] !== null) {
                $tarifa->setPrestador($orgCoco);
                $tarifa->setPrestadorServicio($serviciosCoco[$def['prestadorCoco']] ?? null);
            }

            $this->em->persist($tarifa);

            $rel = (new TravelSegmentoComponente())
                ->setSegmento($segmento)
                ->setComponente($componente)
                ->setTarifaPredeterminada($tarifa)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setHora(new \DateTimeImmutable($def['hora']))
                ->setDia(1)
                ->setOrden(1);
            $this->em->persist($rel);

            $servicio = $servicios[$def['servicio']] ?? null;
            if ($servicio !== null) {
                $servicio->addSegmento($segmento);
                $servicio->addComponente($componente);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf('%s %d segmento(s).', $simula ? 'Se crearían' : 'Creados', $creados));
        $io->warning('Las tarifas nacen a 0: son el vehículo del prestador, no un precio. Ponles el suyo antes de cotizar.');
        $io->note('Isla Saona nace sin prestador a propósito: es un lugar, y sus fotos van en el propio segmento.');

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
