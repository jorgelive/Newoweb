<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelOrganizacion;
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
 * Crea el maestro completo del seguro de viaje: servicio, segmento, componente y tarifa.
 *
 * Sigue la receta de `docs/TravelCargaDeCatalogo.md` §4 entera, porque las cuatro piezas sueltas
 * se guardan sin protestar y el hueco sólo aparece al cotizar.
 *
 * ## Las tres decisiones que no se deducen leyendo el código
 *
 * **1. Pool suelto, sin plantilla** (§3). Un seguro no tiene día ni orden dentro de un guion: se
 * añade al expediente cuando el cliente lo quiere. La regla del doc es «si al venderlo tienes que
 * elegir *cuáles* y *en qué orden*, es un pool», y aquí no hay ni orden que elegir. Por eso no se
 * crea `TravelItinerario` ni sus `Rel`.
 *
 * **2. `EXTRAS`, y de ahí sale el «varios días».** Es el único tipo que encaja: `sinHorario()`
 * devuelve `true`, así que el componente no arrastra una hora inventada, y `puntosDeServicio()`
 * da `NINGUNO` —un seguro no recoge a nadie—. Y es justo lo que necesita el multi-día: en
 * `dominio/cotizacion/itinerarioVista.ts` un bloque se lee como periodo cuando
 * `!horaInicio && !horaFin && finPeriodo > base`. Con hora, el seguro saldría clavado en un solo
 * día a una hora que nadie fijó.
 *
 * ⚠️ **No es `ALOJAMIENTO`,** que también daría multi-día: ése declara
 * `esAnclaDeUbicacion() = true` y de él se deduce dónde duerme el pasajero cada noche. Un seguro
 * colgado de ahí envenenaría los puntos de recojo de todo lo demás.
 *
 * **3. Los 8 USD son POR PASAJERO Y POR DÍA, y el «por día» no es un campo.** El cálculo es
 * `monto × cantidadTarifa × cantidadComponente` (`util/src/stores/cotizacion/cotizacionEditorStore.ts`,
 * y `docs/Cotizaciones.md` §6.g.2). No existe un multiplicador de días en el catálogo: la tarifa
 * vale 8 la unidad con `costoPorGrupo = false` —o sea, por pasajero— y **los días los pone el
 * operador en la cantidad del componente**. Cinco días con tres pasajeros son 8 × 3 × 5.
 *
 * Poner `costoPorGrupo = true` habría hecho que 8 fuera el total del grupo, que es el mismo
 * número escrito en la ficha y una factura distinta.
 *
 * ## Por qué la organización va primero y con `visibleParaCliente`
 *
 * «Que se muestre al público» se decide en el catálogo, no en la cotización:
 * `TravelOrganizacion::$visibleParaCliente` es lo que siembra
 * `CotizacionCotcomponente::$prestadorVisible` al asignar la tarifa, y ése es el único
 * interruptor que lee el normalizer público. Creada sin él, La Positiva quedaría dentro del
 * precio y fuera de la vista del pasajero.
 *
 * Y va antes que nada porque `TravelOrganizacionServicio` la exige `NOT NULL` — es el paso 1 de
 * los cinco `flush()` de §4 bis.
 *
 * ⚠️ **Los setters de `TravelTarifa` NO se encadenan todos** (§7): `setComponente`,
 * `setNombreInterno`, `setTitulo` y `setMoneda` devuelven `void`. Una llamada por línea.
 */
#[AsCommand(
    name: 'app:travel:crear-seguro-de-viaje',
    description: 'Crea el maestro del seguro de viaje: servicio, segmento, componente y tarifa.'
)]
final class CrearSeguroDeViajeCommand extends Command
{
    private const ORG_NOMBRE = 'Seguros La Positiva';
    private const SERVICIO_CODIGO = 'SEGURO';
    private const SERVICIO_NOMBRE = 'Seguro de Viaje';
    private const SEGMENTO_SLUG = 'seguro-de-viaje';
    private const SEGMENTO_NOMBRE = 'Seguro de viaje';
    private const COMPONENTE_NOMBRE = 'Seguro de viaje';
    private const TARIFA_NOMBRE = 'Seguros La Positiva';
    private const MONEDA = 'USD';
    private const MONTO = '8.00';

    /** El título público del segmento: lo que el pasajero ve como encabezado del bloque. */
    private const SEGMENTO_TITULO = 'Seguro de viaje';

