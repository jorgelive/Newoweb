<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\GrupoTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pone en cada componente la hora REAL del vuelo de su subgrupo.
 *
 * ── El problema que resuelve ────────────────────────────────────────────────
 * Un componente de vuelo nace con la hora de la plantilla —a menudo `08:00` de relleno, la misma
 * en inicio y fin—. La hora de verdad está en `CotizacionVuelo`, y **cuál de ellas** depende del
 * subgrupo: el 17 de setiembre hay tres vuelos Cusco→Lima, a las 06:50, 07:15 y 20:05, cada uno
 * con su gente. Sin el subgrupo no hay forma de elegir, y con él la respuesta es única.
 *
 * ```
 * componente ──grupo──> CotizacionFileGrupo ──vuelos──> CotizacionVuelo (salida, llegada)
 * ```
 *
 * ── ⚠️ Por comando ORM y no por SQL ─────────────────────────────────────────
 * Cambiar una hora dispara los listeners de coherencia y recalcula lo que cuelga de ella. Un
 * `UPDATE` directo se los salta y deja el cuadro de operación diciendo una hora y el itinerario
 * otra. Es la regla de `CLAUDE.md`: lo que pasa por listeners entra por el ORM.
 *
 * ── Qué NO hace, a propósito ────────────────────────────────────────────────
 * **No adivina el subgrupo.** Si un componente no lo tiene, se salta y se dice: elegir por fecha y
 * ruta acertaría con los servicios de un solo vuelo y fallaría justo en los repartidos, que son
 * los que se están arreglando. Un acierto silencioso en el caso fácil no compensa un error mudo en
 * el difícil.
 *
 * ⚠️ **Elige el vuelo por la FECHA del componente.** Un subgrupo es una reserva —un localizador—
 * y cubre el viaje entero: medido sobre datos reales, los 24 subgrupos aéreos del colegio tienen 2
 * vuelos (ida y vuelta) o 4 (con escala). Ninguno tiene uno solo, así que una regla de «el único
 * vuelo del grupo» no se dispararía nunca.
 *
 * ⚠️ Y varios vuelos **el mismo día** son una **escala**, no una ambigüedad: el componente sale
 * con el primero y llega con el último.
 */
