<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Finanzas\PmsPrepagoEnlaceService;
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
 * Releva el enlace de ADELANTO por uno del SALDO en las reservas que ya llegaron.
 *
 * ### Por qué hace falta un reloj
 *
 * La regla la decide `PmsPrepagoCalculador::queSePide()`: desde el día de llegada se pide el
 * total. Pero el emisor de enlaces se dispara **por movimiento, no por reloj** — vive en el
 * `postFlush` de `PmsInformacionFinancieraCoherenciaListener` y sólo entra si en ese flush se
 * movió un cargo, un pago, una estancia o nació la reserva.
 *
 * Consecuencia: una reserva que no se mueve el día de su llegada se queda con el enlace de
 * adelanto vivo —nace con `vigenciaDias: 0`, o sea **sin caducidad**— pidiendo la primera
 * noche, mientras el mensaje y la app del huésped ya piden el total. Lo normal es que Beds24
 * mueva algo y se releve solo, pero «lo normal» no es una garantía.
 *
 * ### Las cuatro superficies
 *
 * El mismo dinero se enseña en cuatro sitios y tres ya eran conscientes de la fecha:
 *
 * | Superficie | Qué la hace coherente |
 * |---|---|
 * | Tarjeta del huésped en `pax` | `PmsSituacionDeCobroResolver` |
 * | El agente (`consultar_cuenta`) | el mismo resolver, desde el 30/08/2026 |
 * | Panel del operador | la guarda de `PmsInformacionFinancieraPorReservaProvider::prepago()` |
 * | **El enlace de pago** | este comando |
 *
 * ### Qué hace, exactamente
 *
 * Pasa por el emisor de siempre —`emitirPorCambioDeCargos()`— las reservas que **ya tienen un
 * enlace automático vivo** y cuya llegada ya ocurrió. Ni inventa reglas ni amplía el público:
 * si el emisor decide que no procede, no procede. Emitir donde no había nada sería otra cosa,
 * y no es ésta.
 *
 * Es **idempotente**: si el enlace vivo ya es por el importe correcto, el emisor lo reconoce y
 * no toca nada. Correrlo dos veces no duplica.
 *
 * ⚠️ **La fecha se calcula en PHP, no en MySQL.** El servidor tiene el reloj en UTC y PHP en
 * `America/Lima`: entre las 19:00 y la medianoche de Lima, `CURDATE()` ya es el día siguiente.
 * Usar la fecha de la base adelantaría el relevo cinco horas, justo en la franja en que un
 * huésped puede estar pagando.
 *
 *   php bin/console app:pms:prepago:revisar-llegadas --dry-run
 */
#[AsCommand(
    name: 'app:pms:prepago:revisar-llegadas',
    description: 'Releva el enlace de adelanto por uno del saldo en las reservas que ya llegaron.',
)]
final class PmsPrepagoRevisarLlegadasCommand extends Command
{
    /** Hasta cuántos días atrás se miran las llegadas. Acota la consulta sin perder rezagados. */
    private const DIAS_ATRAS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinEnlacePagoRepository $enlaces,
        private readonly PmsPrepagoEnlaceService $prepago,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué relevaría, sin tocar nada.');
        $this->addOption(
            'dias-atras',
            null,
            InputOption::VALUE_REQUIRED,
            'Cuántos días atrás mirar las llegadas.',
            (string) self::DIAS_ATRAS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $diasAtras = max(0, (int) $input->getOption('dias-atras'));

        if (!$this->prepago->estaActivo()) {
            $io->warning('Los enlaces de prepago están desactivados (FINANZAS_ENLACES_PREPAGO=0): no hay nada que relevar.');

            return Command::SUCCESS;
        }

        // ⚠️ En PHP, que es donde está America/Lima. Ver la cabecera de la clase.
        $hoy = new DateTimeImmutable('today');
        $desde = $hoy->modify(sprintf('-%d days', $diasAtras));

        $filas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT BIN_TO_UUID(i.id) AS info_id, r.localizador AS loc, r.fecha_llegada AS llegada
             FROM pms_reserva r
             INNER JOIN pms_informacion_financiera i ON i.reserva_id = r.id
             INNER JOIN fin_enlace_pago e ON e.origen_id = r.id AND e.origen_tipo = :tipo
             WHERE i.activa = 1
               AND r.fecha_llegada BETWEEN :desde AND :hoy
               AND e.creado_por_id IS NULL
               AND e.estado IN (:pendiente, :fallido)
             ORDER BY r.fecha_llegada DESC',
            [
                'tipo' => FinOrigenCobro::PMS_RESERVA->value,
                'desde' => $desde->format('Y-m-d'),
                'hoy' => $hoy->format('Y-m-d'),
                // Los dos estados desde los que un enlace todavía puede pagarse. La caducidad
                // NO se filtra aquí: `expira_en` lo escribe Doctrine en la zona de PHP y el
                // servidor de base va en UTC. Lo decide `estaVigente()` más abajo, que es
                // quien sabe.
                'pendiente' => 'pendiente',
                'fallido' => 'fallido',
            ],
        );

        if ($filas === []) {
            $io->success('Ninguna reserva ya llegada conserva un enlace automático vivo.');

            return Command::SUCCESS;
        }

        $io->title(sprintf(
            'Llegadas del %s al %s · %d candidata(s)%s',
            $desde->format('d/m/Y'),
            $hoy->format('d/m/Y'),
            count($filas),
            $seco ? ' · SIMULACIÓN' : '',
        ));

        $relevadas = 0;
        $sinCambio = 0;

        foreach ($filas as $fila) {
            $localizador = (string) $fila['loc'];
            $info = $this->em->getRepository(PmsInformacionFinanciera::class)->find((string) $fila['info_id']);

            if (!$info instanceof PmsInformacionFinanciera) {
                continue;
            }

            $reservaId = $info->getReserva()?->getId();

            if ($reservaId === null) {
                continue;
            }

            $vivoAntes = $this->automaticoVivo($reservaId);

            // El SQL no puede decidir la vigencia (ver arriba): si ya caducó, no hay nada que
            // relevar y el emisor tampoco lo tocaría.
            if ($vivoAntes === null) {
                continue;
            }

            if ($seco) {
                $io->writeln(sprintf(
                    '  %s · vivo: «%s» %s %s',
                    $localizador,
                    $vivoAntes->getConcepto(),
                    $vivoAntes->getMonedaCodigo(),
                    $vivoAntes->getMontoNeto(),
                ));
                continue;
            }

            // El camino de siempre. Si no procede relevar, devuelve null y no toca nada.
            $nuevo = $this->prepago->emitirPorCambioDeCargos($info);

            if ($nuevo === null) {
                $sinCambio++;
                continue;
            }

            $relevadas++;
            $io->writeln(sprintf(
                '  ✅ %s · «%s» %s %s  ←  «%s» %s',
                $localizador,
                $nuevo->getConcepto(),
                $nuevo->getMonedaCodigo(),
                $nuevo->getMontoNeto(),
                $vivoAntes->getConcepto(),
                $vivoAntes->getMontoNeto(),
            ));
        }

        if ($seco) {
            $io->note('Simulación: no se emitió ni se anuló nada.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d relevada(s), %d sin cambio.', $relevadas, $sinCambio));

        return Command::SUCCESS;
    }

    /** El enlace automático que todavía se puede pagar, si lo hay. */
    private function automaticoVivo(Uuid $reservaId): ?FinEnlacePago
    {
        foreach ($this->enlaces->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId) as $enlace) {
            if ($enlace->getCreadoPorNombre() === null && $enlace->estaVigente()) {
                return $enlace;
            }
        }

        return null;
    }
}
