<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\PuntoModoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Da al servicio Coco Bongo sus dos traslados, con un solo componente urbano.
 *
 * **Por qué UN componente para los dos sentidos.** `travel_componente` no tiene origen ni
 * destino: los pone el segmento (`inicioModo`/`finModo`). Y en un `TRANSPORTE`, el nombre que
 * llega al proveedor y al cliente es el del **segmento**
 * ({@see ComponenteTipoEnum::mandaElSegmento()}), no el del componente. Así que un componente por
 * sentido serían dos fichas con el mismo precio y el mismo vehículo cuya única diferencia —la
 * dirección— ya la dice quien manda. Es el mismo error que hubo que deshacer en Cusco, Lima y
 * Punta Cana; aquí se evita de entrada.
 *
 * **Y por qué URBANO y no un componente propio de Coco Bongo.** El traslado es un coche dentro de
 * Punta Cana: no cambia según a qué discoteca vayas. Siguiendo el patrón de `Transporte urbano en
 * Cusco` y `Transporte urbano en Puno`, la ficha es de la ciudad y el segmento pone el destino.
 * El día que haya otro sitio nocturno en Punta Cana, reutiliza el mismo componente.
 *
 * ⚠️ **Los nombres dicen «Punta Cana» a propósito.** Coco Bongo tiene local en varias ciudades, y
 * un segmento llamado sólo «Coco Bongo» es ambiguo en cuanto entre el de otra ciudad al catálogo.
 *
 * La flota se **clona** del traslado de aeropuerto en vez de rescribirla: son las mismas
 * modalidades, y copiar el número a mano es como divergieron las capacidades del `Auto` (3 en un
 * sentido, 4 en el otro) que hubo que unificar.
 */
#[AsCommand(
    name: 'app:travel:crear-traslado-coco-bongo',
    description: 'Crea el traslado urbano de Punta Cana y los dos segmentos de Coco Bongo.',
)]
final class CrearTrasladoCocoBongoCommand extends Command
{
    private const SERVICIO = 'Coco Bongo';

    /** De aquí sale la flota: mismas modalidades, mismas capacidades. */
    private const COMPONENTE_ORIGEN = 'Transporte Aeropuerto Punta Cana ↔ Hotel Punta Cana (ida o vuelta)';

    private const COMPONENTE = 'Transporte urbano en Punta Cana';

