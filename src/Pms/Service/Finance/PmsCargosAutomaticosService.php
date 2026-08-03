<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Service\Tarifa\Engine\TarifaPricingEngine;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Genera los cargos de una estancia DIRECTA a partir del tarifario.
 *
 * Una reserva OTA recibe sus importes de Beds24 (§11); una directa no recibe nada, y hasta
 * ahora había que teclearlos. Este servicio los arma solos al crear la estancia:
 *
 *   · ALOJAMIENTO — suma del precio de CADA DÍA del tarifario, no una tarifa plana. Se usa el
 *     mismo motor que pinta el calendario de tarifas (TarifaPricingEngine), así que respeta
 *     temporadas, prioridades y solapamientos exactamente igual que lo que ve el operador.
 *   · LIMPIEZA — importe fijo (`TARIFA_LIMPIEZA`).
 *   · SERVICIO — **no se genera**: en las reservas directas se exonera.
 *
 * Todo queda como cargo MANUAL (sin `beds24ItemId`), así que el operador puede corregirlo o
 * borrarlo sin pelearse con la sincronización.
 */
final class PmsCargosAutomaticosService
{
    /** Importe fijo de limpieza para reservas directas, en la moneda de la cabecera. */
    public const string TARIFA_LIMPIEZA = '15.00';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TarifaPricingEngine $pricingEngine,
        private readonly MonedaResolver $monedaResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * ¿Esta estancia debe estrenar cargos automáticos?
     *
     * Sólo las DIRECTAS: una OTA los recibe del canal y duplicarlos falsearía el saldo. Los
     * bloqueos tampoco — no son una venta.
     */
    public function aplica(PmsEventoCalendario $evento): bool
    {
        $canal = $evento->getChannel()?->getId();
        if ($canal !== null && $canal !== PmsChannel::CODIGO_DIRECTO) {
            return false;
        }

        if ($evento->getEstado()?->getId() === PmsEventoEstado::CODIGO_BLOQUEO) {
            return false;
        }

        return $evento->getPmsUnidad() !== null
            && $evento->getInicio() !== null
            && $evento->getFin() !== null;
    }

    /**
     * Crea los cargos de la estancia. NO hace flush: lo hace quien llama.
     *
     * Es idempotente por estancia: si ya tiene cargos imputados, no añade nada. Así un
     * segundo guardado del drawer no duplica el alojamiento.
     */
    public function generarParaEvento(PmsEventoCalendario $evento, PmsInformacionFinanciera $info): void
    {
        if (!$this->aplica($evento) || $this->yaTieneCargos($evento, $info)) {
            return;
        }

        $moneda = $info->getMoneda() ?? $this->monedaResolver->resolve(null);

        $noches = $this->calcularAlojamiento($evento);
        if ($noches !== null && $noches > 0) {
            $this->crearCargo(
                info: $info,
                evento: $evento,
                tipo: PmsTipoCargo::ALOJAMIENTO,
                descripcion: 'Alojamiento',
                importe: number_format($noches, 2, '.', ''),
                moneda: $moneda,
            );
        }

        $this->crearCargo(
            info: $info,
            evento: $evento,
            tipo: PmsTipoCargo::LIMPIEZA,
            descripcion: 'Suplemento de limpieza',
            importe: self::TARIFA_LIMPIEZA,
            moneda: $moneda,
        );

        // SERVICIO: intencionadamente ausente — se exonera en las reservas directas.
    }

    /** ¿Ya se le generaron cargos a esta estancia? */
    private function yaTieneCargos(PmsEventoCalendario $evento, PmsInformacionFinanciera $info): bool
    {
        foreach ($info->getCargos() as $cargo) {
            if ($cargo->getEvento() === $evento) {
                return true;
            }
        }

        return false;
    }

