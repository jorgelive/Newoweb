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
 * Dos cosas que van juntas porque una saca piezas de la otra.
 *
 * ## 1 · «Varios en el aeropuerto de Lima»
 *
 * Comer en el aeropuerto no es parte de una caminata por Miraflores: pasa en una escala, en una
 * espera de conexión o en una salida de madrugada. Estaba metido dentro de `WALK_MIR` porque allí
 * nació, y así sólo se podía usar arrastrando la escala entera.
 *
 * El desayuno **se muda** —no se duplica— y estrena slug: `DES-WALK_MIR-AEROPUERTO` decía que
 * pertenecía a la caminata, y ya no.
 *
 * ## 2 · El traslado del walking, con transporte de verdad
 *
 * ⚠️ Los dos traslados colgaban de un solo componente `pool` llamado «Escala en Lima · traslados
 * y caminata», que mezclaba el vehículo con el paseo. Con eso **no se puede elegir movilidad**:
 * un grupo de 40 y una pareja contratan lo mismo.
 *
 * Ahora cada sentido tiene su componente de tipo `transporte` con su flota —Auto, Van, Minibús y
 * Bus, privados y por grupo—, igual que los traslados de Lima y Punta Cana.
 *
 * ⚠️ **La hora promovida se traspasa.** El ancla llevaba `horaServicioCompleto` en la plantilla, que
 * es lo que pone el horario de toda la excursión en la guía. Al retirarla hay que dársela al
 * transporte de ida, o el día se queda sin hora de cabecera y cae al desempate por `orden`.
 */
#[AsCommand(
    name: 'app:travel:crear-varios-aeropuerto-lima',
    description: 'Crea «Varios en el aeropuerto de Lima» y da transporte propio a los traslados del walking.',
)]
final class CrearVariosAeropuertoLimaCommand extends Command
{
    private const SERVICIO = 'VAR_APT_LIM';
    private const SERVICIO_NOMBRE = 'Varios en el aeropuerto de Lima';
    private const AEROPUERTO = 'Aeropuerto de Lima';
    private const MONEDA = 'PEN';

    private const ANCLA_VIEJA = 'Escala en Lima · traslados y caminata por Miraflores';

    /** @var array<string, int> */
    private const FLOTA = ['Auto' => 4, 'Van' => 8, 'Minibús' => 25, 'Bus' => 45];

    /**
     * Las comidas del aeropuerto. El desayuno se muda desde `WALK_MIR`.
     *
     * @var list<array{slug: string, viejo: string|null, nombre: string, titulo: string, contenido: string, hora: string}>
     */
    private const COMIDAS = [
        [
            'slug' => 'DES-APT_LIM',
            'viejo' => 'DES-WALK_MIR-AEROPUERTO',
            'nombre' => 'Desayuno en el aeropuerto de Lima',
            'titulo' => 'Desayuno en el aeropuerto',
            'contenido' => '<p>🍽️ <b>Desayuno en el aeropuerto:</b> Antes de continuar, desayuno en el '
                . 'aeropuerto Jorge Chávez, con tiempo para acomodar el equipaje de mano y estirar las piernas.</p>',
            'hora' => '07:00',
        ],
        [
            'slug' => 'ALM-APT_LIM',
            'viejo' => null,
            'nombre' => 'Almuerzo en el aeropuerto de Lima',
            'titulo' => 'Almuerzo en el aeropuerto',
            'contenido' => '<p>🍽️ <b>Almuerzo en el aeropuerto:</b> Almuerzo en el aeropuerto Jorge '
                . 'Chávez durante la espera, sin salir de la terminal.</p>',
            'hora' => '12:30',
        ],
        [
            'slug' => 'CEN-APT_LIM',
            'viejo' => null,
            'nombre' => 'Cena en el aeropuerto de Lima',
            'titulo' => 'Cena en el aeropuerto',
            'contenido' => '<p>🍽️ <b>Cena en el aeropuerto:</b> Cena en el aeropuerto Jorge Chávez '
                . 'antes del vuelo, habitual en las salidas de madrugada.</p>',
            'hora' => '19:00',
        ],
    ];

