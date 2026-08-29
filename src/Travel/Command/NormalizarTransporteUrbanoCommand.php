<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\TarifaModalidadEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fase 2 del refactor de transporte: **dos componentes por ciudad**, urbano y aeropuerto.
 *
 * En Cusco había cinco componentes urbanos —terminal, paradero, restaurante ida, restaurante
 * vuelta— con **exactamente las mismas cuatro tarifas** repetidas, y ninguno con enlaces. Cinco
 * copias del mismo precio es donde se esconden los errores: la Van del aeropuerto y la del
 * terminal estaban **intercambiadas**, 40 donde tocaba 35 y al revés, y nadie lo vio.
 *
 * ## Por qué DOS y no uno con recargo
 *
 * El precio urbano y el de aeropuerto sólo se diferencian en el estacionamiento de la terminal
 * aérea. Cabía modelarlo como una tarifa más dentro de un único componente —«Van · con ingreso
 * al aeropuerto»—, que es lo más fiel al dato. Se descartó: obliga al operador a acordarse de
 * elegir la correcta, y **equivocarse ahí lo paga la operación, no el catálogo**. Con dos
 * componentes, elegir mal exige equivocarse de componente, que es mucho más difícil.
 *
 * ## Lo que NO hace
 *
 * Sólo Cusco. Las demás ciudades tienen la misma forma —Lima, Arequipa, Puno, Ica, Paracas,
 * Quillabamba repiten el par hotel↔terminal— pero **no tengo su cuadro de precios**, y
 * extrapolar el de Cusco sería inventar dinero. Se añaden a `CUADROS` cuando se sepan.
 *
 * Y no toca `Traslado a Restaurante con espera Cusco`: sus precios son más altos porque el
 * vehículo espera. Es otro servicio, no una copia.
 */