    /**
     * El relato del segmento.
     *
     * ⚠️ **El `<p>` no es estilo: es el contrato del campo** (§«La redacción»). `pax` lo pinta con
     * `v-html` dentro de un contenedor `prose` de Tailwind, que estiliza por selector de elemento;
     * un texto pelado sale con otro interlineado y pegado al de al lado, en la misma lista.
     *
     * Voz impersonal y concreta, sin entradilla en negrita ni emoji —la minoría del catálogo que
     * los usa rompe la voz dentro de un mismo expediente— y **lo que limita, al final**.
     */
    private const SEGMENTO_CONTENIDO = '<p>Cobertura de asistencia en viaje para los pasajeros del programa, '
        . 'contratada por día y vigente durante toda la estadía.</p>';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin tocar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $moneda = $this->em->getRepository(MaestroMoneda::class)->find(self::MONEDA);

        if ($moneda === null) {
            $io->error(sprintf('No existe la moneda «%s» en el maestro.', self::MONEDA));

            return Command::FAILURE;
        }

        $io->title('Seguro de viaje');

        // ── 1. La organización ──────────────────────────────────────────────
        // Antes que nada: es la única pieza que otra exige NOT NULL, y lleva el interruptor
        // que decide si el pasajero llega a leer el nombre de la aseguradora.
        $org = $this->em->getRepository(TravelOrganizacion::class)
            ->findOneBy(['nombreComercial' => self::ORG_NOMBRE]);

        if ($org === null) {
            $io->text(sprintf('  %s · organización %s (visible para el cliente)', $simula ? 'crearía' : 'creada ', self::ORG_NOMBRE));

            if (!$simula) {
                $org = (new TravelOrganizacion())
                    ->setNombreComercial(self::ORG_NOMBRE)
                    ->setTitulo([['language' => 'es', 'content' => self::ORG_NOMBRE]])
                    ->setVisibleParaCliente(true);
                $this->em->persist($org);
                $this->em->flush();
            }
        } else {
            $io->text(sprintf(
                '  ya existe · organización %s%s',
                self::ORG_NOMBRE,
                $org->isVisibleParaCliente() ? '' : ' ⚠ NO es visible para el cliente'
            ));
        }

        // ── 2. El servicio ──────────────────────────────────────────────────
        $servicio = $this->em->getRepository(TravelServicio::class)
            ->findOneBy(['codigo' => self::SERVICIO_CODIGO]);

        if ($servicio === null) {
            $io->text(sprintf('  %s · servicio %s (%s)', $simula ? 'crearía' : 'creado ', self::SERVICIO_NOMBRE, self::SERVICIO_CODIGO));

            if (!$simula) {
                $servicio = (new TravelServicio())
                    ->setNombreInterno(self::SERVICIO_NOMBRE)
                    ->setCodigo(self::SERVICIO_CODIGO)
                    ->setTitulo([['language' => 'es', 'content' => self::SERVICIO_NOMBRE]]);
                $this->em->persist($servicio);
            }
        } else {
            $io->text(sprintf('  ya existe · servicio %s', self::SERVICIO_CODIGO));
        }

        // ── 3. El segmento: lo que el pasajero LEE ──────────────────────────
        $segmento = $this->em->getRepository(TravelSegmento::class)
            ->findOneBy(['slug' => self::SEGMENTO_SLUG]);

        if ($segmento === null) {
            $io->text(sprintf('  %s · segmento %s', $simula ? 'crearía' : 'creado ', self::SEGMENTO_SLUG));

            if (!$simula) {
                $segmento = (new TravelSegmento())
                    ->setSlug(self::SEGMENTO_SLUG)
                    ->setNombreInterno(self::SEGMENTO_NOMBRE)
                    ->setTitulo([['language' => 'es', 'content' => self::SEGMENTO_TITULO]])
                    ->setContenido([['language' => 'es', 'content' => self::SEGMENTO_CONTENIDO]]);
                $this->em->persist($segmento);
            }
        } else {
            $io->text(sprintf('  ya existe · segmento %s', self::SEGMENTO_SLUG));
        }

        // ── 4. El componente: lo que se COMPRA ──────────────────────────────
        $componente = $this->em->getRepository(TravelComponente::class)
            ->findOneBy(['nombreInterno' => self::COMPONENTE_NOMBRE]);

        if ($componente === null) {
            $io->text(sprintf('  %s · componente %s (extras)', $simula ? 'crearía' : 'creado ', self::COMPONENTE_NOMBRE));

            if (!$simula) {
                $componente = (new TravelComponente())
                    ->setNombreInterno(self::COMPONENTE_NOMBRE)
                    ->setTitulo([['language' => 'es', 'content' => self::COMPONENTE_NOMBRE]])
                    ->setTipo(ComponenteTipoEnum::EXTRAS);
                $this->em->persist($componente);
            }
        } else {
            $io->text(sprintf('  ya existe · componente %s', self::COMPONENTE_NOMBRE));
        }

