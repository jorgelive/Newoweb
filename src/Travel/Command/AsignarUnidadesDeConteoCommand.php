<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Travel\Entity\TravelComponente;
use App\Travel\Enum\MomentoDelDiaEnum;
use App\Travel\Enum\UnidadDeConteoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Declara en qué se cuenta un producto del catálogo y lo propaga a los expedientes.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Un hotel del 18 al 22 son 4 noches; un seguro del 18 al 22 son 5 días. Hasta el 07/09/2026 el
 * editor sólo sabía restar, así que para cobrar los 5 días de estancia en Punta Cana **hubo que
 * escribir el seguro como «18 → 23»**: torcer la fecha era la única forma de que la resta diera 5.
 *
 * Con la unidad declarada, la cantidad sale sola de las fechas correctas — pero **las fechas ya
 * torcidas siguen torcidas**, y ahí es donde entra este comando. Si se despliega el intervalo
 * cerrado sin corregirlas, el seguro gana un día fantasma en la app del huésped.
 *
 * ── Por qué comando y no migración ──────────────────────────────────────────
 * Toca entidades con listeners detrás (coherencia, traducción) y escribe en la cotización, que es
 * el sitio donde un `UPDATE` a pelo se salta lo que mantiene el expediente coherente. Ver
 * `CLAUDE.md` §«Qué entra por migración y qué tiene que entrar por comando».
 *
 * ── Qué hace y qué NO ───────────────────────────────────────────────────────
 * - Declara unidad, sustantivo y momento en el **componente maestro**.
 * - Congela esos tres en los **snapshots** de los componentes ya cotizados que cuelgan de él.
 * - **Denuncia** las fechas que no cuadran con la cantidad, una por una y con su expediente.
 * - Sólo con `--ajustar-fin` las corrige, y **manda la cantidad**: la fecha se recalcula como
 *   `inicio + cantidad − 1`.
 *
 * ⚠️ **Ajustar el fin NO es siempre lo correcto, y por eso no es el modo por defecto.** En el
 * expediente 5SRAJV la operativa tiene la fecha torcida (18→23) y la cantidad buena (5): ahí
 * ajustar acierta. La confirmada tiene la fecha buena (18→22) y la cantidad corta (4): ahí
 * ajustar **encogería la cobertura** en vez de arreglar el precio, que es una decisión comercial
 * —lo vendido es lo vendido— y no la toma un comando.
 *
 * Léase el informe antes de ajustar nada.
 */
