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
use App\Travel\Enum\TarifaCalculoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * El contenedor «Extras del programa» y los extras que cuelgan de él.
 *
 * Sigue la receta de `docs/TravelCargaDeCatalogo.md` §4 entera —organización, servicio, segmento,
 * componente, tarifa, pivote y **los dos pools**— porque las piezas sueltas se guardan sin
 * protestar y el hueco sólo aparece al cotizar.
 *
 * ## Por qué UN servicio contenedor y no uno por extra
 *
 * Es la forma dominante del catálogo, no una excepción: `VUELO` tiene 32 segmentos, `ALO` 19,
 * `ACT_RESORT` 14, `TRF_CUZ` 8. Nadie compra «el catálogo de vuelos», compra dos vuelos — y
 * «lo que se compra junto» (§2) significa **el repertorio del que eliges**, no un paquete cerrado.
 *
 * El que desentona es `SEGURO`: un servicio entero con 1 segmento y 1 componente. Montar kit,
 * álbum y coordinación así daría cuatro servicios de uno, y el operador buscándolos de uno en uno.
 *
 * Lo que permite meterlos juntos, y se verificó antes de decidirlo:
 *
 * - **El prestador cuelga de la TARIFA**, no del servicio (`TravelServicio` ni lo tiene). Así que
 *   un mismo contenedor mezcla sin problema al que fabrica los kits y al fotógrafo.
 * - **`TravelSegmento ↔ TravelServicio` es ManyToMany.** El contenedor no es una cárcel: un
 *   segmento puede estar en varios servicios a la vez.
 *
 * ⚠️ **Los slugs van SIN el código del contenedor** (`kit-de-viaje`, no `KIT-EXTRAS`). El doc tiene
 * la cicatriz: el desayuno del aeropuerto se llamaba `DES-WALK_MIR-AEROPUERTO` y al mudarlo hubo
 * que renombrarlo porque **el slug afirmaba una pertenencia que dejó de ser cierta**. Con slugs
 * neutros, partir «Extras» el día que crezca es añadir filas en el pool del servicio nuevo y nada
 * más. Por eso un contenedor ahora y partirlo sólo si pasa de ~15 segmentos: `VUELO` aguanta 32,
 * y partir antes es inventarse una taxonomía que habría que volver a decidir.
 *
 * ## El tipo, y el que NO es
 *
 * `EXTRAS` declara `sinHorario() = true` —no le inventa una cita a algo que no la tiene— y
 * `puntosDeServicio() = NINGUNO` —un kit no recoge a nadie—.
 *
 * ⚠️ **No `ALOJAMIENTO`**, que también daría multi-día: ése es `esAnclaDeUbicacion()` y de él se
 * deduce dónde duerme el pasajero, así que colgar un extra de ahí envenena los puntos de recojo de
 * todo lo demás.
 *
 * La coordinación va en `PERSONAL_EXTRA`, que es lo que es: personal extra que acompaña. Se
 * comporta igual que `EXTRAS` —`sinHorario`, `NINGUNO`— pero lo dice. ⚠️ **Es su primer uso**: el
 * tipo existía con cero componentes y sin docblock. Y su `value` se congela en los snapshots
 * (`CotizacionCotcomponente::$tipo` es un string), así que renombrarlo después obliga a migrar.
 *
 * ## El `calculo` de cada uno, que es la decisión de verdad
 *
 * ```
 * Kit de viaje    individual   15 por cabeza de verdad: 60 pax son 60 kits
 * Álbum           grupal       precio global: se contrata una cobertura, no 60
 * Coordinación    grupal       no compras 60 coordinaciones: compras un coordinador
 * ```
 *
 * ⚠️ **En `grupal` el monto YA es el total del grupo** y el clasificador lo reparte entre los
 * pasajeros. Ponerlo `individual` escribiría el mismo número en la ficha y una factura distinta
 * —`monto × cantidad × unidades`—, que es el fallo que dobló los traslados hasta el 27/08/2026.
 *
 * Y si algún día la coordinación tiene que repartirse **sin que el cliente la vea como línea**,
 * eso es `operativa` y se marca al cotizar: hasta la fase 5 de `docs/PlanModalidadDeTarifa.md` ni
 * siquiera se podía publicar una cotización que la llevara.
 *
 * ## ⚠️ El «por día» no es un campo, y la cantidad del componente es 1
 *
 * El cálculo es `monto × cantidadTarifa × cantidadComponente` y no existe multiplicador de días en
 * el catálogo: el seguro vale 8 la unidad y **los días los pone el operador en la cantidad del
 * componente**. Estos tres no se cuentan por días —un kit es uno por persona para todo el viaje,
 * el álbum es uno por viaje—, así que su cantidad es 1 y ahí se queda.
 *
 * ## Por qué el costo entra por opción y no como constante
 *
 * La estructura y el texto son decisión del catálogo y van escritos aquí. **El dinero es decisión
 * comercial**, cambia, y no puede inventarse un valor por defecto: un costo inventado se guarda
 * sin protestar y sale en una cotización. Un extra sin `--*-costo` **no se carga**, y lo dice.
 *
 * ⚠️ Ojo con lo que NO son estos números: `TravelTarifa::$monto` es el **costo**. El precio que
 * lee el cliente sale de la comisión de la cotización. Los 15 / 20 / 100 de una tabla de venta no
 * se cargan aquí tal cual.
 *
 * ## El prestador puede faltar, y tiene consecuencia
 *
 * `TravelTarifa::$prestador` es nulable y sin él la tarifa es «interna». ⚠️ **Un extra sin
 * prestador no genera orden de servicio**: no hay a quién mandarle nada. Está bien para un kit que
 * arma la agencia, pero conviene que sea a propósito y no un descubrimiento.
 *
 * La organización nace con `visibleParaCliente = false`, que es lo correcto aquí: el pasajero no
 * necesita saber quién imprimió las gorras.
 *
 * ```
 * bin/console app:travel:crear-extras-programa --dry-run \
 *     --kit-costo=9.50 --kit-prestador="Publicidad XYZ" \
 *     --album-costo=1200 --album-prestador="Estudio ABC" \
 *     --coordinacion-costo=850
 * ```
 */