#[AsCommand(
    name: 'app:travel:normalizar-transporte-urbano',
    description: 'Deja un componente urbano y uno de aeropuerto por ciudad, con su cuadro de precios.',
)]
final class NormalizarTransporteUrbanoCommand extends Command
{
    /**
     * El cuadro, tal y como lo dictó el operador el 29/08/2026.
     *
     * ⚠️ La diferencia ES el estacionamiento del aeropuerto, no un margen: por eso el recargo no
     * es ni plano ni porcentual —+5, +5, +2, +4, +5— y no se puede calcular, hay que preguntarlo.
     *
     * ⚠️ El bloque de aeropuerto es **opcional**, y las tres ciudades enseñan por qué no se puede
     * asumir una forma única:
     *
     * - **Cusco** cobra un recargo por entrar a la terminal aérea → dos componentes.
     * - **Arequipa** cobra lo MISMO por aeropuerto y por terminal → uno solo, y el aeropuerto se
     *   absorbe como un destino urbano más.
     * - **Puno** tiene su aeropuerto en **Juliaca, a casi una hora** → eso no es urbano con
     *   recargo, es un tramo interurbano, y se queda como componente aparte sin tocarlo.
     *
     * @var array<string, array{urbano: array<string, array{monto: string, cap: int}>, aeropuerto?: array<string, array{monto: string, cap: int}>, canonicoUrbano: string, absorbidos: list<string>, canonicoAeropuerto?: string, absorbidosAeropuerto?: list<string>, nombreUrbano: string, nombreAeropuerto?: string}>
     */
    private const CUADROS = [
        'Cusco' => [
            'canonicoUrbano' => 'Transporte Term Cusco ↔ Htl Cusco',
            'nombreUrbano' => 'Transporte urbano en Cusco',
            'absorbidos' => [
                'Transporte Htl Cusco ↔ Paradero Cusco',
                'Transporte Htl - Rest Cusco',
                'Transporte Rest - Htl Cusco',
            ],
            'canonicoAeropuerto' => 'Transporte Hotel Cusco ↔ Aeropuerto Cusco',
            'nombreAeropuerto' => 'Transporte Aeropuerto Cusco ↔ Cusco (ida o vuelta)',
            'urbano' => [
                'Auto' => ['monto' => '25.00', 'cap' => 4],
                'Van' => ['monto' => '35.00', 'cap' => 8],
                'Master' => ['monto' => '14.00', 'cap' => 14],
                'Sprinter' => ['monto' => '16.00', 'cap' => 18],
                'Bus' => ['monto' => '20.00', 'cap' => 25],
            ],
            'aeropuerto' => [
                'Auto' => ['monto' => '30.00', 'cap' => 4],
                'Van' => ['monto' => '40.00', 'cap' => 8],
                'Master' => ['monto' => '16.00', 'cap' => 14],
                'Sprinter' => ['monto' => '20.00', 'cap' => 18],
                'Bus' => ['monto' => '25.00', 'cap' => 25],
            ],
        ],
        // Mismo precio por aeropuerto y por terminal: no hay recargo que separar, así que un
        // solo componente y el nombre lo dice. En USD, a diferencia del resto.
        'Arequipa' => [
            'canonicoUrbano' => 'Transporte Hotel Arequipa ↔ Terminal Terrestre Arequipa (ida o vuelta)',
            'nombreUrbano' => 'Transporte Aeropuerto o Terminal ↔ Arequipa (ida o vuelta)',
            'absorbidos' => [
                'Transporte Aeropuerto Arequipa - Hotel Arequipa',
                'Transporte Hotel Arequipa - Aeropuerto de Arequipa',
            ],
            'urbano' => [
                // Capacidades dictadas por el operador el 29/08/2026. Venían las dos a 4 —Van y
                // Auto igual—, que fue lo que hizo sospechar. Las de verdad son 3 y 5.
                'Auto' => ['monto' => '17.00', 'cap' => 3],
                'Van' => ['monto' => '17.00', 'cap' => 5],
                'Master' => ['monto' => '23.00', 'cap' => 14],
                'Sprinter' => ['monto' => '28.00', 'cap' => 14],
            ],
        ],

        // El Valle no es una ciudad, pero tiene exactamente la misma forma: un traslado desde
        // Cusco y otro desde su aeropuerto. Hoy cuestan lo mismo —40/73/99/120/160— y aun así
        // van separados, porque **el ingreso al aeropuerto es un caso aparte**: es donde aparece
        // el coste de entrada, igual que en Cusco. Tenerlos separados desde ya evita repetir la
        // fusión el día que ese coste se cobre.
        'Valle Sagrado' => [
            'canonicoUrbano' => 'Transporte Cusco ↔ Valle Sagrado (ida o vuelta)',
            'nombreUrbano' => 'Transporte Cusco ↔ Valle Sagrado (ida o vuelta)',
            'absorbidos' => [],
            'urbano' => [
                'Auto' => ['monto' => '40.00', 'cap' => 4],
                'Van' => ['monto' => '73.00', 'cap' => 8],
                'Master' => ['monto' => '99.00', 'cap' => 14],
                'Sprinter' => ['monto' => '120.00', 'cap' => 18],
                'Bus' => ['monto' => '160.00', 'cap' => 25],
            ],
            // ⚠️ Ojo al DOBLE ESPACIO: «Transporte  Aeropuerto». Es la razón de que la fusión
            // automática no lo emparejara con «Transporte Valle Sagrado - Aeropuerto», igual que
            // en Lima lo impidió una preposición. Los nombres se comparan literalmente.
            'canonicoAeropuerto' => 'Transporte  Aeropuerto Cusco - Valle Sagrado',
            'absorbidosAeropuerto' => ['Transporte Valle Sagrado - Aeropuerto'],
            'nombreAeropuerto' => 'Transporte Aeropuerto Cusco ↔ Valle Sagrado (ida o vuelta)',
            'aeropuerto' => [
                'Auto' => ['monto' => '40.00', 'cap' => 4],
                'Van' => ['monto' => '73.00', 'cap' => 8],
                'Master' => ['monto' => '99.00', 'cap' => 14],
                'Sprinter' => ['monto' => '120.00', 'cap' => 18],
                'Bus' => ['monto' => '160.00', 'cap' => 25],
            ],
        ],

        // Sin bloque de aeropuerto A PROPÓSITO: el de Puno está en Juliaca, a casi una hora.
        // `Transporte Aeropuerto Juliaca ↔ Hotel Puno` es interurbano y no se toca.
        'Puno' => [
            'canonicoUrbano' => 'Transporte Htl Puno - Terminal Terrestre Puno',
            'nombreUrbano' => 'Transporte urbano en Puno',
            'absorbidos' => [
                'Transporte Htl Puno - Estacion Tren Puno',
            ],
            'urbano' => [
                'Auto' => ['monto' => '25.00', 'cap' => 4],
                'Van' => ['monto' => '35.00', 'cap' => 8],
                'Master' => ['monto' => '50.00', 'cap' => 14],
                'Sprinter' => ['monto' => '70.00', 'cap' => 18],
                'Bus' => ['monto' => '100.00', 'cap' => 25],
            ],
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

        foreach (self::CUADROS as $ciudad => $cuadro) {
            $io->title($ciudad);

            // ⚠️ Por el nombre VIEJO y también por el NUEVO. Si sólo se busca el viejo, el
            // comando deja de encontrarse a sí mismo en cuanto renombra, y el cuadro deja de ser
            // la fuente de verdad: un cambio de precio o de capacidad ya no se puede aplicar
            // volviendo a correrlo. Se descubrió al corregir las plazas de Arequipa.
            $urbano = $this->componente($cuadro['canonicoUrbano']) ?? $this->componente($cuadro['nombreUrbano']);

            if ($urbano === null) {
                $io->text(sprintf('  <fg=red>no encontrada</> — ni «%s» ni «%s»', $cuadro['canonicoUrbano'], $cuadro['nombreUrbano']));
                continue;
            }

            $io->section('Urbano');
            $this->absorber($urbano, $cuadro['absorbidos'], $io, $simula);
            $this->ajustarAlCuadro($urbano, $cuadro['urbano'], $io, $simula);
            $this->renombrar($urbano, $cuadro['nombreUrbano'], $io, $simula);

            if (!isset($cuadro['canonicoAeropuerto'], $cuadro['aeropuerto'], $cuadro['nombreAeropuerto'])) {
                continue;
            }

            $aeropuerto = $this->componente($cuadro['canonicoAeropuerto']) ?? $this->componente($cuadro['nombreAeropuerto']);

            if ($aeropuerto === null) {
                $io->text(sprintf('  <fg=red>no encontrada</> — ni «%s» ni «%s»', $cuadro['canonicoAeropuerto'], $cuadro['nombreAeropuerto']));
                continue;
            }

            $io->section('Aeropuerto');
            $this->absorber($aeropuerto, $cuadro['absorbidosAeropuerto'] ?? [], $io, $simula);
            $this->ajustarAlCuadro($aeropuerto, $cuadro['aeropuerto'], $io, $simula);
            $this->renombrar($aeropuerto, $cuadro['nombreAeropuerto'], $io, $simula);
        }

        if (!$simula) {
            $this->em->flush();
        }

        if ($simula) {
            $io->newLine();
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * Los duplicados urbanos ceden sus enlaces y sus citas, y salen de los pools.
     *
     * @param list<string> $nombres
     */
    private function absorber(TravelComponente $canonico, array $nombres, SymfonyStyle $io, bool $simula): void
    {
        foreach ($nombres as $nombre) {
            $sobra = $this->componente($nombre);

            if ($sobra === null) {
                continue;
            }

            $relaciones = $this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $sobra]);

            foreach ($relaciones as $rel) {
                $io->text(sprintf('  mueve segmento · %s', $rel->getSegmento()?->getSlug() ?? '?'));

                if (!$simula) {
                    $rel->setComponente($canonico);
                    // La tarifa por defecto apuntaba a una que va a desaparecer: se resuelve
                    // después, en ajustarAlCuadro(), contra la superviviente del mismo nombre.
                }
            }

            $citas = $this->em->getRepository(CotizacionCotcomponente::class)
                ->findBy(['componenteMaestroId' => (string) $sobra->getId()]);

            $io->text(sprintf('  absorbe · %s (%d enlaces, %d líneas de cotización)', $nombre, count($relaciones), count($citas)));

            if (!$simula) {
                foreach ($citas as $cita) {
                    // El snapshot no se toca: dice lo que se vendió. Sólo el puntero al maestro.
                    $cita->setComponenteMaestroId((string) $canonico->getId());
                }

                foreach ($sobra->getServicios() as $servicio) {
                    $servicio->removeComponente($sobra);
                }
            }
        }
    }

    /**
     * Deja EXACTAMENTE una tarifa por vehículo, con el importe y la capacidad del cuadro.
     *
     * Lo que sobra —duplicados internos, las dos Van partidas por sentido— se borra reapuntando
     * antes lo que la citaba. Lo que falta se crea.
     *
     * @param array<string, array{monto: string, cap: int}> $cuadro
     */
    private function ajustarAlCuadro(TravelComponente $componente, array $cuadro, SymfonyStyle $io, bool $simula): void
    {
        /** @var array<string, list<TravelTarifa>> $porVehiculo */
        $porVehiculo = [];

        foreach ($componente->getTarifas() as $tarifa) {
            // «Van · Term Cusco → Htl Cusco» vuelve a ser «Van»: el sentido dejó de existir.
            $vehiculo = trim(explode('·', $tarifa->getNombreInterno() ?? '')[0]);
            $porVehiculo[$vehiculo][] = $tarifa;
        }

        foreach ($cuadro as $vehiculo => $valores) {
            $existentes = $porVehiculo[$vehiculo] ?? [];
            $superviviente = $existentes[0] ?? null;

            if ($superviviente === null) {
                $io->text(sprintf('  %s · %s a %s', $simula ? 'crearía' : 'creada ', $vehiculo, $valores['monto']));

                if (!$simula) {
                    $nueva = new TravelTarifa();
                    $nueva->setComponente($componente);
                    $nueva->setNombreInterno($vehiculo);
                    $nueva->setTitulo([['language' => 'es', 'content' => $vehiculo]]);
                    $nueva->setMoneda($existentes === [] ? $this->monedaDe($componente) : $existentes[0]->getMoneda());
                    $nueva->setMonto($valores['monto']);
                    $nueva->setModalidad(TarifaModalidadEnum::PRIVADO);
                    $nueva->setCostoPorGrupo(true);
                    $nueva->setCapacidadMaxima($valores['cap']);
                    $this->em->persist($nueva);
                }

                continue;
            }

            $antes = sprintf('%s / cap %s', $superviviente->getMonto() ?? '?', $superviviente->getCapacidadMaxima() ?? '—');
            $despues = sprintf('%s / cap %d', $valores['monto'], $valores['cap']);

            if ($antes !== $despues || $superviviente->getNombreInterno() !== $vehiculo) {
                $io->text(sprintf('  ajusta · %-9s %s → %s', $vehiculo, $antes, $despues));

                if (!$simula) {
                    $superviviente->setNombreInterno($vehiculo);
                    $superviviente->setTitulo([['language' => 'es', 'content' => $vehiculo]]);
                    $superviviente->setMonto($valores['monto']);
                    $superviviente->setCapacidadMaxima($valores['cap']);
                }
            }

            // Los sobrantes del mismo vehículo: se van, cediendo antes lo que les cuelga.
            foreach (array_slice($existentes, 1) as $sobra) {
                $io->text(sprintf('  <fg=yellow>quita duplicada</> · %s (%s)', $sobra->getNombreInterno() ?? '', $sobra->getMonto() ?? ''));

                if (!$simula) {
                    $this->ceder($sobra, $superviviente);
                    $this->em->remove($sobra);
                }
            }
        }

        // Vehículos que están en el catálogo pero no en el cuadro: se avisan, no se tocan.
        foreach (array_diff(array_keys($porVehiculo), array_keys($cuadro)) as $extra) {
            $io->text(sprintf('  <fg=cyan>fuera del cuadro</> · %s — se deja como está', $extra));
        }
    }

    /**
     * Todo lo que apunta a la tarifa que muere pasa a la que queda: relaciones del catálogo y
     * citas de cotización.
     *
     * ⚠️ Olvidar las citas no rompe nada visible —el snapshot conserva nombre e importe— pero deja
     * la línea sin poder volver al maestro. Pasó en la fase 1 y costó trece enlaces al vacío.
     */
    private function ceder(TravelTarifa $muere, TravelTarifa $sobrevive): void
    {
        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['tarifaPredeterminada' => $muere]) as $rel) {
            $rel->setTarifaPredeterminada($sobrevive);
        }

        foreach ($this->em->getRepository(CotizacionCottarifa::class)->findBy(['tarifaMaestraId' => (string) $muere->getId()]) as $cita) {
            $cita->setTarifaMaestraId((string) $sobrevive->getId());
        }
    }

    private function renombrar(TravelComponente $componente, string $nombre, SymfonyStyle $io, bool $simula): void
    {
        if ($componente->getNombreInterno() === $nombre) {
            return;
        }

        $io->text(sprintf('  %s · <info>%s</info>', $simula ? 'renombraría a' : 'renombrado a', $nombre));

        if (!$simula) {
            $componente->setNombreInterno($nombre);
            $componente->setTitulo([['language' => 'es', 'content' => $nombre]]);
        }
    }

    private function componente(string $nombreInterno): ?TravelComponente
    {
        return $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombreInterno]);
    }

    private function monedaDe(TravelComponente $componente): ?\App\Entity\Maestro\MaestroMoneda
    {
        foreach ($componente->getTarifas() as $t) {
            if ($t->getMoneda() !== null) {
                return $t->getMoneda();
            }
        }

        return null;
    }
}
