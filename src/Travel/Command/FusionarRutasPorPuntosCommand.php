<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fusiona por sentido los componentes que nombran una RUTA, emparejándolos por sus PUNTOS.
 *
 * Es el mismo trabajo que `app:travel:fusionar-transportes-bidireccionales`, con una diferencia
 * que obliga a un comando aparte: **allí el par se detectaba por el nombre y aquí no se puede**.
 * «Transporte Cusco - Ollanta» se parte por el « - »; «Tren San Pedro Mapi» no se parte por nada
 * —¿San / Pedro Mapi? ¿San Pedro / Mapi?— y «Vuelo Cusco Puerto Maldonado» tampoco.
 *
 * Así que el par sale de `travel_segmento.inicioPunto` / `finPunto`, que es donde el origen y el
 * destino viven **como dato**. Dos componentes son el mismo si sus extremos están al revés. Es
 * inmune a cómo se escriba el nombre, que es la lección de esta jornada: el nombre es prosa.
 *
 * ## Por qué fusionar un tren o un vuelo
 *
 * Sus tarifas son **referenciales** —clases de PeruRail, equipaje— y se actualizan a mano. Con la
 * ficha por duplicado se actualiza una y no la otra: «IR 360 Adulto» estaba a 105 en un sentido y
 * a 60 en el otro, y eso **no significaba que subir costara más**, significaba que sólo se tocó
 * un lado. Ése es el argumento, y es el mismo que valió para las vans.
 *
 * ⚠️ **Fusionar y mostrar son la misma decisión.** Al unir los dos sentidos el nombre deja de
 * decir hacia dónde se va hoy; quien lo sabe es el segmento. Por eso
 * {@see ComponenteTipoEnum::mandaElSegmento()} cubre exactamente los tipos que
 * {@see ComponenteTipoEnum::nombraUnaRuta()}. Uno sin el otro deja al proveedor una flecha de dos
 * puntas en grande y el destino escondido debajo.
 *
 * ## Las reglas
 *
 * - **Importes distintos → gana el MAYOR.** Son referenciales y todo sube: el bajo es el que se
 *   quedó sin actualizar. ⚠️ Lo contrario de lo que se decidió para el `Auto` de Paracas↔Ica,
 *   donde 300 vs 200 era un error y ganó el bajo. La diferencia es si el número es una referencia
 *   o un pacto, y eso no lo puede deducir un comando.
 * - **Capacidades a la menor**, como en todo el refactor: vender una plaza que no existe se
 *   descubre en el andén.
 * - **El nombre se reconstruye desde los puntos**, no desde el nombre viejo: «Tren Ollantaytambo
 *   ↔ Machu Picchu», no «Tren Ollanta Mapi ↔ …». Si la fuente de la verdad son los puntos, el
 *   nombre que sale de ellos también.
 * - Lo que se borra **cede antes** sus relaciones y sus citas de cotización.
 */
#[AsCommand(
    name: 'app:travel:fusionar-rutas-por-puntos',
    description: 'Une ida y vuelta de trenes y vuelos emparejando por los puntos del segmento.',
)]
final class FusionarRutasPorPuntosCommand extends Command
{
    /** @var list<ComponenteTipoEnum> */
    private const TIPOS = [ComponenteTipoEnum::TREN, ComponenteTipoEnum::VUELO];

    /** Prefijos que sobran al construir el nombre desde el punto. @var list<string> */
    private const RUIDO = ['Estación de ', 'Estación ', 'Aeropuerto de ', 'Aeropuerto '];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué fusionaría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        /** @var array<string, array{comp: TravelComponente, desde: string, hasta: string}> $porRuta */
        $porRuta = [];
        $ambiguos = [];

        foreach (self::TIPOS as $tipo) {
            foreach ($this->em->getRepository(TravelComponente::class)->findBy(['tipo' => $tipo]) as $componente) {
                $extremos = $this->extremos($componente);

                if ($extremos === null) {
                    $ambiguos[] = (string) $componente->getNombreInterno();
                    continue;
                }

                $porRuta[$tipo->value . '|' . $extremos[0] . '|' . $extremos[1]] = [
                    'comp' => $componente, 'desde' => $extremos[0], 'hasta' => $extremos[1],
                ];
            }
        }

        $vistos = [];
        $pares = 0;
        $mayores = [];