#[AsCommand(
    name: 'app:travel:crear-extras-programa',
    description: 'Crea el contenedor «Extras del programa» con el kit, el álbum y la coordinación.'
)]
final class CrearExtrasDelProgramaCommand extends Command
{
    private const SERVICIO_CODIGO = 'EXTRAS';
    private const SERVICIO_NOMBRE = 'Extras del programa';
    private const MONEDA = 'USD';

    /**
     * Los extras, con todo lo que NO es dinero.
     *
     * La clave es la que forma las opciones: `kit` → `--kit-costo` y `--kit-prestador`.
     *
     * @var array<string, array{
     *     slug: string,
     *     nombre: string,
     *     contenido: string,
     *     tipo: ComponenteTipoEnum,
     *     calculo: TarifaCalculoEnum
     * }>
     */
    private const EXTRAS = [
        'kit' => [
            'slug' => 'kit-de-viaje',
            'nombre' => 'Kit de viaje',
            'contenido' => '<p>Cada pasajero recibe su kit de viaje personalizado con su nombre: '
                . 'cubre maletas, gorra del viaje de promoción y tomatodo.</p>',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'calculo' => TarifaCalculoEnum::INDIVIDUAL,
        ],
        'album' => [
            'slug' => 'album-fotografico',
            'nombre' => 'Álbum fotográfico',
            'contenido' => '<p>Un fotógrafo acompaña al grupo durante el programa: la llegada, las '
                . 'excursiones y las actividades. Al terminar el viaje el grupo recibe el video y '
                . 'las fotografías.</p>',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            // Precio global: se contrata una cobertura del viaje, no una por pasajero.
            'calculo' => TarifaCalculoEnum::GRUPAL,
        ],
        'coordinacion' => [
            'slug' => 'coordinacion',
            'nombre' => 'Coordinación',
            'contenido' => '<p>Un coordinador de la agencia acompaña al grupo durante todo el '
                . 'programa, desde la salida hasta el retorno.</p>',
            'tipo' => ComponenteTipoEnum::PERSONAL_EXTRA,
            'calculo' => TarifaCalculoEnum::GRUPAL,
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin tocar nada.');

        foreach (array_keys(self::EXTRAS) as $clave) {
            $this->addOption(
                $clave . '-costo',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('COSTO de «%s» (no el precio de venta). Sin esto, no se carga.', self::EXTRAS[$clave]['nombre'])
            );
            $this->addOption(
                $clave . '-prestador',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Nombre comercial de quien provee «%s». Vacío = interno, sin orden de servicio.', self::EXTRAS[$clave]['nombre'])
            );
        }
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

        $io->title('Extras del programa');

        $servicio = $this->resolverServicio($io, $simula);
        $cargados = 0;

        foreach (self::EXTRAS as $clave => $extra) {
            $costo = $this->leerCosto($input, $io, $clave);

            if ($costo === null) {
                continue;
            }

            $io->section($extra['nombre']);

            // ⚠️ El nombre y la entidad van por separado, y no es ceremonia: en simulación la
            // entidad NO se crea, así que si el mensaje se guiara por ella diría «interna, no
            // genera orden de servicio» dos líneas después de decir «crearía la organización».
            // Una simulación que miente deja de servir para revisar, que es para lo que existe.
            $nombrePrestador = $this->leerNombrePrestador($input, $clave);
            $prestador = $nombrePrestador === null ? null : $this->resolverOrganizacion($io, $simula, $nombrePrestador);

            $this->crearExtra($io, $simula, $servicio, $extra, $costo, $nombrePrestador, $prestador, $moneda);
            ++$cargados;
        }

        if ($cargados === 0) {
            $io->warning('Ningún extra traía su `--*-costo`: no se cargó nada. Ver `--help`.');

            return Command::SUCCESS;
        }

        if ($simula) {
            $io->success(sprintf('Simulación de %d extra(s): no se escribió nada.', $cargados));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d extra(s) en el catálogo.', $cargados));

        return Command::SUCCESS;
    }

    /** El costo, o `null` si no se pasó — que es la señal de «este no se carga». */
    private function leerCosto(InputInterface $input, SymfonyStyle $io, string $clave): ?string
    {
        $crudo = $input->getOption($clave . '-costo');

        if ($crudo === null) {
            $io->text(sprintf('  omitido · %s (sin --%s-costo)', self::EXTRAS[$clave]['nombre'], $clave));

            return null;
        }

        if (!is_string($crudo) || preg_match('/^\d+(\.\d{1,2})?$/', $crudo) !== 1) {
            // El mismo formato que exige `TravelTarifa::$monto`: mejor parar aquí que guardar
            // «1,200» y descubrirlo como 1.00 en una cotización.
            $io->error(sprintf('El costo de «%s» no es un decimal válido: %s', $clave, get_debug_type($crudo)));

            throw new \InvalidArgumentException(sprintf('Costo inválido para «%s».', $clave));
        }

        return $crudo;
    }

    /** El nombre que se pidió, limpio. `null` = tarifa interna, sin orden de servicio. */
    private function leerNombrePrestador(InputInterface $input, string $clave): ?string
    {
        $nombre = $input->getOption($clave . '-prestador');

        if (!is_string($nombre) || trim($nombre) === '') {
            return null;
        }

        return trim($nombre);
    }

    /** La organización, creándola si hace falta. En simulación devuelve `null` y no escribe. */
    private function resolverOrganizacion(SymfonyStyle $io, bool $simula, string $nombre): ?TravelOrganizacion
    {
        $org = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => $nombre]);

        if ($org !== null) {
            return $org;
        }

        $io->text(sprintf('  %s · organización %s', $simula ? 'crearía' : 'creada ', $nombre));

        if ($simula) {
            return null;
        }

        // Nace oculta al cliente, que es lo correcto para un proveedor de merchandising: el
        // pasajero no necesita saber quién imprimió las gorras.
        $org = (new TravelOrganizacion())
            ->setNombreComercial($nombre)
            ->setTitulo([['language' => 'es', 'content' => $nombre]]);

        $this->em->persist($org);
        $this->em->flush();

        return $org;
    }

