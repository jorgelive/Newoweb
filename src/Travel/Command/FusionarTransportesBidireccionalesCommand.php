<?php

declare(strict_types=1);

namespace App\Travel\Command;

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
 * Fase 1 del refactor de transporte: un componente por RUTA, no por sentido.
 *
 * `Transporte Cusco - Ollanta` y `Transporte Ollanta - Cusco` son el mismo vehículo, el mismo
 * proveedor y el mismo precio. Existían por duplicado porque el nombre del componente era lo
 * único que le decía al proveedor a dónde ir. Desde el 29/08/2026 el nombre del SEGMENTO viaja
 * con la orden (`OperacionOrdenServicioItem::$nombreSegmento`), y el segmento es quien guarda
 * de verdad el origen y el destino — `travel_componente` no tiene esas columnas.
 *
 * Lo que la duplicación costó, medido antes de empezar: 94 componentes de transporte y 336
 * tarifas para 39 líneas de cotización reales, con divergencias que nadie vio en meses.
 *
 * ## Las reglas, y por qué
 *
 * - **Canónico = el más usado.** Gana el que tiene más enlaces a segmentos; a empate, el primero
 *   por nombre. Mover pocos enlaces es menos superficie de error que mover muchos.
 * - **Capacidades a la MENOR.** Decisión del operador: un `Auto` que dice 4 cuando caben 3 vende
 *   una plaza que no existe, y eso se descubre en el aeropuerto. Al revés sólo se pierde una venta.
 *   ⚠️ Un nulo NO es «menor»: es «sin medir», así que gana el número que sí está.
 * - **Los importes NO se fusionan si discrepan.** Elegir uno sería inventar dinero. La tarifa
 *   discrepante se conserva bajo el componente fusionado, con su sentido en el nombre, y sale
 *   listada al final para que alguien decida.
 * - **El componente secundario no se borra**: se queda sin enlaces y fuera de los pools. Una
 *   cotización histórica que lo cite sigue contando lo que se vendió. Reapuntarlas es la fase 3.
 *
 * ## Lo que este comando NO hace
 *
 * No toca cotizaciones ni órdenes emitidas, no colapsa el transporte urbano (fase 2) y no
 * renombra lugares. Una cosa por pasada.
 */
#[AsCommand(
    name: 'app:travel:fusionar-transportes-bidireccionales',
    description: 'Fusiona los pares A→B / B→A de transporte en un solo componente por ruta.',
)]
final class FusionarTransportesBidireccionalesCommand extends Command
{
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

        /** @var list<TravelComponente> $todos */
        $todos = $this->em->getRepository(TravelComponente::class)
            ->findBy(['tipo' => ComponenteTipoEnum::TRANSPORTE]);

        /** @var array<string, TravelComponente> $porRuta clave normalizada «a|b» */
        $porRuta = [];

        foreach ($todos as $c) {
            $extremos = $this->extremos($c->getNombreInterno() ?? '');

            if ($extremos !== null) {
                $porRuta[$extremos[0] . '|' . $extremos[1]] = $c;
            }
        }

        /** @var list<array{0: TravelComponente, 1: TravelComponente, 2: string, 3: string}> $pares */
        $pares = [];
        $vistos = [];

        foreach ($porRuta as $clave => $componente) {
            [$a, $b] = explode('|', $clave, 2);
            $inverso = $b . '|' . $a;

            if ($a === $b || !isset($porRuta[$inverso]) || isset($vistos[$inverso]) || isset($vistos[$clave])) {
                continue;
            }

            $vistos[$clave] = true;
            $vistos[$inverso] = true;

            $uno = $componente;
            $otro = $porRuta[$inverso];

            // El más usado manda: mover pocos enlaces es menos superficie de error.
            $canonico = $this->enlaces($otro) > $this->enlaces($uno) ? $otro : $uno;
            $secundario = $canonico === $uno ? $otro : $uno;

            $pares[] = [$canonico, $secundario, $a, $b];
        }

        if ($pares === []) {
            $io->success('No quedan pares direccionales: nada que fusionar.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d pares direccionales', count($pares)));

        $discrepancias = [];
        $capacidadesAjustadas = [];

