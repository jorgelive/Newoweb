<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsTipoCargo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reengancha a la estancia VIVA los cargos que quedaron colgados de una cancelada.
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * Desde el 08/09/2026 un cargo de una estancia cancelada **no cuenta** (ver
 * {@see \App\Pms\Service\Finance\PmsTotalesPorMoneda::cargoCuenta()}). La regla es correcta, pero
 * saca a la luz las fichas donde el dinero se quedó pegado al tramo muerto: el huésped movió
 * fechas o casita, el cargo original no se movió con él, y la ficha parecía saldada porque el
 * importe del tramo cancelado casualmente cuadraba con lo pagado.
 *
 * ⚠️ **Va por ORM y no por SQL**, aunque sea un `UPDATE` de una columna: los listeners de
 * coherencia financiera recalculan los totales por moneda al guardar. Un `UPDATE` directo dejaría
 * `pms_finanzas_total_moneda` diciendo lo de antes — y ese desajuste no da error, sólo un panel
 * que miente.
 *
 * ⚠️ **Sólo mueve cuando hay UNA estancia viva.** Con dos, quién se queda el cargo es una decisión
 * de negocio y la toma una persona mirando las fechas, no un comando adivinando.
 */
#[AsCommand(
    name: 'app:pms:mover-cargos-de-cancelada',
    description: 'Reengancha a la estancia viva los cargos colgados de una estancia cancelada.',
)]
final class PmsMoverCargosDeEstanciaCanceladaCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'reserva',
            InputArgument::REQUIRED,
            'El localizador que se ve en el panel (p. ej. 5509354785), o el id interno de Beds24',
        );
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'No guarda: enseña lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $buscado = trim((string) $input->getArgument('reserva'));

        $reserva = $this->buscarReserva($buscado);

        if ($reserva === null) {
            $io->error(sprintf('No encuentro ninguna reserva con «%s».', $buscado));

            return Command::FAILURE;
        }

        $io->writeln(sprintf(
            '<info>%s</info> · %s · %s',
            (string) $reserva->getReferenciaCanalAggregate(),
            (string) $reserva->getUnidadesAggregate(),
            $reserva->getFechaLlegada()?->format('d/m/Y') ?? '—',
        ));

        $vivas = [];

        foreach ($reserva->getEventosCalendario() as $evento) {
            $estado = $evento->getEstado()?->getId();

            // ⚠️ **`extension` y `bloqueo` NO son estancias**: son la salida tardía y el bloqueo de
            // calendario. Contarlas hacía que una reserva de una sola casita con salida tardía
            // dijera «hay 2 estancias vivas» y el comando se negara justo cuando servía — o peor,
            // que los cargos acabaran en la noche fantasma si el tramo real estaba cancelado.
            if (in_array($estado, [
                PmsEventoEstado::CODIGO_CANCELADA,
                PmsEventoEstado::CODIGO_EXTENSION,
                PmsEventoEstado::CODIGO_BLOQUEO,
            ], true)) {
                continue;
            }

            $vivas[] = $evento;
        }

        if (count($vivas) !== 1) {
            $io->error(sprintf(
                'Hay %d estancias vivas. Con una sola se sabe a dónde va el cargo; con más, lo decide una persona.',
                count($vivas),
            ));

            return Command::FAILURE;
        }

        $destino = $vivas[0];
        $filas = [];
        $movidos = 0;

        $info = $reserva->getInformacionFinanciera();

        if ($info !== null) {
            foreach ($info->getCargos() as $cargo) {
                if ($cargo->getEvento()?->getEstado()?->getId() !== PmsEventoEstado::CODIGO_CANCELADA) {
                    continue;
                }

                // ⚠️ **La penalización se queda.** Es el «Cancel Fee» que manda el canal por haber
                // cancelado: moverlo a la estancia nueva se lo cobraría al huésped que sí viene,
                // por algo que ya no pasó. De 24 medidas, 2 traen importe — bastan para hacer daño.
                if ($cargo->getTipoCargo() === PmsTipoCargo::PENALIZACION) {
                    continue;
                }

                $filas[] = [
                    $this->etiqueta($cargo),
                    $cargo->getMoneda()?->getId() ?? '—',
                    $cargo->getTotalLinea() ?? $cargo->getMonto() ?? '0.00',
                    $this->nombreDe($cargo->getEvento()),
                    $this->nombreDe($destino),
                ];

                if (!$seco) {
                    $cargo->setEvento($destino);
                    // 🔥 **Sin esto la mudanza no dura.** El persister reimputa en cada sync y le
                    // pisa los importes con lo que mande el canal —que en lo cancelado es 0.00 la
                    // mitad de las veces—. El panel ya lo hacía; este comando se escribió antes de
                    // descubrirlo y se quedó atrás.
                    $cargo->setFijadoPorOperador(true);
                }

                ++$movidos;
            }
        }

        if ($filas === []) {
            $io->success('No hay cargos colgados de una estancia cancelada.');

            return Command::SUCCESS;
        }

        $io->table(['Cargo', 'Moneda', 'Importe', 'Desde', 'Hacia'], $filas);

        if ($seco) {
            $io->warning(sprintf('Ensayo: se moverían %d.', $movidos));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d cargo(s) movido(s). Los totales se recalculan solos al guardar.', $movidos));

        return Command::SUCCESS;
    }

    /**
     * Por el localizador que el operador VE, y sólo después por el id interno.
     *
     * ⚠️ **El panel no enseña `beds24MasterId` en ningún sitio.** Enseña la referencia del canal
     * —«5509354785»—, que es la que el huésped también tiene y por la que se habla de una reserva.
     * Pedir el id interno obliga a una consulta a la base para usar un comando, que es exactamente
     * la fricción que hace que un comando no se use.
     *
     * `LIKE` porque el campo es un AGREGADO: una reserva con dos estancias de canales distintos
     * lleva las dos referencias separadas por `|`.
     */
    private function buscarReserva(string $buscado): ?PmsReserva
    {
        $repo = $this->em->getRepository(PmsReserva::class);

        /** @var list<PmsReserva> $porReferencia */
        $porReferencia = $repo->createQueryBuilder('r')
            ->where('r.referenciaCanalAggregate LIKE :ref')
            ->setParameter('ref', '%' . $buscado . '%')
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        if (count($porReferencia) === 1) {
            return $porReferencia[0];
        }

        if (count($porReferencia) > 1) {
            return null;
        }

        return ctype_digit($buscado)
            ? $repo->findOneBy(['beds24MasterId' => (int) $buscado])
            : null;
    }

    private function etiqueta(PmsCargoFinanciero $cargo): string
    {
        return $cargo->getDescripcion() ?? $cargo->getTipoCargo()->value ?? '(sin descripción)';
    }

    private function nombreDe(?PmsEventoCalendario $evento): string
    {
        if ($evento === null) {
            return '(nivel reserva)';
        }

        return sprintf(
            '%s %s→%s',
            (string) $evento->getPmsUnidad()?->getNombre(),
            $evento->getInicio()?->format('d/m') ?? '?',
            $evento->getFin()?->format('d/m') ?? '?',
        );
    }
}