#[AsCommand(
    name: 'app:travel:asignar-unidades-de-conteo',
    description: 'Declara la unidad de conteo de un componente maestro y la propaga a los expedientes.',
)]
final class AsignarUnidadesDeConteoCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('componente', null, InputOption::VALUE_REQUIRED, 'Nombre interno del componente maestro (búsqueda parcial).')
            ->addOption('unidad', null, InputOption::VALUE_REQUIRED, 'noches | dias | unidades')
            ->addOption('sustantivo', null, InputOption::VALUE_REQUIRED, 'Cómo se llama en singular: «día», «desayuno».')
            ->addOption('momento', null, InputOption::VALUE_REQUIRED, 'Dónde se lee sin reloj: abre | manana | media_manana | mediodia | tarde | noche | cierra')
            ->addOption('ajustar-fin', null, InputOption::VALUE_NONE, 'Recalcula fechaHoraFin como inicio + cantidad − 1. LEE EL INFORME ANTES.')
            ->addOption('estado', null, InputOption::VALUE_REQUIRED, 'Limita el ajuste de fechas a las cotizaciones en ese estado (operativa, confirmado…).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No guarda nada: enseña lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $ajustar = (bool) $input->getOption('ajustar-fin');
        // ⚠️ El filtro existe porque el ajuste NO es correcto en todas las filas a la vez. En el
        // 5SRAJV la operativa tiene la fecha torcida y la cantidad buena —ajustar acierta— y la
        // confirmada tiene la fecha buena y la cantidad corta, donde ajustar encogería la
        // cobertura. Sin poder acotar, la única salida era tocar la base a mano, que es justo lo
        // que este comando existe para evitar.
        $estadoFiltro = (string) ($input->getOption('estado') ?? '');

        $busqueda = (string) ($input->getOption('componente') ?? '');
        if ($busqueda === '') {
            $io->error('Falta --componente: el nombre interno del componente maestro.');

            return Command::INVALID;
        }

        $unidad = UnidadDeConteoEnum::tryFrom((string) ($input->getOption('unidad') ?? ''));
        if ($unidad === null) {
            $io->error('--unidad tiene que ser noches, dias o unidades.');

            return Command::INVALID;
        }

        $momentoTexto = (string) ($input->getOption('momento') ?? '');
        $momento = $momentoTexto === '' ? null : MomentoDelDiaEnum::tryFrom($momentoTexto);
        if ($momentoTexto !== '' && $momento === null) {
            $io->error(sprintf('--momento «%s» no existe.', $momentoTexto));

            return Command::INVALID;
        }

        $sustantivo = (string) ($input->getOption('sustantivo') ?? '');

        /** @var list<TravelComponente> $maestros */
        $maestros = $this->em->createQuery(
            'SELECT c FROM App\Travel\Entity\TravelComponente c WHERE c.nombreInterno LIKE :q'
        )->setParameter('q', '%' . $busqueda . '%')->getResult();

        if ($maestros === []) {
            $io->error(sprintf('Ningún componente maestro con «%s» en el nombre interno.', $busqueda));

            return Command::FAILURE;
        }

        $io->section(sprintf('Componentes maestros (%d)', count($maestros)));

        foreach ($maestros as $maestro) {
            $io->writeln(sprintf(
                '  %s  <info>%s</info> → unidad <comment>%s</comment>%s%s',
                $maestro->getId()?->toRfc4122() ?? '—',
                (string) $maestro->getNombreInterno(),
                $unidad->value,
                $sustantivo !== '' ? sprintf(', sustantivo «%s»', $sustantivo) : '',
                $momento !== null ? sprintf(', momento «%s»', $momento->value) : '',
            ));

            $maestro->setUnidadDeConteo($unidad);
            if ($sustantivo !== '') {
                $maestro->setSustantivoUnidad($sustantivo);
            }
            if ($momento !== null) {
                $maestro->setMomentoDelDia($momento);
            }
        }

        $idsMaestros = array_values(array_filter(array_map(
            static fn (TravelComponente $c): ?string => $c->getId()?->toRfc4122(),
            $maestros
        )));

        /** @var list<CotizacionCotcomponente> $cotizados */
        $cotizados = $this->em->createQuery(
            'SELECT k FROM App\Cotizacion\Entity\CotizacionCotcomponente k WHERE k.componenteMaestroId IN (:ids)'
        )->setParameter('ids', $idsMaestros)->getResult();

        $io->section(sprintf('Componentes ya cotizados que cuelgan de ellos (%d)', count($cotizados)));

        $filas = [];
        $ajustados = 0;

        foreach ($cotizados as $k) {
            $k->setUnidadDeConteoSnapshot($unidad);
            if ($sustantivo !== '') {
                $k->setSustantivoUnidadSnapshot($sustantivo);
            }
            if ($momento !== null) {
                $k->setMomentoDelDiaSnapshot($momento);
            }

            $inicio = $k->getFechaHoraInicio();
            $fin = $k->getFechaHoraFin();
            $cantidad = max(1, $k->getCantidad());

            if ($inicio === null || $fin === null || !$unidad->seDerivaDeLasFechas()) {
                continue;
            }

            // El intervalo que DEBERÍAN decir las fechas para esa cantidad.
            $finEsperado = (clone $inicio)->modify(sprintf('+%d days', $cantidad - ($unidad->sumaElUltimoDia() ? 1 : 0)));
            $cuadra = $fin->format('Y-m-d') === $finEsperado->format('Y-m-d');

            $expediente = $k->getCotservicio()?->getCotizacion();
            $estado = (string) ($expediente?->getEstado()->value ?? '');
            $enAlcance = $estadoFiltro === '' || $estado === $estadoFiltro;

            $filas[] = [
                $expediente?->getFile()?->getLocalizador() ?? '—',
                $estado === '' ? '—' : $estado,
                $inicio->format('d/m'),
                $fin->format('d/m'),
                (string) $cantidad,
                $finEsperado->format('d/m'),
                $cuadra ? '✅' : (($ajustar && $enAlcance) ? '🔧 ajustado' : '❌ NO cuadra'),
            ];

            if (!$cuadra && $ajustar && $enAlcance) {
                $k->setFechaHoraFin($finEsperado);
                ++$ajustados;
            }
        }

        if ($filas !== []) {
            $io->table(['Expediente', 'Estado', 'Inicio', 'Fin', 'Cantidad', 'Fin esperado', ''], $filas);
        }

        if ($seco) {
            $io->warning('Ensayo: no se guardó nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();

        $io->success(sprintf(
            '%d maestro(s) y %d componente(s) cotizado(s) actualizados. Fechas ajustadas: %d.',
            count($maestros),
            count($cotizados),
            $ajustados,
        ));

        if (!$ajustar && array_filter($filas, static fn (array $f): bool => str_contains($f[6], 'NO cuadra')) !== []) {
            $io->note('Hay fechas que no cuadran con su cantidad. Míralas una a una: ajustar hace mandar a la CANTIDAD, y eso no siempre es lo correcto.');
        }

        return Command::SUCCESS;
    }
}