        foreach ($porRuta as $clave => $datos) {
            [$tipo, $a, $b] = explode('|', $clave, 3);
            $inverso = $tipo . '|' . $b . '|' . $a;

            if (!isset($porRuta[$inverso]) || isset($vistos[$clave]) || isset($vistos[$inverso])) {
                continue;
            }

            $vistos[$clave] = $vistos[$inverso] = true;
            ++$pares;

            $uno = $datos['comp'];
            $otro = $porRuta[$inverso]['comp'];
            $canonico = $this->enlaces($otro) > $this->enlaces($uno) ? $otro : $uno;
            $secundario = $canonico === $uno ? $otro : $uno;

            $nombre = sprintf('%s %s ↔ %s (ida o vuelta)', ucfirst($tipo), $this->corto($a), $this->corto($b));

            $io->section(sprintf('%s  +  %s', $canonico->getNombreInterno() ?? '', $secundario->getNombreInterno() ?? ''));
            $io->text(sprintf('  queda como · <info>%s</info>', $nombre));

            $this->fusionarTarifas($canonico, $secundario, $io, $simula, $mayores);
            $this->mover($canonico, $secundario, $io, $simula);

            if (!$simula) {
                $canonico->setNombreInterno($nombre);

                foreach ($secundario->getServicios() as $servicio) {
                    $servicio->removeComponente($secundario);
                }
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('  pares fusionados: %d', $pares));

        if ($mayores !== []) {
            $io->section(sprintf('Importes distintos — gana el mayor (%d)', count($mayores)));
            $io->listing($mayores);
        }

        if ($ambiguos !== []) {
            $io->section(sprintf('Sin par o sin puntos, se dejan (%d)', count($ambiguos)));
            $io->listing($ambiguos);
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * Los extremos del componente, leídos de sus segmentos.
     *
     * Null si no tiene segmentos, si le faltan puntos, o si **sus segmentos discrepan**: un
     * componente que sale de dos sitios distintos no es una ruta, y fusionarlo por «la primera
     * que aparezca» sería elegir a ciegas.
     *
     * @return array{0: string, 1: string}|null
     */
    private function extremos(TravelComponente $componente): ?array
    {
        $encontrado = null;

        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $componente]) as $rel) {
            $segmento = $rel->getSegmento();
            $desde = $segmento?->getInicioPunto()?->getNombre();
            $hasta = $segmento?->getFinPunto()?->getNombre();

            if ($desde === null || $hasta === null) {
                return null;
            }

            $par = [$desde, $hasta];

            if ($encontrado !== null && $encontrado !== $par) {
                return null;
            }

            $encontrado = $par;
        }

        return $encontrado;
    }

    /** @param list<string> $mayores */
    private function fusionarTarifas(
        TravelComponente $canonico,
        TravelComponente $secundario,
        SymfonyStyle $io,
        bool $simula,
        array &$mayores,
    ): void {
        /** @var array<string, TravelTarifa> $delCanonico */
        $delCanonico = [];

        foreach ($canonico->getTarifas() as $t) {
            $delCanonico[mb_strtolower(trim($t->getNombreInterno() ?? ''))] = $t;
        }

        foreach ($secundario->getTarifas() as $tarifa) {
            $gemela = $delCanonico[mb_strtolower(trim($tarifa->getNombreInterno() ?? ''))] ?? null;

            if ($gemela === null) {
                $io->text(sprintf('    muda · %s', $tarifa->getNombreInterno() ?? ''));

                if (!$simula) {
                    $tarifa->setComponente($canonico);
                }

                continue;
            }

            $aqui = (float) ($gemela->getMonto() ?? '0');
            $alla = (float) ($tarifa->getMonto() ?? '0');

            if ($aqui !== $alla) {
                $gana = max($aqui, $alla);
                $mayores[] = sprintf('%s · %s: %s y %s → %s', $canonico->getNombreInterno() ?? '',
                    $tarifa->getNombreInterno() ?? '', $gemela->getMonto() ?? '', $tarifa->getMonto() ?? '', number_format($gana, 2, '.', ''));
                $io->text(sprintf('    <fg=yellow>mayor</> · %s: %s / %s → %s',
                    $tarifa->getNombreInterno() ?? '', $gemela->getMonto() ?? '', $tarifa->getMonto() ?? '', number_format($gana, 2, '.', '')));

                if (!$simula) {
                    $gemela->setMonto(number_format($gana, 2, '.', ''));
                }
            }

            $menor = $this->menor($gemela->getCapacidadMaxima(), $tarifa->getCapacidadMaxima());

            if (!$simula) {
                $gemela->setCapacidadMaxima($menor);
                $this->ceder($tarifa, $gemela);
                $this->em->remove($tarifa);
            }
        }
    }

    private function mover(TravelComponente $canonico, TravelComponente $secundario, SymfonyStyle $io, bool $simula): void
    {
        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $secundario]) as $rel) {
            $io->text(sprintf('    segmento · %s', $rel->getSegmento()?->getSlug() ?? '?'));

            if (!$simula) {
                $rel->setComponente($canonico);
            }
        }

        $citas = $this->em->getRepository(CotizacionCotcomponente::class)
            ->findBy(['componenteMaestroId' => (string) $secundario->getId()]);

        if ($citas !== []) {
            $io->text(sprintf('    %d líneas de cotización reapuntadas', count($citas)));

            if (!$simula) {
                foreach ($citas as $cita) {
                    $cita->setComponenteMaestroId((string) $canonico->getId());
                }
            }
        }
    }

    private function ceder(TravelTarifa $muere, TravelTarifa $sobrevive): void
    {
        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['tarifaPredeterminada' => $muere]) as $rel) {
            $rel->setTarifaPredeterminada($sobrevive);
        }

        foreach ($this->em->getRepository(CotizacionCottarifa::class)->findBy(['tarifaMaestraId' => (string) $muere->getId()]) as $cita) {
            $cita->setTarifaMaestraId((string) $sobrevive->getId());
        }
    }

    private function menor(?int $a, ?int $b): ?int
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }

    private function corto(string $punto): string
    {
        return trim(str_replace(self::RUIDO, '', $punto));
    }

    private function enlaces(TravelComponente $c): int
    {
        return count($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $c]));
    }
}
