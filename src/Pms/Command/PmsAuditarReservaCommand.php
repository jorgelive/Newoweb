<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Radiografía de una reserva: estancias, extensiones, cargos, pagos y descuadres.
 *
 * Nace de perseguir a mano, con scripts de usar y tirar, una reserva que mostraba
 * dos veces la misma casita. Lo que hay que mirar en esos casos siempre es lo
 * mismo, y en producción no hay más herramienta que la consola — de ahí el
 * comando.
 *
 * Es **solo lectura**: ni escribe ni recalcula nada.
 *
 *   php bin/console pms:reserva:auditar XTHRMQ
 */
#[AsCommand(
    name: 'pms:reserva:auditar',
    description: 'Muestra estancias, extensiones, cargos y pagos de una reserva, y señala descuadres.',
)]
final class PmsAuditarReservaCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('localizador', InputArgument::REQUIRED, 'Localizador de la reserva (ej. XTHRMQ).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $localizador = (string) $input->getArgument('localizador');

        $reserva = $this->em->getRepository(PmsReserva::class)->findOneBy(['localizador' => $localizador]);
        if (!$reserva instanceof PmsReserva) {
            $io->error(sprintf('No existe la reserva %s.', $localizador));

            return Command::FAILURE;
        }

        $io->title(sprintf('Reserva %s — %s', $localizador, (string) $reserva->getNombreApellido()));

        $avisos = [];

        // ── ESTANCIAS Y EXTENSIONES ─────────────────────────────────────────
        $filas = [];
        $estancias = [];
        foreach ($reserva->getEventosCalendario() as $evento) {
            $esExt = $evento->esExtension();
            $clave = ($evento->getPmsUnidad()?->getNombre() ?? '?')
                . '|' . $evento->getInicio()?->format('Y-m-d')
                . '|' . $evento->getFin()?->format('Y-m-d');

            if (!$esExt) {
                $estancias[$clave] = ($estancias[$clave] ?? 0) + 1;
            }

            $filas[] = [
                $esExt ? '  └ extensión' : 'estancia',
                substr((string) $evento->getId(), 0, 8),
                $evento->getPmsUnidad()?->getNombre() ?? '—',
                $evento->getInicio()?->format('d/m H:i') . ' → ' . $evento->getFin()?->format('d/m H:i'),
                (string) $evento->getEstado()?->getId(),
                (string) $evento->getEstadoPago()?->getId(),
                ($evento->isEntradaTemprana() ? 'ET ' : '') . ($evento->isSalidaTardia() ? 'ST' : ''),
            ];

            // Una extensión de una estancia cancelada no debería seguir activa.
            if ($esExt
                && $evento->getEstado()?->getId() === 'extension'
                && $evento->getEventoOrigen()?->getEstado()?->getId() === 'cancelada'
            ) {
                $avisos[] = sprintf('La extensión %s sigue activa pero su estancia está cancelada.', substr((string) $evento->getId(), 0, 8));
            }

            if ($esExt && $evento->getEstadoPago()?->getId() !== 'no-pagado') {
                $avisos[] = sprintf('La extensión %s tiene estado de pago «%s»: debería ser «no-pagado».', substr((string) $evento->getId(), 0, 8), (string) $evento->getEstadoPago()?->getId());
            }
        }

        $io->table(['Tipo', 'Id', 'Casita', 'Fechas', 'Estado', 'Pago', 'Horario'], $filas);

        foreach ($estancias as $clave => $veces) {
            if ($veces > 1) {
                $avisos[] = sprintf('%d estancias con la MISMA casita y fechas (%s): el panel las muestra como grupos idénticos.', $veces, str_replace('|', ' ', $clave));
            }
        }

        // ── FINANZAS ────────────────────────────────────────────────────────
        $info = $this->em->getRepository(PmsInformacionFinanciera::class)->findOneBy(['reserva' => $reserva]);
        if (!$info instanceof PmsInformacionFinanciera) {
            $io->warning('La reserva no tiene cabecera financiera.');

            return Command::SUCCESS;
        }

        $base = $info->getMoneda()?->getId() ?? '—';
        $io->section(sprintf('Finanzas (base %s)', $base));

        $filasCargos = [];
        foreach ($info->getCargos() as $cargo) {
            $evento = $cargo->getEvento();
            $estado = $evento?->getEstado()?->getId() ?? 'sin estancia';

            $filasCargos[] = [
                substr((string) $cargo->getDescripcion(), 0, 46),
                $cargo->getMoneda()?->getId() ?? '—',
                $cargo->getTotalLinea() ?? $cargo->getMonto() ?? '—',
                $cargo->getTipoCambio() ?? '—',
                $estado,
            ];

            if ($estado === 'cancelada') {
                $avisos[] = sprintf('El cargo «%s» cuelga de una estancia CANCELADA y sigue sumando al total.', substr((string) $cargo->getDescripcion(), 0, 40));
            }

            if ($cargo->getTipoCambio() === null && $cargo->getMoneda()?->getId() !== $base) {
                $avisos[] = sprintf('El cargo «%s» está en %s sin tipo de cambio: aporta 0 al total.', substr((string) $cargo->getDescripcion(), 0, 40), (string) $cargo->getMoneda()?->getId());
            }
        }
        $io->table(['Cargo', 'Mon.', 'Importe', 'TC', 'Estancia'], $filasCargos);

        $filasPagos = [];
        foreach ($info->getPagos() as $pago) {
            $filasPagos[] = [
                $pago->getFechaPago()?->format('d/m/Y') ?? '—',
                $pago->getMoneda()?->getId() ?? '—',
                $pago->getMonto(),
                $pago->getTipoCambio() ?? '—',
                (string) $pago->getMedioPago(),
                $pago->isEsAutomatico() ? 'auto' : '',
            ];

            if ($pago->getTipoCambio() === null && $pago->getMoneda()?->getId() !== $base) {
                $avisos[] = sprintf('El pago de %s %s no tiene tipo de cambio: aporta 0 al saldo.', (string) $pago->getMoneda()?->getId(), $pago->getMonto());
            }
        }
        $io->table(['Fecha', 'Mon.', 'Importe', 'TC', 'Medio', ''], $filasPagos);

        $saldo = (float) $info->getTotalCargos() - (float) $info->getTotalPagos();
        $io->writeln(sprintf(
            '  <info>cargos</info> %s   <info>pagos</info> %s   <info>saldo</info> %s %s',
            $info->getTotalCargos(),
            $info->getTotalPagos(),
            number_format($saldo, 2, '.', ''),
            $base
        ));

        if ($saldo < 0) {
            $avisos[] = sprintf('Saldo NEGATIVO (%s %s): hay más cobrado que facturado.', number_format($saldo, 2, '.', ''), $base);
        }

        if ($avisos === []) {
            $io->success('Sin inconsistencias.');

            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d posibles inconsistencias:', count($avisos)));
        $io->listing(array_unique($avisos));

        return Command::SUCCESS;
    }
}
