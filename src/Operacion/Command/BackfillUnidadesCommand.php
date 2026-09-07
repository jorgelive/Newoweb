<?php

declare(strict_types=1);

namespace App\Operacion\Command;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Operacion\Entity\OperacionOrdenServicioItem;
use App\Operacion\Entity\OperacionServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rellena la unidad de conteo en lo que ya existía: filas de La Biblia y líneas de órdenes.
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * Las columnas nacen vacías, y vacías significan «número pelado» — que es exactamente como se
 * leían antes. Correcto para no cambiar nada de golpe, y falso a partir de mañana: un hotel de
 * cuatro noches seguiría diciendo «4» en un documento que ya sabe decir «4 noches».
 *
 * ⚠️ **También toca documentos YA EMITIDOS**, y eso normalmente no se hace: una orden dice lo que
 * decía el día que se mandó. Aquí se hace a conciencia y por decisión del operador —«prefiero
 * corregir esos detalles antes de que sean evidentes»—, porque lo que cambia **no contradice** lo
 * que se envió: donde ponía «4» pondrá «4 noches». No se toca ni una cifra, ni un importe, ni una
 * fecha: sólo se nombra la unidad que el número ya tenía.
 *
 * ── De dónde sale el dato ───────────────────────────────────────────────────
 * Del componente de la cotización, que es de donde salió la fila. Si el componente ya no existe
 * —cotización borrada, fila huérfana— se deja como estaba y se cuenta aparte.
 */
#[AsCommand(
    name: 'app:operacion:backfill-unidades',
    description: 'Rellena la unidad de conteo en las filas de La Biblia y en las líneas de órdenes ya emitidas.',
    hidden: true,
)]
final class BackfillUnidadesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'No guarda nada: enseña lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        /** @var list<OperacionServicio> $filas */
        $filas = $this->em->getRepository(OperacionServicio::class)->findAll();

        $tocadas = 0;
        $sinComponente = 0;
        $conFin = 0;
        /** @var array<string, \DateTimeImmutable|null> $finPorServicio */
        $finPorServicio = [];
        /** @var array<string, int> $porUnidad */
        $porUnidad = [];
        /** @var array<string, string|null> $sustantivoPorServicio  id de OperacionServicio → sustantivo */
        $sustantivoPorServicio = [];

        foreach ($filas as $fila) {
            $componente = $fila->getCotizacionComponente();

            if (!$componente instanceof CotizacionCotcomponente) {
                ++$sinComponente;
                continue;
            }

            $unidad = $componente->getUnidadDeConteo();
            $sustantivo = $componente->getSustantivoUnidad();

            // La fecha de fin se rellena SIEMPRE que exista, aunque la unidad no tenga nombre: un
            // traslado no dice «2 noches», pero saber que acaba al día siguiente sí importa.
            $id = (string) $fila->getId();
            $fin = $componente->getFechaHoraFin();

            if ($fin !== null) {
                $fila->setFechaFinServicio(\DateTimeImmutable::createFromInterface($fin)->setTime(0, 0));
                ++$conFin;

                // ⚠️ **Antes del `continue`, y esto se me pasó en la primera versión.** La fecha de
                // fin viaja al documento AUNQUE la unidad no tenga nombre: un traslado nocturno no
                // dice «2 noches», pero que acabe al día siguiente es justo lo que el conductor
                // necesita saber. Poblado después del `continue`, esas líneas se quedaban sin
                // salida y sólo lo delataba mirar la tabla en la base.
                if ($id !== '') {
                    $finPorServicio[$id] = $fila->getFechaFinServicio();
                }
            }

            // Sin unidad que nombrar no hay nada más que rellenar: el número pelado es lo correcto.
            if ($unidad === 'unidades') {
                continue;
            }

            $fila->setUnidadDeConteo($unidad);
            $fila->setSustantivoUnidad($sustantivo);

            if ($id !== '') {
                $sustantivoPorServicio[$id] = $sustantivo;
            }

            $porUnidad[$unidad] = ($porUnidad[$unidad] ?? 0) + 1;
            ++$tocadas;
        }

        /** @var list<OperacionOrdenServicioItem> $items */
        $items = $this->em->getRepository(OperacionOrdenServicioItem::class)->findAll();

        $itemsTocados = 0;
        foreach ($items as $item) {
            $clave = (string) $item->getOperacionServicioId();
            $sustantivo = $sustantivoPorServicio[$clave] ?? null;
            $fin = $finPorServicio[$clave] ?? null;

            if ($sustantivo === null && $fin === null) {
                continue;
            }

            if ($sustantivo !== null && $sustantivo !== '') {
                $item->setSustantivoUnidad($sustantivo);
            }

            if ($fin !== null) {
                $item->setFechaFin($fin);
            }

            ++$itemsTocados;
        }

        $io->table(
            ['Qué', 'Cuántas'],
            [
                ['Filas de La Biblia revisadas', (string) count($filas)],
                ['  con unidad que nombrar', (string) $tocadas],
                ['  sin componente vivo (se dejan)', (string) $sinComponente],
                ['  con fecha de fin', (string) $conFin],
                ['Líneas de órdenes ya emitidas', (string) $itemsTocados],
            ]
        );

        foreach ($porUnidad as $unidad => $n) {
            $io->writeln(sprintf('  %s: <info>%d</info>', $unidad, $n));
        }

        if ($seco) {
            $io->warning('Ensayo: no se guardó nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Unidades rellenadas.');

        return Command::SUCCESS;
    }
}
