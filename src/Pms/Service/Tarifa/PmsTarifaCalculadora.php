<?php

declare(strict_types=1);

namespace App\Pms\Service\Tarifa;

use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Tarifa\Engine\TarifaPricingEngine;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cuánto cuesta cada noche de una casita, resolviendo qué rango gana.
 *
 * Existe para que **haya una sola respuesta a esa pregunta**. La hacen ya tres sitios —el
 * agente al consultar tarifas, el agente al ofrecer disponibilidad y el cálculo de cargos al
 * crear una reserva—, y cada uno resolviéndolo por su cuenta es garantizar que un día el
 * precio que se enseña y el que se cobra dejen de coincidir.
 *
 * ### 🏆 Qué rango gana un día
 *
 * Sobre una misma noche pueden solaparse varios `PmsTarifaRango`. El desempate lo pone
 * {@see \App\Pms\Service\Tarifa\Engine\TarifaDailyPriceFlattener}, no este servicio:
 *
 * ```
 * 1. important = true            ← una promo marcada gana a todo
 * 2. prioridad (weight) mayor
 * 3. duración más corta          ← un rango de 3 días gana al de todo el mes
 * 4. id más reciente
 * ```
 *
 * Sólo si NINGÚN rango cubre la noche se cae a la tarifa base de la unidad, y eso se distingue
 * en la respuesta: vender a la base no es lo mismo que vender a una tarifa puesta a propósito.
 */
final readonly class PmsTarifaCalculadora
{
    public function __construct(
        private EntityManagerInterface $em,
        private TarifaPricingEngine $motor,
    ) {}

    /**
     * El precio de cada noche del tramo `[desde, hasta)`, indexado por fecha.
     *
     * @return array<string, array{price: float, minStay: int, currency: ?string, sourceId: string}>
     */
    public function preciosPorNoche(
        PmsUnidad $unidad,
        DateTimeInterface $desde,
        DateTimeInterface $hasta
    ): array {
        return $this->motor->buildDailyPricesForIntervalWithFallback(
            rangos: $this->rangosDe($unidad, $desde, $hasta),
            from: $desde,
            to: $hasta,
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
    }

    /**
     * Lo que costaría el tramo entero: total, moneda, estancia mínima y cuántas noches salen
     * a la tarifa base por no tener rango propio.
     *
     * `null` en `total` cuando no hay ni rangos ni tarifa base activa — que NO es lo mismo que
     * costar cero.
     *
     * @return array{total: ?float, moneda: ?string, min_stay: int, noches: int, noches_sin_tarifa: int}
     */
    public function resumen(
        PmsUnidad $unidad,
        DateTimeInterface $desde,
        DateTimeInterface $hasta
    ): array {
        $diarios = $this->preciosPorNoche($unidad, $desde, $hasta);

        $total = 0.0;
        $moneda = null;
        $minStay = 0;
        $sinTarifa = 0;

        foreach ($diarios as $datos) {
            $total += (float) $datos['price'];
            $moneda ??= $datos['currency'];
            $minStay = max($minStay, (int) $datos['minStay']);

            if (self::vieneDeLaBase((string) $datos['sourceId'])) {
                ++$sinTarifa;
            }
        }

        return [
            'total' => $diarios !== [] ? $total : null,
            'moneda' => $moneda,
            'min_stay' => $minStay,
            'noches' => count($diarios),
            'noches_sin_tarifa' => $sinTarifa,
        ];
    }

    /**
     * ¿Ese precio salió de la tarifa base y no de un rango?
     *
     * El `sourceId` lo compone `TarifaDailyPriceFlattener::computeSourceId()`: `id:<uuid>` con
     * rango, `base:unidad:<uuid>` con el fallback. Se pregunta aquí para que quien lo use no
     * tenga que conocer ese formato.
     */
    public static function vieneDeLaBase(string $sourceId): bool
    {
        return !str_starts_with($sourceId, 'id:');
    }

    /** El uuid del rango que ganó, o `null` si fue la tarifa base. */
    public static function rangoDe(string $sourceId): ?string
    {
        return self::vieneDeLaBase($sourceId) ? null : substr($sourceId, 3);
    }

    /**
     * Los rangos activos que tocan el tramo.
     *
     * ⚠️ `findBy()` y NO `createQueryBuilder()->setParameter('unidad', $unidad)`: el id es un
     * UUID `BINARY(16)` y en DQL ese parámetro se serializa mal — la consulta **no falla**,
     * devuelve CERO filas, y el resultado es que todas las noches acaban saliendo a la tarifa
     * base aunque tengan su rango cargado. Misma trampa que en
     * `PmsExtensionEstanciaService::buscar()` y §12.6 del doc de sync.
     *
     * El solape se filtra en PHP por lo mismo: meterlo en el DQL obligaría a volver al query
     * builder. Son pocas filas por unidad y no compensa el riesgo.
     *
     * @return list<PmsTarifaRango>
     */
    private function rangosDe(
        PmsUnidad $unidad,
        DateTimeInterface $desde,
        DateTimeInterface $hasta
    ): array {
        return array_values(array_filter(
            $this->em->getRepository(PmsTarifaRango::class)->findBy([
                'unidad' => $unidad,
                'activo' => true,
            ]),
            static fn (PmsTarifaRango $t): bool => $t->getFechaInicio() !== null
                && $t->getFechaFin() !== null
                && $t->getFechaInicio() < $hasta
                && $t->getFechaFin() >= $desde
        ));
    }
}