    /**
     * Los dos traslados de la escala, cada uno con su componente de transporte.
     *
     * @var list<array{slug: string, componente: string, titulo: string, desdeAeropuerto: bool, promueve: bool}>
     */
    private const TRASLADOS = [
        [
            'slug' => 'SAL_EXC-WALK_MIR',
            'componente' => 'Transporte Aeropuerto Lima - Miraflores',
            'titulo' => 'Transporte del aeropuerto de Lima a Miraflores',
            'desdeAeropuerto' => true,
            // Hereda la hora promovida del ancla: es lo que encabeza el día en la guía.
            'promueve' => true,
        ],
        [
            'slug' => 'RET_EXC-WALK_MIR-AEROPUERTO',
            'componente' => 'Transporte Miraflores - Aeropuerto Lima',
            'titulo' => 'Transporte de Miraflores al aeropuerto de Lima',
            'desdeAeropuerto' => false,
            'promueve' => false,
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
        $walking = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => 'WALK_MIR']);

        if ($moneda === null || $aeropuerto === null || $walking === null) {
            $io->error('Falta la moneda, el punto del aeropuerto o el servicio WALK_MIR.');

            return Command::FAILURE;
        }

        $io->title(self::SERVICIO_NOMBRE);

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
        }

        $io->section('Comidas en el aeropuerto');
        $this->comidas($servicio, $walking, $aeropuerto, $moneda, $io, $simula);

        $io->section('Traslados de la escala, con su flota');
        $this->traslados($walking, $aeropuerto, $moneda, $io, $simula);

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->warning('Todas las tarifas nacen a 0: el precio lo pone quien compra.');

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    private function comidas(
        ?TravelServicio $servicio,
        TravelServicio $walking,
        TravelPunto $aeropuerto,
        MaestroMoneda $moneda,
        SymfonyStyle $io,
        bool $simula,
    ): void {
        $repo = $this->em->getRepository(TravelSegmento::class);

        foreach (self::COMIDAS as $def) {
            $segmento = $repo->findOneBy(['slug' => $def['slug']]);

            // La mudanza: el mismo segmento cambia de slug y de servicio, no se duplica. Duplicarlo
            // dejaría dos desayunos con el mismo texto y nadie sabría cuál es el bueno.
            if ($segmento === null && $def['viejo'] !== null) {
                $segmento = $repo->findOneBy(['slug' => $def['viejo']]);

                if ($segmento !== null) {
                    $io->text(sprintf('  %s · %s → %s', $simula ? 'mudaría' : 'mudado ', $def['viejo'], $def['slug']));

                    if (!$simula) {
                        $segmento->setSlug($def['slug']);
                        $walking->removeSegmento($segmento);
                        $servicio?->addSegmento($segmento);
                    }

                    continue;
                }
            }

            if ($segmento !== null) {
                $io->text(sprintf('  ya existe · %s', $def['slug']));
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $def['slug']));

            if ($simula) {
                continue;
            }

            $segmento = (new TravelSegmento())
                ->setSlug($def['slug'])
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setContenido([['language' => 'es', 'content' => $def['contenido']]])
                ->setInicioModo(PuntoModoEnum::FIJO)
                ->setInicioPunto($aeropuerto)
                ->setFinModo(PuntoModoEnum::FIJO)
                ->setFinPunto($aeropuerto);
            $this->em->persist($segmento);

            $componente = (new TravelComponente())
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setTipo(ComponenteTipoEnum::ALIMENTACION_HORARIO_VAR);
            $this->em->persist($componente);

            $tarifa = $this->tarifaSimple($componente, $def['nombre'], $def['titulo'], $moneda);

            $this->em->persist(
                (new TravelSegmentoComponente())
                    ->setSegmento($segmento)
                    ->setComponente($componente)
                    ->setTarifaPredeterminada($tarifa)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setOrden(1)
                    ->setHora(new \DateTimeImmutable($def['hora'])),
            );

            $servicio?->addSegmento($segmento);
            $servicio?->addComponente($componente);
        }
    }

    private function traslados(
        TravelServicio $walking,
        TravelPunto $aeropuerto,
        MaestroMoneda $moneda,
        SymfonyStyle $io,
        bool $simula,
    ): void {
        foreach (self::TRASLADOS as $def) {
            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']]);

            if ($segmento === null) {
                $io->text(sprintf('  no existe · %s', $def['slug']));
                continue;
            }

            if ($this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $def['componente']]) !== null) {
                $io->text(sprintf('  ya existe · %s', $def['componente']));
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $def['componente']));

            if ($simula) {
                continue;
            }

            $componente = (new TravelComponente())
                ->setNombreInterno($def['componente'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setTipo(ComponenteTipoEnum::TRANSPORTE);
            $this->em->persist($componente);
            $walking->addComponente($componente);

            $flota = [];

            foreach (self::FLOTA as $vehiculo => $capacidad) {
                $tarifa = new TravelTarifa();
                $tarifa->setComponente($componente);
                $tarifa->setNombreInterno(sprintf('%s · %s', $vehiculo, $def['titulo']));
                $tarifa->setTitulo([['language' => 'es', 'content' => $vehiculo]]);
                $tarifa->setMoneda($moneda);
                $tarifa->setMonto('0.00');
                $tarifa->setModalidad(TarifaModalidadEnum::PRIVADO);
                $tarifa->setCostoPorGrupo(true);
                $tarifa->setCapacidadMaxima($capacidad);
                $this->em->persist($tarifa);
                $flota[] = $tarifa;
            }

            // Los extremos: el aeropuerto es fijo y el otro lado lo pone el itinerario.
            if ($def['desdeAeropuerto']) {
                $segmento->setInicioModo(PuntoModoEnum::FIJO)->setInicioPunto($aeropuerto);
            } else {
                $segmento->setFinModo(PuntoModoEnum::FIJO)->setFinPunto($aeropuerto);
            }

            // El enlace global, con la tarifa por defecto.
            $this->em->persist(
                (new TravelSegmentoComponente())
                    ->setSegmento($segmento)
                    ->setComponente($componente)
                    ->setTarifaPredeterminada($flota[0])
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setOrden(1),
            );

            // Y las relaciones del ancla vieja se retiran, traspasando la hora promovida.
            $this->retirarAncla($segmento, $componente, $flota[0], $def['promueve'], $io);
        }
    }

    /**
     * Quita el componente `pool` que mezclaba vehículo y paseo, y le pasa su hora promovida al
     * transporte de ida.
     *
     * ⚠️ Si la promoción no se traspasa, el día pierde su hora de cabecera en la guía y cae al
     * desempate por `orden`. Ver `docs/Cotizaciones.md` §6.u.
     */
    private function retirarAncla(
        TravelSegmento $segmento,
        TravelComponente $nuevo,
        TravelTarifa $porDefecto,
        bool $promueve,
        SymfonyStyle $io,
    ): void {
        foreach ($segmento->getSegmentoComponentes() as $rel) {
            if ($rel->getComponente()?->getNombreInterno() !== self::ANCLA_VIEJA) {
                continue;
            }

            $contexto = $rel->getItinerarioContexto();

            if ($promueve && $contexto !== null) {
                $io->text(sprintf('  hora de servicio completo → %s', $nuevo->getNombreInterno() ?? ''));

                $this->em->persist(
                    (new TravelSegmentoComponente())
                        ->setSegmento($segmento)
                        ->setComponente($nuevo)
                        ->setTarifaPredeterminada($porDefecto)
                        ->setItinerarioContexto($contexto)
                        ->setModo(ComponenteModoEnum::INCLUIDO)
                        ->setOrden(1)
                        ->setDia($rel->getDia())
                        ->setHora($rel->getHora())
                        ->setHoraServicioCompleto(true),
                );
            }

            $io->text(sprintf('  retirada · «%s» de %s', self::ANCLA_VIEJA, (string) $segmento->getSlug()));
            $this->em->remove($rel);
        }
    }

    private function tarifaSimple(
        TravelComponente $componente,
        string $nombre,
        string $titulo,
        MaestroMoneda $moneda,
    ): TravelTarifa {
        $tarifa = new TravelTarifa();
        $tarifa->setComponente($componente);
        $tarifa->setNombreInterno($nombre);
        $tarifa->setTitulo([['language' => 'es', 'content' => $titulo]]);
        $tarifa->setMoneda($moneda);
        $tarifa->setMonto('0.00');
        $this->em->persist($tarifa);

        return $tarifa;
    }
}
