<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Service\Finance\TipoCambioDelDia;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rellena el tipo de cambio de los cargos y pagos que se guardaron sin él.
 *
 * Un registro sin TC aporta **0** al total cuando su moneda no es la de la
 * cabecera (§12.2), y eso pasa en bloque el día que se cambia la moneda base de
 * una reserva: todo lo que se registró «en la moneda de casa» se queda cojo de
 * golpe. Este comando los repara con la cotización **de su propia fecha**, no la
 * de hoy — el TC es la foto del día en que se movió el dinero.
 *
 * Se puede ejecutar tantas veces como haga falta: sólo toca los que están
 * vacíos. El listener de coherencia lo permite (`null → X` es la reparación) y
 * bloquea, como siempre, cambiar uno ya puesto.
 *
 * Arranca en SIMULACIÓN. Para escribir de verdad, `--aplicar`.
 */
#[AsCommand(
    name: 'pms:finanzas:completar-tipo-cambio',
    description: 'Rellena el tipo de cambio de cargos y pagos que se guardaron sin él.',
)]
final class PmsCompletarTipoCambioCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TipoCambioDelDia $tipoCambio,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('aplicar', null, InputOption::VALUE_NONE, 'Escribe los cambios (sin esto solo simula).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $aplicar = (bool) $input->getOption('aplicar');

        $io->title($aplicar ? 'Completando tipos de cambio' : 'Simulación (usa --aplicar para escribir)');

        // Cache por día: varias filas del mismo día no repiten la consulta.
        $porDia = [];
        $resolver = function (?DateTimeInterface $fecha) use (&$porDia): ?string {
            $clave = $fecha?->format('Y-m-d') ?? 'hoy';
            if (!array_key_exists($clave, $porDia)) {
                $porDia[$clave] = $this->tipoCambio->venta($fecha);
            }

            return $porDia[$clave];
        };

        $filas = [];
        $completados = 0;
        $sinCotizacion = 0;

        /** @var PmsCargoFinanciero[] $cargos */
        $cargos = $this->em->getRepository(PmsCargoFinanciero::class)->findBy(['tipoCambio' => null]);
        foreach ($cargos as $cargo) {
            // El cargo no tiene fecha propia: la de registro es lo más cercano al
            // día en que se pactó el importe.
            $fecha = $cargo->getCreatedAt();
            $tc = $resolver($fecha);

            $filas[] = [
                'cargo',
                substr((string) $cargo->getId(), 0, 8),
                $fecha?->format('Y-m-d') ?? '—',
                $cargo->getMoneda()?->getId() ?? '—',
                $cargo->getTotalLinea() ?? $cargo->getMonto() ?? '—',
                $tc ?? '(sin cotización)',
            ];

            if ($tc === null) {
                $sinCotizacion++;
                continue;
            }

            $cargo->setTipoCambio($tc);
            $completados++;
        }

        /** @var PmsPagoFinanciero[] $pagos */
        $pagos = $this->em->getRepository(PmsPagoFinanciero::class)->findBy(['tipoCambio' => null]);
        foreach ($pagos as $pago) {
            $fecha = $pago->getFechaPago();
            $tc = $resolver($fecha);

            $filas[] = [
                'pago',
                substr((string) $pago->getId(), 0, 8),
                $fecha?->format('Y-m-d') ?? '—',
                $pago->getMoneda()?->getId() ?? '—',
                $pago->getMonto(),
                $tc ?? '(sin cotización)',
            ];

            if ($tc === null) {
                $sinCotizacion++;
                continue;
            }

            $pago->setTipoCambio($tc);
            $completados++;
        }

        if ($filas === []) {
            $io->success('No hay cargos ni pagos sin tipo de cambio.');

            return Command::SUCCESS;
        }

        $io->table(['Tipo', 'Id', 'Fecha', 'Moneda', 'Importe', 'TC a aplicar'], $filas);

        if (!$aplicar) {
            // Sin flush no se escribe nada, pero las entidades quedan modificadas en
            // memoria: se limpia para que ningún otro proceso del mismo request las
            // arrastre por error.
            $this->em->clear();
            $io->note(sprintf('Simulación: %d se completarían, %d sin cotización disponible.', $completados, $sinCotizacion));

            return Command::SUCCESS;
        }

        // El flush dispara el listener de coherencia, que recalcula las cabeceras
        // afectadas: los totales quedan al día sin tocar nada más.
        $this->em->flush();

        $io->success(sprintf('%d registros completados. %d sin cotización disponible.', $completados, $sinCotizacion));

        return Command::SUCCESS;
    }
}