    /**
     * Los dos sentidos. `desdeAlojamiento` dice qué extremo es el hotel: el otro es Coco Bongo.
     *
     * @var list<array{slug: string, nombreInterno: string, titulo: string, desdeAlojamiento: bool}>
     */
    private const SEGMENTOS = [
        [
            'slug' => 'TRANS-HTL_PUJ-COCO_BONGO_PUJ',
            'nombreInterno' => 'Transporte Hotel Punta Cana - Coco Bongo Punta Cana',
            'titulo' => 'Transporte desde el alojamiento en Punta Cana a Coco Bongo Punta Cana',
            'desdeAlojamiento' => true,
        ],
        [
            'slug' => 'TRANS-COCO_BONGO_PUJ-HTL_PUJ',
            'nombreInterno' => 'Transporte Coco Bongo Punta Cana - Hotel Punta Cana',
            'titulo' => 'Transporte desde Coco Bongo Punta Cana al alojamiento en Punta Cana',
            'desdeAlojamiento' => false,
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

        $servicio = $this->em->getRepository(TravelServicio::class)->findOneBy(['nombreInterno' => self::SERVICIO]);

        if ($servicio === null) {
            $io->error(sprintf('No existe el servicio «%s».', self::SERVICIO));

            return Command::FAILURE;
        }

        [$componente, $flota] = $this->componenteUrbano($io, $simula);

        if ($componente === null && !$simula) {
            return Command::FAILURE;
        }

        $this->segmentos($servicio, $componente, $this->tarifaPorDefecto($flota), $io, $simula);

        if (!$simula) {
            $this->em->flush();
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        } else {
            $io->note('Los precios quedan en 0.00: hay que negociarlos y ponerlos a mano.');
        }

        return Command::SUCCESS;
    }

    /**
     * Crea el componente urbano clonando la flota del traslado de aeropuerto.
     *
     * Devuelve el componente **y su flota**: la lista es la fuente fiable justo después de
     * `persist()`, la colección del componente todavía no.
     *
     * @return array{0: TravelComponente|null, 1: list<TravelTarifa>}
     */
    private function componenteUrbano(SymfonyStyle $io, bool $simula): array
    {
        $repo = $this->em->getRepository(TravelComponente::class);
        $existente = $repo->findOneBy(['nombreInterno' => self::COMPONENTE]);

        if ($existente !== null) {
            $io->text(sprintf('  ya existe · %s (%d tarifas)', self::COMPONENTE, $existente->getTarifas()->count()));

            return [$existente, array_values($existente->getTarifas()->toArray())];
        }

        $origen = $repo->findOneBy(['nombreInterno' => self::COMPONENTE_ORIGEN]);

        if ($origen === null) {
            $io->error(sprintf('No existe el componente del que copiar la flota: «%s».', self::COMPONENTE_ORIGEN));

            return [null, []];
        }

        $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', self::COMPONENTE));

        $componente = null;
        $flota = [];

        if (!$simula) {
            $componente = (new TravelComponente())
                ->setNombreInterno(self::COMPONENTE)
                // Sólo español: el listener de AutoTranslate pone los otros seis idiomas.
                ->setTitulo([['language' => 'es', 'content' => self::COMPONENTE]])
                ->setTipo(ComponenteTipoEnum::TRANSPORTE);
            $this->em->persist($componente);
        }

        foreach ($origen->getTarifas() as $modelo) {
            $vehiculo = (string) $modelo->getNombreInterno();

            $io->text(sprintf(
                '      %s tarifa · %-8s %s plazas',
                $simula ? 'clonaría' : 'clonada ',
                $vehiculo,
                $modelo->getCapacidadMaxima() ?? '—',
            ));

            if ($simula) {
                continue;
            }

            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            $tarifa->setNombreInterno($vehiculo);
            $tarifa->setTitulo([['language' => 'es', 'content' => $vehiculo]]);
            $tarifa->setMoneda($modelo->getMoneda());
            // ⚠️ El importe NO se clona: el urbano no cuesta lo mismo que el del aeropuerto —el
            // recargo de la terminal es un coste real, ver docs §«Dos componentes por ciudad»—.
            // Entra a 0.00 para que se negocie, no heredando un precio que sería mentira.
            $tarifa->setMonto('0.00');
            $tarifa->setModalidad($modelo->getModalidad());
            $tarifa->setCostoPorGrupo($modelo->isCostoPorGrupo());
            $tarifa->setCapacidadMaxima($modelo->getCapacidadMaxima());
            $this->em->persist($tarifa);
            $flota[] = $tarifa;
        }

        return [$componente, $flota];
    }

    /**
     * Crea los dos segmentos, los mete en el pool del servicio y los engancha al componente.
     */
    private function segmentos(
        TravelServicio $servicio,
        ?TravelComponente $componente,
        ?TravelTarifa $porDefecto,
        SymfonyStyle $io,
        bool $simula,
    ): void {
        $repo = $this->em->getRepository(TravelSegmento::class);

        foreach (self::SEGMENTOS as $def) {
            $yaEsta = $repo->findOneBy(['slug' => $def['slug']]);

            if ($yaEsta !== null) {
                $io->text(sprintf('  ya existe · %s', $def['slug']));
                $this->repararTarifaPorDefecto($yaEsta, $porDefecto, $io, $simula);
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $def['slug']));
            $io->text(sprintf('      %s', $def['titulo']));

            if ($simula || $componente === null) {
                continue;
            }

            $segmento = (new TravelSegmento())
                ->setSlug($def['slug'])
                ->setNombreInterno($def['nombreInterno'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]]);

            // El extremo del hotel es ALOJAMIENTO —lo resuelve el itinerario, que sabe dónde
            // duerme el grupo esa noche— y el de Coco Bongo es FIJO. Igual que los traslados de
            // aeropuerto de Punta Cana, que tampoco llevan `punto`: el nombre del segmento ya lo
            // dice y no hay `travel_lugar` para la discoteca.
            if ($def['desdeAlojamiento']) {
                $segmento->setInicioModo(PuntoModoEnum::ALOJAMIENTO)->setFinModo(PuntoModoEnum::FIJO);
            } else {
                $segmento->setInicioModo(PuntoModoEnum::FIJO)->setFinModo(PuntoModoEnum::ALOJAMIENTO);
            }

            $this->em->persist($segmento);
            $servicio->addSegmento($segmento);

            $this->em->persist(
                (new TravelSegmentoComponente())
                    ->setSegmento($segmento)
                    ->setComponente($componente)
                    ->setTarifaPredeterminada($porDefecto)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setOrden(1),
            );
        }
    }

    /**
     * Pone la tarifa por defecto en un enlace que se quedó sin ella.
     *
     * Existe porque la primera pasada de este comando los creó a null, y volver a ejecutarlo no
     * los habría tocado: idempotente no basta si lo que quedó escrito estaba mal.
     */
    private function repararTarifaPorDefecto(
        TravelSegmento $segmento,
        ?TravelTarifa $porDefecto,
        SymfonyStyle $io,
        bool $simula,
    ): void {
        if ($porDefecto === null) {
            return;
        }

        foreach ($segmento->getSegmentoComponentes() as $enlace) {
            if ($enlace->getTarifaPredeterminada() !== null) {
                continue;
            }

            $io->text(sprintf(
                '      %s tarifa por defecto · %s',
                $simula ? 'pondría' : 'puesta ',
                $porDefecto->getNombreInterno(),
            ));

            if (!$simula) {
                $enlace->setTarifaPredeterminada($porDefecto);
            }
        }
    }

    /**
     * La tarifa que entra si el operador no elige: la de menor capacidad.
     *
     * Sin `tarifaPredeterminada` el componente entra **sin precio** y no falla en ninguna parte
     * hasta que alguien mira el total. Ver docs/TravelCargaDeCatalogo.md §5.
     *
     * ⚠️ **Recibe la lista, no lee `getTarifas()`.** La primera versión leía la colección del
     * componente justo después de `persist()`, y `TravelTarifa::setComponente()` no mantiene el
     * lado inverso: la colección estaba **vacía**, así que los dos enlaces nacieron sin tarifa por
     * defecto. No falló nada —ni el comando, ni el flush— porque el campo admite null. Es
     * exactamente el fallo que este docblock advierte, cometido al escribirlo.
     *
     * @param list<TravelTarifa> $tarifas
     */
    private function tarifaPorDefecto(array $tarifas): ?TravelTarifa
    {
        usort(
            $tarifas,
            static fn (TravelTarifa $a, TravelTarifa $b): int
                => ($a->getCapacidadMaxima() ?? PHP_INT_MAX) <=> ($b->getCapacidadMaxima() ?? PHP_INT_MAX),
        );

        return $tarifas[0] ?? null;
    }
}