        foreach ($pares as [$canonico, $secundario]) {
            $nombreFusion = $this->nombreBidireccional($canonico->getNombreInterno() ?? '');

            $io->section(sprintf('%s  ⟵⟶  %s', $canonico->getNombreInterno() ?? '', $secundario->getNombreInterno() ?? ''));
            $io->text(sprintf('  queda como · <info>%s</info>', $nombreFusion));

            $this->fusionarTarifas($canonico, $secundario, $io, $simula, $discrepancias, $capacidadesAjustadas);
            $this->moverEnlaces($canonico, $secundario, $io, $simula);

            if (!$simula) {
                $canonico->setNombreInterno($nombreFusion);
                $canonico->setTitulo([['language' => 'es', 'content' => $nombreFusion]]);

                // Fuera de los pools: deja de ofrecerse sin dejar de existir.
                foreach ($secundario->getServicios() as $servicio) {
                    $servicio->removeComponente($secundario);
                }
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $this->resumen($io, $capacidadesAjustadas, $discrepancias, $simula);

        return Command::SUCCESS;
    }

    /**
     * Las tarifas del secundario se funden con las del canónico por nombre.
     *
     * @param list<string> $discrepancias
     * @param list<string> $capacidadesAjustadas
     */
    private function fusionarTarifas(
        TravelComponente $canonico,
        TravelComponente $secundario,
        SymfonyStyle $io,
        bool $simula,
        array &$discrepancias,
        array &$capacidadesAjustadas,
    ): void {
        // ⚠️ Cada tarifa que sobreviva por duplicado tiene que decir SU sentido. «(sentido
        // inverso)» es inservible en un componente que ya no tiene sentido propio: no hay
        // respecto a qué. «Ica → Lima» se lee sin contexto, que es como se lee una orden.
        $sentidoCanonico = $this->sentido($canonico->getNombreInterno() ?? '');
        $sentidoSecundario = $this->sentido($secundario->getNombreInterno() ?? '');
        /** @var array<string, TravelTarifa> $delCanonico */
        $delCanonico = [];

        foreach ($canonico->getTarifas() as $t) {
            $delCanonico[$this->claveTarifa($t)] = $t;
        }

        foreach ($secundario->getTarifas() as $tarifa) {
            $clave = $this->claveTarifa($tarifa);
            $gemela = $delCanonico[$clave] ?? null;

            if ($gemela === null) {
                // Sólo existía en un sentido: se muda entera.
                $io->text(sprintf('    muda tarifa · %s', $tarifa->getNombreInterno() ?? ''));

                if (!$simula) {
                    $tarifa->setComponente($canonico);
                }

                continue;
            }

            if ($this->importe($gemela) !== $this->importe($tarifa)) {
                // ⚠️ Aquí NO se elige: elegir sería inventar dinero.
                $discrepancias[] = sprintf(
                    '«%s» — %s: %s   vs   %s: %s',
                    $tarifa->getNombreInterno() ?? '',
                    $sentidoCanonico,
                    $this->importe($gemela),
                    $sentidoSecundario,
                    $this->importe($tarifa),
                );

                $io->text(sprintf(
                    '    <fg=red>importe distinto</> · %s: %s (%s) vs %s (%s) — se conservan las dos',
                    $tarifa->getNombreInterno() ?? '',
                    $this->importe($gemela),
                    $sentidoCanonico,
                    $this->importe($tarifa),
                    $sentidoSecundario,
                ));

                if (!$simula) {
                    // Las DOS se renombran: si sólo se marcara una, la otra seguiría pareciendo
                    // «la normal» y nadie sabría que hay dos precios.
                    $gemela->setNombreInterno(sprintf('%s · %s', $gemela->getNombreInterno() ?? '', $sentidoCanonico));
                    $tarifa->setNombreInterno(sprintf('%s · %s', $tarifa->getNombreInterno() ?? '', $sentidoSecundario));
                    $tarifa->setComponente($canonico);
                }

                continue;
            }

            // Misma tarifa en los dos sentidos: sobra una, y la capacidad se resuelve a la menor.
            $menor = $this->capacidadMenor($gemela->getCapacidadMaxima(), $tarifa->getCapacidadMaxima());

            if ($menor !== $gemela->getCapacidadMaxima()) {
                $capacidadesAjustadas[] = sprintf(
                    '%s · «%s» %s → %d',
                    $canonico->getNombreInterno() ?? '',
                    $gemela->getNombreInterno() ?? '',
                    $gemela->getCapacidadMaxima() ?? 'sin medir',
                    $menor ?? 0,
                );

                $io->text(sprintf(
                    '    capacidad a la menor · %s: %s → %s',
                    $gemela->getNombreInterno() ?? '',
                    $gemela->getCapacidadMaxima() ?? 'sin medir',
                    $menor ?? 'sin medir',
                ));

                if (!$simula) {
                    $gemela->setCapacidadMaxima($menor);
                }
            }

            if (!$simula) {
                // Las relaciones que la usaban por defecto pasan a la gemela ANTES de quitarla.
                foreach ($this->relacionesConTarifa($tarifa) as $rel) {
                    $rel->setTarifaPredeterminada($gemela);
                }

                // ⚠️ Y las COTIZACIONES que la citaban, también. Se olvidó en la primera versión
                // y dejó trece `cotizacion_cottarifa.tarifa_maestra_id` apuntando al vacío: no
                // falla nada al guardar ni al servir —el snapshot conserva nombre e importe—,
                // simplemente la línea deja de poder volver al maestro. Un enlace muerto no
                // duele hasta que alguien intenta recalcular, y entonces ya nadie recuerda por qué.
                $this->reapuntarCotizaciones($tarifa, $gemela);

                $this->em->remove($tarifa);
            }
        }
    }

    private function moverEnlaces(
        TravelComponente $canonico,
        TravelComponente $secundario,
        SymfonyStyle $io,
        bool $simula,
    ): void {
        $relaciones = $this->em->getRepository(TravelSegmentoComponente::class)
            ->findBy(['componente' => $secundario]);

        foreach ($relaciones as $rel) {
            $io->text(sprintf('    mueve segmento · %s', $rel->getSegmento()?->getSlug() ?? '?'));

            if (!$simula) {
                $rel->setComponente($canonico);
            }
        }
    }

    /**
     * @param list<string> $capacidades
     * @param list<string> $discrepancias
     */
    private function resumen(SymfonyStyle $io, array $capacidades, array $discrepancias, bool $simula): void
    {
        $io->newLine();

        if ($capacidades !== []) {
            $io->section(sprintf('%d capacidades resueltas a la menor', count($capacidades)));
            $io->listing($capacidades);
        }

        if ($discrepancias !== []) {
            $io->section(sprintf('%d tarifas con importe distinto entre sentidos', count($discrepancias)));
            $io->listing($discrepancias);
            $io->warning('Se conservan las dos, cada una con su sentido en el nombre. Hay que decidirlas a mano: el comando no inventa precios.');
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }
    }

    /**
     * Los dos extremos de una ruta, normalizados, o null si el nombre no tiene forma de ruta.
     *
     * @return array{0: string, 1: string}|null
     */
    private function extremos(string $nombre): ?array
    {
        $limpio = trim(preg_replace('/^Transporte\s+/i', '', $nombre) ?? $nombre);
        $partes = preg_split('/\s+-\s+/', $limpio);

        if ($partes === false || count($partes) !== 2) {
            return null;
        }

        $normaliza = static fn (string $p): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $p) ?? $p));

        return [$normaliza($partes[0]), $normaliza($partes[1])];
    }

    private function nombreBidireccional(string $nombre): string
    {
        $limpio = trim(preg_replace('/^Transporte\s+/i', '', $nombre) ?? $nombre);
        $partes = preg_split('/\s+-\s+/', $limpio);

        if ($partes === false || count($partes) !== 2) {
            return $nombre;
        }

        return sprintf('Transporte %s ↔ %s', trim($partes[0]), trim($partes[1]));
    }

    /** «Cusco → Ollanta», leído del nombre original del componente antes de fusionarlo. */
    private function sentido(string $nombre): string
    {
        $limpio = trim(preg_replace('/^Transporte\s+/i', '', $nombre) ?? $nombre);
        $partes = preg_split('/\s+-\s+/', $limpio);

        if ($partes === false || count($partes) !== 2) {
            return $limpio;
        }

        return sprintf('%s → %s', trim($partes[0]), trim($partes[1]));
    }

    /** Dos tarifas son «la misma» si se llaman igual: es como las nombra el operador. */
    private function claveTarifa(TravelTarifa $t): string
    {
        return mb_strtolower(trim($t->getNombreInterno() ?? ''));
    }

    private function importe(TravelTarifa $t): string
    {
        return sprintf('%s %s', $t->getMonto() ?? '0.00', $t->getMoneda()?->getId() ?? '?');
    }

    /** ⚠️ Un nulo no es «menor»: es «sin medir», y pierde contra un número real. */
    private function capacidadMenor(?int $a, ?int $b): ?int
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return min($a, $b);
    }

    /**
     * Las líneas de cotización que citaban la tarifa que desaparece pasan a su gemela.
     *
     * Los snapshots (nombre, importe, moneda) NO se tocan: dicen lo que se vendió y son la
     * verdad de esa cotización. Lo único que se mueve es el puntero al maestro, que es lo que
     * permite volver a él.
     */
    private function reapuntarCotizaciones(TravelTarifa $muere, TravelTarifa $sobrevive): void
    {
        $viejo = (string) $muere->getId();
        $nuevo = (string) $sobrevive->getId();

        $citas = $this->em->getRepository(CotizacionCottarifa::class)
            ->findBy(['tarifaMaestraId' => $viejo]);

        foreach ($citas as $cita) {
            $cita->setTarifaMaestraId($nuevo);
        }
    }

    /** @return list<TravelSegmentoComponente> */
    private function relacionesConTarifa(TravelTarifa $tarifa): array
    {
        return $this->em->getRepository(TravelSegmentoComponente::class)
            ->findBy(['tarifaPredeterminada' => $tarifa]);
    }

    private function enlaces(TravelComponente $c): int
    {
        return count($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $c]));
    }
}
