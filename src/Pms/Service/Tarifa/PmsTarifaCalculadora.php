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
     * ### 👥 El suplemento por persona
     *
     * Con `$pax` se suma lo que cobra la unidad por las personas que pasan de las incluidas en
     * la tarifa ({@see PmsUnidad::suplementoPorPax()}). Va desglosado y no metido dentro del
     * total a secas: el operador tiene que poder decirle al huésped «son 135 de alojamiento
     * más 36 por dos personas de más», no soltarle 171 sin explicación.
     *
     * Sin `$pax` no se aplica nada — consultar el precio de una casita sin saber cuántos van
     * es una pregunta legítima, y ahí el suplemento todavía no se puede calcular.
     *
     * ### 🧹 La limpieza
     *
     * Va **por estancia y no por noche** ({@see PmsUnidad::costoLimpieza()}): se limpia al
     * salir, una vez. Se cobra en todos los canales y entra en el total siempre.
     *
     * El mismo campo se lee como importe o como PORCENTAJE sobre alojamiento + suplemento,
     * según el flag de la unidad. En 0 no se cobra, y entonces no aparece ni como línea de
     * cero.
     *
     * ### 🏷️ El servicio de la OTA
     *
     * Sólo si `$canalId` es uno de los canales marcados en la unidad. **No lo cobra el PMS**:
     * lo aplica Booking o Airbnb por su cuenta, y se calcula aquí para poder cuadrar lo que el
     * huésped acaba pagando allí con lo que se cotiza aquí.
     *
     * ⚠️ Su base es alojamiento + suplemento, **sin la limpieza** — la regla vive en
     * {@see PmsUnidad::servicioSobre()} y no se reescribe aquí. Es la misma base que usa el
     * porcentaje de limpieza, y aun así son dos reglas separadas: coinciden, no son una.
     *
     * Sin `$canalId` no se aplica: una cotización sin canal es un directo o un tanteo, y
     * ninguno de los dos paga servicio. El porcentaje y los canales viajan igualmente en la
     * respuesta, como REFERENCIA, para poder decir «en Booking se le añade un 16%» sin
     * inflar un total que nadie va a cobrar.
     *
     * @return array{total: ?float, alojamiento: ?float, suplemento_pax: float, pax_adicionales: int, limpieza: float, limpieza_porcentaje: float, servicio: float, porcentaje_servicio: float, servicio_canales: list<string>, moneda: ?string, min_stay: int, noches: int, noches_sin_tarifa: int, precios_por_noche: list<float>}
     */
    public function resumen(
        PmsUnidad $unidad,
        DateTimeInterface $desde,
        DateTimeInterface $hasta,
        ?int $pax = null,
        ?string $canalId = null
    ): array {
        $diarios = $this->preciosPorNoche($unidad, $desde, $hasta);

        $alojamiento = 0.0;
        $moneda = null;
        $minStay = 0;
        $sinTarifa = 0;

        foreach ($diarios as $datos) {
            $alojamiento += (float) $datos['price'];
            $moneda ??= $datos['currency'];
            $minStay = max($minStay, (int) $datos['minStay']);

            if (self::vieneDeLaBase((string) $datos['sourceId'])) {
                ++$sinTarifa;
            }
        }

        $noches = count($diarios);
        $suplemento = $pax !== null ? $unidad->suplementoPorPax($pax, $noches) : 0.0;
        $adicionales = $pax !== null && $unidad->cobraPaxAdicional()
            ? max(0, $pax - $unidad->getPaxIncluidos())
            : 0;

        // 💵 Los precios distintos que hay dentro del tramo. Un tramo puede cruzar dos
        // temporadas —65 las tres primeras noches y 45 las dos últimas— y ahí un «precio por
        // noche» promedio daría 57.00: un número que no se cobra ninguna noche y que el
        // operador repetiría al huésped. Se devuelven los precios REALES y quien pinta decide
        // si dice uno («45.00/noche») o un rango («de 45.00 a 65.00»).
        $precios = array_values(array_unique(array_map(
            static fn (array $d): float => (float) $d['price'],
            $diarios
        )));
        sort($precios);

        // Las dos bases son hoy la MISMA —alojamiento + suplemento— y aun así se calculan por
        // separado: son dos reglas distintas que coinciden, no una sola. Cada `%` decide en su
        // método; aquí sólo se les entrega lo que cuenta.
        //
        // Lo que NO entra en ninguna de las dos es la limpieza: el servicio no se cobra sobre
        // ella, y ella no se cobra sobre sí misma.
        $base = $alojamiento + $suplemento;

        $limpieza = $unidad->costoLimpieza($noches, $base);
        $servicio = $unidad->servicioSobre($base, $canalId);

        return [
            'total' => $noches > 0 ? $alojamiento + $suplemento + $limpieza + $servicio : null,
            'alojamiento' => $noches > 0 ? $alojamiento : null,
            'suplemento_pax' => $suplemento,
            'pax_adicionales' => $adicionales,
            'limpieza' => $limpieza,
            // Para poder decir «limpieza 15%» y no sólo el importe: al cliente le cambia cómo
            // lo entiende. `0.0` = importe fijo.
            'limpieza_porcentaje' => $unidad->limpiezaEsPorcentaje()
                ? (float) $unidad->getPrecioLimpieza()
                : 0.0,
            'servicio' => $servicio,
            // De referencia, viajen o no aplicados: sirven para decir en cuánto sale por
            // Booking sin tener que cotizar dos veces.
            'porcentaje_servicio' => (float) $unidad->getPorcentajeServicio(),
            'servicio_canales' => $unidad->idsCanalesServicio(),
            'moneda' => $moneda,
            'min_stay' => $minStay,
            'noches' => $noches,
            'noches_sin_tarifa' => $sinTarifa,
            'precios_por_noche' => $precios,
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