    private function resolverServicio(SymfonyStyle $io, bool $simula): ?TravelServicio
    {
        $servicio = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => self::SERVICIO_CODIGO]);

        if ($servicio !== null) {
            $io->text(sprintf('  ya existe · servicio %s', self::SERVICIO_CODIGO));

            return $servicio;
        }

        $io->text(sprintf('  %s · servicio %s (%s)', $simula ? 'crearía' : 'creado ', self::SERVICIO_NOMBRE, self::SERVICIO_CODIGO));

        if ($simula) {
            return null;
        }

        $servicio = (new TravelServicio())
            ->setNombreInterno(self::SERVICIO_NOMBRE)
            ->setCodigo(self::SERVICIO_CODIGO)
            ->setTitulo([['language' => 'es', 'content' => self::SERVICIO_NOMBRE]]);

        $this->em->persist($servicio);
        $this->em->flush();

        return $servicio;
    }

    /**
     * Segmento, componente, tarifa, pivote y los dos pools. La receta §4 completa.
     *
     * @param array{slug: string, nombre: string, contenido: string, tipo: ComponenteTipoEnum, calculo: TarifaCalculoEnum} $extra
     */
    private function crearExtra(
        SymfonyStyle $io,
        bool $simula,
        ?TravelServicio $servicio,
        array $extra,
        string $costo,
        ?string $nombrePrestador,
        ?TravelOrganizacion $prestador,
        MaestroMoneda $moneda,
    ): void {
        // ── El segmento: lo que el pasajero LEE ─────────────────────────────
        $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $extra['slug']]);

        if ($segmento === null) {
            $io->text(sprintf('  %s · segmento %s', $simula ? 'crearía' : 'creado ', $extra['slug']));

            if (!$simula) {
                $segmento = (new TravelSegmento())
                    ->setSlug($extra['slug'])
                    ->setNombreInterno($extra['nombre'])
                    ->setTitulo([['language' => 'es', 'content' => $extra['nombre']]])
                    ->setContenido([['language' => 'es', 'content' => $extra['contenido']]]);
                $this->em->persist($segmento);
            }
        } else {
            $io->text(sprintf('  ya existe · segmento %s', $extra['slug']));
        }

        // ── El componente: lo que se COMPRA ─────────────────────────────────
        $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $extra['nombre']]);

        if ($componente === null) {
            $io->text(sprintf('  %s · componente %s (%s)', $simula ? 'crearía' : 'creado ', $extra['nombre'], $extra['tipo']->value));

            if (!$simula) {
                $componente = (new TravelComponente())
                    ->setNombreInterno($extra['nombre'])
                    ->setTitulo([['language' => 'es', 'content' => $extra['nombre']]])
                    ->setTipo($extra['tipo']);
                $this->em->persist($componente);
            }
        } else {
            $io->text(sprintf('  ya existe · componente %s', $extra['nombre']));
        }

        // El pivote y la tarifa exigen que segmento y componente existan.
        if (!$simula) {
            $this->em->flush();
        }

        // ── La tarifa ───────────────────────────────────────────────────────
        // Se llama como el prestador cuando lo hay —convención del catálogo, ver el seguro— y
        // como el extra cuando es interna. Ese nombre es su única clave natural (§4 bis).
        $tarifaNombre = $nombrePrestador ?? $extra['nombre'];

        $tarifa = $componente === null ? null : $this->em->getRepository(TravelTarifa::class)
            ->findOneBy(['componente' => $componente, 'nombreInterno' => $tarifaNombre]);

        if ($tarifa !== null) {
            $io->text(sprintf('  ya existe · tarifa %s', $tarifaNombre));
        } else {
            $io->text(sprintf(
                '  %s · tarifa %s · %s %s %s%s',
                $simula ? 'crearía' : 'creada ',
                $tarifaNombre,
                self::MONEDA,
                $costo,
                $extra['calculo'] === TarifaCalculoEnum::GRUPAL ? 'TOTAL del grupo' : 'por pasajero',
                $nombrePrestador === null ? ' · ⚠ interna, no genera orden de servicio' : ''
            ));

            if (!$simula && $componente !== null) {
                $tarifa = new TravelTarifa();
                $tarifa->setNombreInterno($tarifaNombre);
                $tarifa->setTitulo([['language' => 'es', 'content' => $extra['nombre']]]);
                $tarifa->setMoneda($moneda);
                $tarifa->setMonto($costo);
                $tarifa->setCalculo($extra['calculo']);
                $tarifa->setPrestador($prestador);

                // `addTarifa()` mantiene las dos puntas; `setComponente()` a secas deja la
                // colección del componente vacía y la tarifa por defecto saldría nula abajo.
                $componente->addTarifa($tarifa);
                $this->em->persist($tarifa);
            }
        }

        // ── El pivote y los DOS pools ───────────────────────────────────────
        // Sin `itinerarioContexto` ni `dia`: no hay plantilla. Sin hora: los dos tipos son
        // `sinHorario()`, y es lo que deja que el bloque se lea como periodo.
        $pivote = ($segmento === null || $componente === null) ? null
            : $this->em->getRepository(TravelSegmentoComponente::class)
                ->findOneBy(['segmento' => $segmento, 'componente' => $componente]);

        if ($pivote !== null) {
            $io->text('  ya existe · pivote segmento↔componente');

            // Idempotente no basta si lo que quedó escrito estaba mal: al reencontrarlo se
            // rellena lo que falte en vez de saltarlo.
            if ($pivote->getTarifaPredeterminada() === null && $tarifa !== null && !$simula) {
                $io->text('             ↳ le faltaba la tarifa por defecto: puesta');
                $pivote->setTarifaPredeterminada($tarifa);
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

        // Los dos, no sólo el de segmentos: con el de componentes vacío el segmento se arrastra a
        // un día sin nada que cobrar — es lo que le pasa hoy a `TRF_LIM`, 2 segmentos y 0
        // componentes. Su PK es la pareja, así que repetirlo no duplica.
        if (!$simula && $servicio !== null && $segmento !== null && $componente !== null) {
            $servicio->addSegmento($segmento);
            $servicio->addComponente($componente);
        }

        $io->text(sprintf('  %s · pools de segmento y de componente', $simula ? 'llenaría' : 'llenos  '));
    }
}