        // El flush del paso 4: el pivote y la tarifa exigen que segmento y componente existan.
        if (!$simula) {
            $this->em->flush();
        }

        // ── 5. La tarifa ────────────────────────────────────────────────────
        // La tarifa no tiene clave natural (§4 bis): su idempotencia es este par y sólo éste.
        //
        // ⚠️ La comprobación se hace TAMBIÉN en simulación, aunque en la primera pasada el
        // componente todavía no exista y no pueda existir la tarifa. Cortar antes era más corto y
        // dejaba a `--dry-run` diciendo «crearía» sobre una tarifa que ya estaba: una simulación
        // que miente en la segunda pasada deja de servir para revisar, que es para lo que existe.
        $tarifa = $componente === null ? null : $this->em->getRepository(TravelTarifa::class)
            ->findOneBy(['componente' => $componente, 'nombreInterno' => self::TARIFA_NOMBRE]);

        if ($tarifa !== null) {
            $io->text(sprintf('  ya existe · tarifa %s', self::TARIFA_NOMBRE));
        } else {
            $io->text(sprintf(
                '  %s · tarifa %s · %s %s por pasajero y día',
                $simula ? 'crearía' : 'creada ',
                self::TARIFA_NOMBRE,
                self::MONEDA,
                self::MONTO
            ));

            if (!$simula && $componente !== null) {
                $tarifa = new TravelTarifa();
                $tarifa->setNombreInterno(self::TARIFA_NOMBRE);
                $tarifa->setTitulo([['language' => 'es', 'content' => self::TARIFA_NOMBRE]]);
                $tarifa->setMoneda($moneda);
                $tarifa->setMonto(self::MONTO);
                $tarifa->setCostoPorGrupo(false);
                $tarifa->setPrestador($org);

                // `addTarifa()` mantiene las dos puntas; `setComponente()` a secas deja la
                // colección del componente vacía y `tarifaPorDefecto()` devolvería null aquí abajo.
                $componente->addTarifa($tarifa);
                $this->em->persist($tarifa);
            }
        }

        // ── 6. El pivote y los DOS pools ────────────────────────────────────
        // Global: sin `itinerarioContexto` ni `dia` —el 63% del catálogo— porque no hay plantilla.
        // Sin hora: `EXTRAS` es `sinHorario()`, y es lo que deja que el bloque se lea como periodo.
        $pivote = ($segmento === null || $componente === null) ? null
            : $this->em->getRepository(TravelSegmentoComponente::class)
                ->findOneBy(['segmento' => $segmento, 'componente' => $componente]);

        if ($pivote !== null) {
            $io->text('  ya existe · pivote segmento↔componente');

            // Idempotente no basta si lo que quedó escrito estaba mal (§«la tarifa por defecto
            // salió nula»): al reencontrarlo se rellena lo que falte en vez de saltarlo.
            if ($pivote->getTarifaPredeterminada() === null && $tarifa !== null) {
                $io->text('             ↳ le faltaba la tarifa por defecto: puesta');

                if (!$simula) {
                    $pivote->setTarifaPredeterminada($tarifa);
                }
            }
        } else {
            $io->text(sprintf('  %s · pivote segmento↔componente', $simula ? 'crearía' : 'creado '));

            if (!$simula && $segmento !== null && $componente !== null) {
                $this->em->persist(
                    (new TravelSegmentoComponente())
                        ->setSegmento($segmento)
                        ->setComponente($componente)
                        ->setTarifaPredeterminada($tarifa)
                        ->setModo(ComponenteModoEnum::INCLUIDO)
                        ->setOrden(1)
                );
            }
        }

        // Los dos, no sólo el de segmentos: con el de componentes vacío el segmento se arrastra
        // a un día sin nada que cobrar. Su PK es la pareja, así que repetirlo no duplica.
        if (!$simula && $servicio !== null && $segmento !== null && $componente !== null) {
            $servicio->addSegmento($segmento);
            $servicio->addComponente($componente);
        }

        $io->text(sprintf('  %s · pools de segmento y de componente del servicio', $simula ? 'llenaría' : 'llenos  '));

        if ($simula) {
            $io->success('Simulación: no se escribió nada.');

            return Command::SUCCESS;
        }

        $io->text('');
        $io->text('Traduciendo a los siete idiomas (esto tarda)…');
        $this->em->flush();

        $io->success(sprintf(
            'Seguro de viaje listo: servicio %s, segmento %s, componente %s y tarifa %s a %s %s por pasajero y día.',
            self::SERVICIO_CODIGO,
            self::SEGMENTO_SLUG,
            self::COMPONENTE_NOMBRE,
            self::TARIFA_NOMBRE,
            self::MONEDA,
            self::MONTO
        ));

        return Command::SUCCESS;
    }
}
