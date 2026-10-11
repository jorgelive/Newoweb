<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Finanzas\Enum\FinOrigenCobro;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Finanzas\PmsPrepagoEnlaceService;
use App\Pms\Service\Message\SaldoPendiente;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Emite el enlace del SALDO media hora después de que se pague el adelanto, y se lo avisa al
 * huésped (10/10/2026).
 *
 * ### Por qué un barrido y no un mensaje programado
 *
 * La regla vive en el emisor —`PmsPrepagoEnlaceService::puedePedirseElSaldo()` abre la puerta
 * cuando el adelanto se pagó por enlace hace `MINUTOS_SALDO_TRAS_ADELANTO`—, pero el emisor se
 * dispara por MOVIMIENTO, y pasada la media hora puede que nada mueva la reserva. Un mensaje
 * diferido a los 30 minutos también lo resolvería, pero si se pierde —un worker caído, un
 * despliegue— no lo recoge nadie. El barrido sí: corre cada 5 minutos, pregunta al mismo emisor
 * y es idempotente, así que repetirlo no duplica nada.
 *
 * ### La detección
 *
 * Un enlace AUTOMÁTICO en `pagado` con `pagado_en` de hace media hora o más. Ese estado sólo lo
 * escribe `FinEnlacePagoService::confirmarPago()` con el cargo confirmado por la pasarela: el
 * navegador no puede marcarlo.
 *
 * ### El aviso, sólo a los recientes
 *
 * El enlace se emite para cualquier reserva que aún no haya llegado; el aviso al huésped sólo si
 * el adelanto se pagó en las últimas `HORAS_DE_AVISO` horas. Sin ese tope, el primer despliegue
 * habría escrito a todos los que adelantaron hace semanas, de golpe y sin contexto. Y una vez por
 * reserva: ver `SaldoPendiente`.
 *
 * ⚠️ Las fechas se calculan en PHP: `pagado_en` lo escribe Doctrine en la zona de PHP
 * (`America/Lima`) y el servidor de base va en UTC. Ver `PmsPrepagoRevisarLlegadasCommand`.
 *
 *   php bin/console app:pms:prepago:saldo-tras-adelanto --dry-run
 */
#[AsCommand(
    name: 'app:pms:prepago:saldo-tras-adelanto',
    description: 'Emite el enlace del saldo media hora después de pagado el adelanto, y avisa al huésped.',
)]
final class PmsPrepagoSaldoTrasAdelantoCommand extends Command
{
    /** Hasta cuántas horas después del pago del adelanto se avisa al huésped. */
    private const int HORAS_DE_AVISO = 6;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsPrepagoEnlaceService $prepago,
        private readonly SaldoPendiente $aviso,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué reservas tocaría, sin emitir ni avisar.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        if (!$this->prepago->estaActivo()) {
            $io->warning('Los enlaces de prepago están desactivados (FINANZAS_ENLACES_PREPAGO=0).');

            return Command::SUCCESS;
        }

        $corte = new DateTimeImmutable(sprintf('-%d minutes', PmsPrepagoEnlaceService::MINUTOS_SALDO_TRAS_ADELANTO));
        $avisoDesde = new DateTimeImmutable(sprintf('-%d hours', self::HORAS_DE_AVISO));

        /** @var list<array{info_id: string, loc: string, pagado: string}> $filas */
        $filas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT BIN_TO_UUID(i.id) AS info_id, r.localizador AS loc, MAX(e.pagado_en) AS pagado
             FROM pms_reserva r
             INNER JOIN pms_informacion_financiera i ON i.reserva_id = r.id
             INNER JOIN fin_enlace_pago e ON e.origen_id = r.id AND e.origen_tipo = :tipo
             WHERE e.creado_por_id IS NULL
               AND e.estado = :pagado
               AND e.pagado_en <= :corte
               AND r.fecha_llegada >= :hoy
             GROUP BY i.id, r.localizador',
            [
                'tipo' => FinOrigenCobro::PMS_RESERVA->value,
                'pagado' => 'pagado',
                'corte' => $corte->format('Y-m-d H:i:s'),
                'hoy' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            ],
        );

        $emitidos = 0;
        $avisados = 0;

        foreach ($filas as $fila) {
            $info = $this->em->getRepository(PmsInformacionFinanciera::class)->find($fila['info_id']);
            $reserva = $info?->getReserva();

            if (!$info instanceof PmsInformacionFinanciera || $reserva === null) {
                continue;
            }

            $tocaAvisar = new DateTimeImmutable($fila['pagado']) >= $avisoDesde;

            if ($seco) {
                $io->writeln(sprintf('  %s · adelanto pagado %s%s', $fila['loc'], $fila['pagado'], $tocaAvisar ? ' · se avisaría' : ''));

                continue;
            }

            // El mismo emisor que el automático: decide si procede y por cuánto, y no emite si ya
            // hay un enlace vivo por ese importe.
            if ($this->prepago->emitirPorCambioDeCargos($info) !== null) {
                ++$emitidos;
                $io->writeln(sprintf('  %s · enlace del saldo emitido', $fila['loc']));
            }

            if ($tocaAvisar && $this->aviso->avisar($reserva)) {
                ++$avisados;
                $io->writeln(sprintf('  %s · huésped avisado', $fila['loc']));
            }
        }

        $io->success(sprintf('%d candidata(s) · %d enlace(s) emitido(s) · %d aviso(s).', count($filas), $emitidos, $avisados));

        return Command::SUCCESS;
    }
}
