<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Finanzas\CotizacionEnlaceService;
use App\Cotizacion\Finanzas\CotizacionOrigenCobroResolver;
use App\Cotizacion\Service\Message\CotizacionAvisosDeCobro;
use App\Cotizacion\Service\Publico\ComoReservarResolver;
use App\Finanzas\Enum\FinOrigenCobro;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Emite el enlace del SALDO de un viaje media hora después de pagado el adelanto con tarjeta, y se
 * lo avisa al cliente por WhatsApp (16/10/2026). El gemelo de `app:pms:prepago:saldo-tras-adelanto`
 * (Jorge: «como alojamiento»), y un barrido por lo mismo: un mensaje diferido que se pierde no lo
 * recoge nadie; esto corre cada 5 minutos, pregunta y es idempotente.
 *
 * ### La detección
 * Una cotización con un enlace AUTOMÁTICO `pagado` hace media hora o más, de un expediente abierto
 * y cuyo primer servicio aún no pasó. Ese estado sólo lo escribe `FinEnlacePagoService::confirmarPago()`
 * con el cargo confirmado por la pasarela.
 *
 * ### Sólo con el adelanto CUBIERTO y saldo pendiente
 * `CotizacionEnlaceService::adelantoCubierto()` (la regla de pax): con una parte de un adelanto en
 * tramos sin pagar —anulada, o caducada si era manual— se emitía el saldo y el aviso decía «tu
 * adelanto ya está registrado» con la mitad pagada.
 *
 * ### ⚠️ Con algún enlace vivo, no se toca
 * Si el adelanto salió en TRAMOS y queda una parte por pagar, emitir el saldo la anularía (un cobro
 * nuevo anula a los vivos) y le juntaría el resto del viaje: el cliente perdería el «parte 2 de 2»
 * que tenía delante. Se espera a que no quede nada vivo. Lo mismo si ya hay un enlace del saldo.
 *
 * ### El aviso, sólo a los recientes y una vez
 * Se avisa en la misma pasada en que se emite, y sólo si el pago fue en las últimas
 * `HORAS_DE_AVISO`: sin ese tope, el primer despliegue escribiría de golpe a quien pagó hace semanas.
 *
 * Plantilla `saldo_pendiente_viaje` (`msg:plantillas:saldo-pendiente-viaje`): hasta que Meta la
 * apruebe, fuera de la ventana de 24 h el aviso no sale (queda en el log del envío).
 *
 *   php bin/console app:cotizacion:saldo-tras-adelanto --dry-run
 */
#[AsCommand(
    name: 'app:cotizacion:saldo-tras-adelanto',
    description: 'Emite el enlace del saldo del viaje media hora después de pagado el adelanto, y avisa al cliente.',
)]
final class CotizacionSaldoTrasAdelantoCommand extends Command
{
    /** Minutos desde el pago del adelanto hasta el enlace del saldo, como en alojamiento. */
    public const int MINUTOS_SALDO_TRAS_ADELANTO = 30;

    /** Hasta cuántas horas después del pago del adelanto se avisa al cliente. */
    private const int HORAS_DE_AVISO = 6;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CotizacionEnlaceService $enlaces,
        private readonly CotizacionAvisosDeCobro $avisos,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué cotizaciones tocaría, sin emitir ni avisar.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        // ⚠️ Las fechas en PHP: `pagado_en` lo escribe Doctrine en la zona de PHP y el servidor de
        // base va en UTC (ver `PmsPrepagoSaldoTrasAdelantoCommand`).
        $corte = new DateTimeImmutable(sprintf('-%d minutes', self::MINUTOS_SALDO_TRAS_ADELANTO));
        $avisoDesde = new DateTimeImmutable(sprintf('-%d hours', self::HORAS_DE_AVISO));
        $hoy = new DateTimeImmutable('today');

        /** @var list<array{cot: string, loc: string, pagado: string}> $filas */
        $filas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT BIN_TO_UUID(c.id) AS cot, f.localizador AS loc, MAX(e.pagado_en) AS pagado
             FROM fin_enlace_pago e
             INNER JOIN cotizacion_cotizacion c ON c.id = e.origen_id
             INNER JOIN cotizacion_file f ON f.id = c.file_id
             WHERE e.origen_tipo = :tipo
               AND e.creado_por_id IS NULL
               AND e.estado = :pagado
               AND e.pagado_en <= :corte
               AND f.estado = :abierto
               AND c.estado NOT IN (:descartadas)
             GROUP BY c.id, f.localizador',
            [
                'tipo' => FinOrigenCobro::COTIZACION->value,
                'pagado' => 'pagado',
                'corte' => $corte->format('Y-m-d H:i:s'),
                'abierto' => 'abierto',
                // Una propuesta cerrada, cancelada o congelada en histórico no se cobra.
                'descartadas' => ['cerrado', 'cancelado', 'historico'],
            ],
            ['descartadas' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $tabla = [];

        foreach ($filas as $fila) {
            $cotizacion = $this->em->find(Cotizacion::class, Uuid::fromString($fila['cot']));
            $inicio = $cotizacion !== null ? ComoReservarResolver::primerServicio($cotizacion) : null;

            if ($cotizacion === null || ($inicio !== null && $inicio < $hoy)) {
                continue; // El viaje ya empezó: el resto se cobra en destino.
            }

            if ($this->enlaces->pagables($cotizacion) !== []) {
                continue; // Una parte del adelanto por pagar, o el saldo ya emitido. Ver la cabecera.
            }

            // ANTES del --dry-run: sin esto la simulación anunciaba «emitiría» en propuestas ya
            // saldadas, y el saldo se emitía con medio adelanto pagado (revisión del 16/10/2026).
            if ($cotizacion->getFile() === null
                || CotizacionOrigenCobroResolver::saldoUsd($cotizacion->getFile(), $cotizacion) <= 0
                || !$this->enlaces->adelantoCubierto($cotizacion)
            ) {
                continue;
            }

            if ($seco) {
                $tabla[] = [$fila['loc'], 'v' . $cotizacion->getPropuesta(), $fila['pagado'], 'emitiría el saldo'];
                continue;
            }

            $emitidos = $this->enlaces->emitirSaldo($cotizacion);
            $avisado = $emitidos !== [] && new DateTimeImmutable($fila['pagado']) >= $avisoDesde
                && $this->avisos->saldoPendiente($cotizacion);

            $tabla[] = [
                $fila['loc'],
                'v' . $cotizacion->getPropuesta(),
                $fila['pagado'],
                $emitidos === [] ? 'sin saldo (o no procede)' : sprintf('%d enlace(s)%s', \count($emitidos), $avisado ? ' · avisado' : ''),
            ];
        }

        if ($tabla !== []) {
            $io->table(['expediente', 'propuesta', 'adelanto pagado', 'resultado'], $tabla);
        }

        $io->success($seco ? '--dry-run: nada emitido.' : sprintf('%d cotización(es) revisada(s).', \count($tabla)));

        return Command::SUCCESS;
    }
}