#[AsCommand(
    name: 'app:cotizacion:horas-desde-vuelos',
    description: 'Pone en cada componente la hora real del vuelo de su subgrupo.',
)]
final class CotizacionHorasDesdeVuelosCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('localizador', InputArgument::REQUIRED, 'Localizador del expediente, p. ej. 5SRAJV')
            ->addOption('propuesta', null, InputOption::VALUE_REQUIRED, 'Sólo esta propuesta')
            ->addOption('estado', null, InputOption::VALUE_REQUIRED, 'Sólo cotizaciones en este estado, p. ej. operativa')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría y no guarda nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ensayo = (bool) $input->getOption('dry-run');
        $localizador = (string) $input->getArgument('localizador');

        $file = $this->em->getRepository(CotizacionFile::class)->findOneBy(['localizador' => $localizador]);

        if ($file === null) {
            $io->error(sprintf('No existe el expediente «%s».', $localizador));

            return Command::FAILURE;
        }

        $criterio = ['file' => $file];

        if ($input->getOption('propuesta') !== null) {
            $criterio['propuesta'] = (int) $input->getOption('propuesta');
        }

        if ($input->getOption('estado') !== null) {
            $criterio['estado'] = (string) $input->getOption('estado');
        }

        /** @var list<Cotizacion> $cotizaciones */
        $cotizaciones = $this->em->getRepository(Cotizacion::class)->findBy($criterio);

        $filas = [];
        $cambiados = 0;
        $sinGrupo = 0;
        $ambiguos = 0;

        foreach ($cotizaciones as $cotizacion) {
            foreach ($cotizacion->getCotservicios() as $servicio) {
                foreach ($servicio->getCotcomponentes() as $componente) {
                    $resultado = $this->resolver($componente);

                    if ($resultado === null) {
                        ++$sinGrupo;
                        continue;
                    }

                    if ($resultado === false) {
                        ++$ambiguos;
                        continue;
                    }

                    [$salida, $llegada, $etiqueta] = $resultado;

                    $antes = sprintf(
                        '%s → %s',
                        $componente->getFechaHoraInicio()?->format('d-M H:i') ?? '—',
                        $componente->getFechaHoraFin()?->format('d-M H:i') ?? '—',
                    );
                    $despues = sprintf('%s → %s', $salida->format('d-M H:i'), $llegada->format('d-M H:i'));

                    if ($antes === $despues) {
                        continue;   // idempotente: ya está puesta
                    }

                    $filas[] = [
                        $cotizacion->getPropuesta() . ' ' . $cotizacion->getEstado()->value,
                        mb_substr((string) $componente->getNombreInternoSnapshot(), 0, 28),
                        $etiqueta,
                        $antes,
                        $despues,
                    ];

                    if (!$ensayo) {
                        $componente->setFechaHoraInicio($salida);
                        $componente->setFechaHoraFin($llegada);
                    }

                    ++$cambiados;
                }
            }
        }

        if ($filas !== []) {
            $io->table(['Propuesta', 'Componente', 'Vuelo', 'Antes', 'Después'], $filas);
        }

        $io->writeln(sprintf(
            '  %d por cambiar · %d sin subgrupo aéreo (se saltan) · %d sin vuelo en su fecha (se saltan)',
            $cambiados,
            $sinGrupo,
            $ambiguos,
        ));

        if ($ensayo) {
            $io->note('Ensayo: no se ha guardado nada. Quita --dry-run para aplicarlo.');

            return Command::SUCCESS;
        }

        if ($cambiados > 0) {
            $this->em->flush();
        }

        $io->success(sprintf('%d componentes con su hora real.', $cambiados));

        return Command::SUCCESS;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}|false|null
     *         Las horas y la etiqueta del vuelo; `false` si no se puede decidir; `null` si el
     *         componente no tiene subgrupo aéreo.
     */
    private function resolver(CotizacionCotcomponente $componente): array|false|null
    {
        $grupo = $componente->getGrupo();

        if ($grupo === null || $grupo->getTipo() !== GrupoTipoEnum::RESERVA_AEREA) {
            return null;
        }

        // ⚠️ **Se elige por FECHA, no por «el único vuelo del grupo».**
        //
        // La primera versión sólo actuaba si el subgrupo tenía un vuelo, y medido sobre datos
        // reales eso **no pasa nunca**: los 24 subgrupos aéreos del colegio tienen 2 vuelos (ida y
        // vuelta) o 4 (con escala). Un subgrupo es una RESERVA —un localizador— y una reserva
        // cubre el viaje entero; el componente es un tramo.
        //
        // Lo que distingue el tramo es la fecha del propio componente, que ya está puesta aunque
        // la hora sea de relleno.
        $dia = ($componente->getFechaHoraInicio() ?? $componente->getCotsegmento()?->getFechaAbsoluta())?->format('Y-m-d');

        if ($dia === null) {
            return false;
        }

        $delDia = [];

        foreach ($grupo->getVuelos() as $vuelo) {
            $salida = $vuelo->getSalida();

            if ($salida !== null && $vuelo->getLlegada() !== null && $salida->format('Y-m-d') === $dia) {
                $delDia[] = $vuelo;
            }
        }

        if ($delDia === []) {
            return false;
        }

        // ⚠️ **Varios el mismo día son una ESCALA, no una ambigüedad.** «Vuelo de Lima a Punta
        // Cana» vía Panamá son dos filas —LIM→PTY y PTY→PUJ— y el componente es el viaje entero:
        // sale con el primero y llega con el último. Tratarlo como ambiguo dejaría sin hora justo
        // los vuelos largos, que son los que más falta hacen en una orden.
        usort($delDia, static fn ($a, $b): int => $a->getSalida() <=> $b->getSalida());

        $primero = $delDia[0];
        $ultimo = $delDia[count($delDia) - 1];

        $etiqueta = count($delDia) === 1
            ? trim(($primero->getNumero() ?? '') . ' ' . ($primero->getOrigen() ?? '') . '→' . ($primero->getDestino() ?? ''))
            : sprintf('%s→%s (%d tramos)', $primero->getOrigen() ?? '?', $ultimo->getDestino() ?? '?', count($delDia));

        return [$primero->getSalida(), $ultimo->getLlegada(), $etiqueta];
    }
}