    /**
     * Suma el precio de cada noche del tarifario.
     *
     * El intervalo es [llegada, salida) — la noche de salida no se cobra —, que es justo el
     * contrato del flattener (`to` exclusivo).
     *
     * Devuelve null (y no se crea el cargo) si NO se puede precisar todas las noches: es
     * preferible que el operador teclee el importe a cobrarle de menos sin que nadie se entere.
     */
    private function calcularAlojamiento(PmsEventoCalendario $evento): ?float
    {
        $inicio = $evento->getInicio();
        $fin = $evento->getFin();
        $unidad = $evento->getPmsUnidad();

        if (!$inicio || !$fin || !$unidad || $fin <= $inicio) {
            return null;
        }

        try {
            $rangos = $this->em->getRepository(PmsTarifaRango::class)
                ->createQueryBuilder('t')
                ->andWhere('t.unidad = :unidad')
                ->andWhere('t.activo = true')
                // Solapan con la estancia: empiezan antes de la salida y acaban tras la llegada.
                ->andWhere('t.fechaInicio < :fin AND t.fechaFin >= :inicio')
                ->setParameter('unidad', $unidad)
                ->setParameter('inicio', $inicio)
                ->setParameter('fin', $fin)
                ->getQuery()
                ->getResult();

            // Mismo fallback que el push de tarifas a Beds24 (Beds24RatesPushQueueCreator): los
            // días sin rango se cubren con la tarifa base de la unidad, si está activa.
            $preciosDiarios = $this->pricingEngine->buildDailyPricesForIntervalWithFallback(
                rangos: $rangos,
                from: $inicio,
                to: $fin,
                rangeAccessor: static fn (PmsTarifaRango $t): array => [
                    'start' => $t->getFechaInicio(),
                    'end' => $t->getFechaFin(),
                    'price' => (float) $t->getPrecio(),
                    'minStay' => $t->getMinStay(),
                    'currency' => $t->getMoneda()?->getId(),
                    'important' => $t->isImportante(),
                    'weight' => $t->getPrioridad(),
                    'id' => (string) $t->getId(),
                ],
                fallbackProvider: static fn (): ?array => $unidad->isTarifaBaseActiva() ? [
                    'price' => (float) $unidad->getTarifaBasePrecio(),
                    'minStay' => $unidad->getTarifaBaseMinStay(),
                    'currency' => $unidad->getTarifaBaseMoneda()?->getId(),
                    'sourceId' => 'base:unidad:' . (string) $unidad->getId(),
                ] : null,
            );

            // El flattener OMITE los días que no logra precisar; si falta alguno, el total sería
            // una estancia más corta de la real. Se prefiere no cobrar nada a cobrar de menos.
            // A DÍA, no a instante: la estancia va de las 14:00 a las 10:00, así que un
            // diff() crudo de dos noches devolvería "1 día y 20 horas" → 1. El flattener
            // trunca a medianoche (§12.5.5), y aquí hay que contar igual.
            $nochesEsperadas = (int) $this->aDia($inicio)->diff($this->aDia($fin))->days;
            if (count($preciosDiarios) < $nochesEsperadas) {
                $this->logger->info('Tarifario incompleto para la estancia: no se genera cargo de alojamiento.', [
                    'unidad' => (string) $unidad->getId(),
                    'nochesEsperadas' => $nochesEsperadas,
                    'nochesConPrecio' => count($preciosDiarios),
                ]);
                return null;
            }

            $total = 0.0;
            foreach ($preciosDiarios as $dia) {
                $total += (float) ($dia['price'] ?? 0);
            }

            return $total;
        } catch (Throwable $e) {
            // Nunca romper el guardado de la reserva por el tarifario: se avisa y se sigue
            // sin cargo de alojamiento, que el operador puede añadir a mano.
            $this->logger->error('Fallo calculando el alojamiento desde el tarifario.', ['exception' => $e]);
            return null;
        }
    }

    /** Trunca a medianoche conservando la fecha de pared (mismo criterio que el flattener). */
    private function aDia(DateTimeInterface $dt): DateTimeImmutable
    {
        return new DateTimeImmutable($dt->format('Y-m-d') . ' 00:00:00');
    }

    private function crearCargo(
        PmsInformacionFinanciera $info,
        PmsEventoCalendario $evento,
        PmsTipoCargo $tipo,
        string $descripcion,
        string $importe,
        object $moneda,
    ): void {
        // Sin beds24ItemId => cargo manual: editable y borrable por el operador (§11.5).
        $cargo = new PmsCargoFinanciero();
        $cargo->setTipoCargo($tipo);
        $cargo->setDescripcion($descripcion);
        $cargo->setTotalLinea($importe);
        $cargo->setMonto($importe);
        $cargo->setMoneda($moneda);
        $cargo->setEvento($evento);

        $info->addCargo($cargo);
        $this->em->persist($cargo);
    }
}
