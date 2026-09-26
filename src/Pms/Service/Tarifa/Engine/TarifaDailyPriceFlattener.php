<?php
declare(strict_types=1);

namespace App\Pms\Service\Tarifa\Engine;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeInterface;
use App\Dto\Lee;
use InvalidArgumentException;

/**
 * Aplana rangos (posiblemente solapados) a valores por día.
 *
 * Convención:
 * - Intervalo: [from, to) (to exclusivo).
 * - Si varios rangos cubren un día, decide con un comparador de prioridad.
 * - Preserva un identificador del "rango ganador" por día: sourceId
 *   (si no viene id, genera un hash estable).
 * - Si un día NO tiene ganador, puede rellenar con fallbackProvider (tarifa base).
 *
 * Lo que devuelve el `rangeAccessor` se lee UNA vez, en `leerRango()`, a una forma con tipo: el
 * comparador y el día ganador trabajan con ella, no con el array que dio el accessor (nivel 10 de
 * PHPStan). El `sourceId` se calcula ANTES, sobre los datos crudos, para que el hash no cambie.
 *
 * @phpstan-type RangoLeido array{
 *     start: DateTimeInterface,
 *     end: DateTimeInterface,
 *     price: float,
 *     minStay: ?int,
 *     currency: ?string,
 *     important: bool,
 *     weight: int,
 *     id: int,
 *     sourceId: string,
 * }
 * @phpstan-type Candidato array{raw: mixed, data: RangoLeido, start: DateTimeImmutable, end: DateTimeImmutable}
 */
final class TarifaDailyPriceFlattener
{
    /**
     * @param array<int, object|array<string,mixed>> $rangos
     * @param callable $rangeAccessor fn($r): array{
     *     start:DateTimeInterface,
     *     end:DateTimeInterface,
     *     price:float|int|string,
     *     minStay?:int|null,
     *     currency?:string|null,
     *     important?:bool,
     *     weight?:int,
     *     id?:int|string|null,
     * }
     * @param (callable(Candidato, Candidato): int)|null $priorityComparator
     * @param callable|null $fallbackProvider fn(DateTimeImmutable $day): ?array{
     *   price:float|int|string,
     *   minStay?:int|null,
     *   currency?:string|null,
     *   sourceId?:string|null
     * }
     *
     * @return array<string, array{
     *   price:float,
     *   minStay:int,
     *   currency:?string,
     *   sourceId:string
     * }>
     */
    public function flatten(
        array $rangos,
        DateTimeInterface $from,
        DateTimeInterface $to,
        callable $rangeAccessor,
        ?callable $priorityComparator = null,
        ?callable $fallbackProvider = null
    ): array {
        $fromDay = $this->toDay($from);
        $toDay = $this->toDay($to);

        if ($toDay <= $fromDay) {
            throw new InvalidArgumentException('flatten(): El intervalo debe cumplir to > from (to exclusivo).');
        }

        // Pre-filtra rangos que intersecten [from, to)
        $candidates = [];
        foreach ($rangos as $r) {
            $data = $this->leerRango($rangeAccessor($r));

            // Sin fechas o con un precio que no es un número, el rango no cuenta: antes
            // `(float) 'abc'` era 0.00 y el día salía gratis a la venta.
            if ($data === null) {
                continue;
            }

            $rs = $this->toDay($data['start']);
            $re = $this->toDay($data['end']);

            // Normalizamos a convención [start, end)
            if ($re <= $rs) {
                continue;
            }

            if ($re <= $fromDay || $rs >= $toDay) {
                continue; // no intersecta
            }

            // Asegura que exista un sourceId estable (id o hash).
            $data['sourceId'] = $this->computeSourceId($data['crudo'], $rs, $re);
            unset($data['crudo']);

            $candidates[] = [
                'raw' => $r,
                'data' => $data,
                'start' => $rs,
                'end' => $re,
            ];
        }

        if ($priorityComparator === null) {
            // Default mejorado:
            // important desc, weight desc, duración asc (más corto gana), id desc.
            $priorityComparator = static function (array $a, array $b): int {
                /** @var Candidato $a */
                /** @var Candidato $b */
                // 1) important: true gana
                $ai = $a['data']['important'] ? 1 : 0;
                $bi = $b['data']['important'] ? 1 : 0;
                if ($ai !== $bi) {
                    return $bi <=> $ai; // (a mejor si es más importante)
                }

                // 2) weight: más grande gana
                $ap = $a['data']['weight'];
                $bp = $b['data']['weight'];
                if ($ap !== $bp) {
                    return $bp <=> $ap; // (a mejor si tiene más prioridad)
                }

                // 3) duración: más corto gana (end - start en días)
                // Nota: aquí a/b ya vienen normalizados a start/end día en $cand['start']/$cand['end'],
                // pero por simplicidad lo leemos de ahí:
                $aDays = (int) (($a['end']->getTimestamp() - $a['start']->getTimestamp()) / 86400);
                $bDays = (int) (($b['end']->getTimestamp() - $b['start']->getTimestamp()) / 86400);

                // Si por alguna razón da 0 o negativo, lo empujamos al fondo.
                if ($aDays <= 0) $aDays = PHP_INT_MAX;
                if ($bDays <= 0) $bDays = PHP_INT_MAX;

                if ($aDays !== $bDays) {
                    return $aDays <=> $bDays; // (a mejor si es más corto)
                }

                // 4) id desc (si existe)
                return $b['data']['id'] <=> $a['data']['id']; // (a mejor si tiene id mayor)
            };
        }

        $daily = [];

        $period = new DatePeriod($fromDay, new DateInterval('P1D'), $toDay); // to exclusivo
        foreach ($period as $day) {
            $best = null;

            foreach ($candidates as $cand) {
                if ($day < $cand['start'] || $day >= $cand['end']) {
                    continue;
                }

                if ($best === null) {
                    $best = $cand;
                    continue;
                }

                // comparator: <0 => cand antes (mejor)
                $cmp = $priorityComparator($cand, $best);
                if ($cmp < 0) {
                    $best = $cand;
                }
            }

            // Si no hay rango ganador y tenemos fallback, rellenamos.
            if ($best === null && $fallbackProvider !== null) {
                $fb = $fallbackProvider($day);
                $fbPrecio = is_array($fb) ? self::decimal($fb['price'] ?? null) : null;
                if (is_array($fb) && $fbPrecio !== null) {
                    $fbMinStay = self::entero($fb['minStay'] ?? null) ?? 2;
                    if ($fbMinStay <= 0) {
                        $fbMinStay = 2;
                    }

                    $fbSourceId = isset($fb['sourceId']) && is_string($fb['sourceId']) && $fb['sourceId'] !== ''
                        ? $fb['sourceId']
                        : 'base';

                    $daily[$day->format('Y-m-d')] = [
                        'price' => $fbPrecio,
                        'minStay' => $fbMinStay,
                        'currency' => is_string($fb['currency'] ?? null) ? $fb['currency'] : null,
                        'sourceId' => $fbSourceId,
                    ];
                }

                continue;
            }

            if ($best !== null) {
                $data = $best['data'];

                $minStay = $data['minStay'] ?? 2;
                if ($minStay <= 0) {
                    $minStay = 2;
                }

                $daily[$day->format('Y-m-d')] = [
                    'price' => $data['price'],
                    'minStay' => $minStay,
                    'currency' => $data['currency'],
                    'sourceId' => $data['sourceId'],
                ];
            }
        }

        return $daily;
    }

    /**
     * Lo que dio el `rangeAccessor`, con tipo. Cada campo con la MISMA lectura que tenía donde se
     * usaba —el comparador, el día ganador—, para que el ganador de cada día no cambie:
     *
     * - `important`: `!empty()`. `weight` y el `id` para desempatar: `is_numeric` → `(int)`, o 0.
     * - `minStay` y `currency`: el `(int)`/`(string)` de antes sobre un escalar; ausente es `null`.
     * - `null` si faltan las fechas o el precio no es un número: el rango no cuenta.
     *
     * `crudo` viaja sólo hasta `computeSourceId()`, que hashea lo que llegó y no lo leído.
     *
     * @return array{
     *     start: DateTimeInterface,
     *     end: DateTimeInterface,
     *     price: float,
     *     minStay: ?int,
     *     currency: ?string,
     *     important: bool,
     *     weight: int,
     *     id: int,
     *     sourceId: string,
     *     crudo: array<string, mixed>,
     * }|null
     */
    private function leerRango(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        $inicio = $data['start'] ?? null;
        $fin = $data['end'] ?? null;
        $precio = self::decimal($data['price'] ?? null);

        if (!$inicio instanceof DateTimeInterface || !$fin instanceof DateTimeInterface || $precio === null) {
            return null;
        }

        $minStay = $data['minStay'] ?? null;
        $moneda = $data['currency'] ?? null;

        return [
            'start' => $inicio,
            'end' => $fin,
            'price' => $precio,
            'minStay' => is_scalar($minStay) ? (int) $minStay : null,
            'currency' => is_scalar($moneda) ? (string) $moneda : null,
            'important' => !empty($data['important']),
            'weight' => self::entero($data['weight'] ?? null) ?? 0,
            'id' => self::entero($data['id'] ?? null) ?? 0,
            'sourceId' => '',
            'crudo' => Lee::objeto($data),
        ];
    }

    /**
     * Un importe de tarifa: número, o número en texto (un DECIMAL llega como «120.50»). Lo que no
     * sea un número no es un importe.
     */
    private static function decimal(mixed $valor): ?float
    {
        return is_numeric($valor) ? (float) $valor : null;
    }

    /** Estancia mínima o peso: mismo criterio, truncando como hacía `(int)`. */
    private static function entero(mixed $valor): ?int
    {
        return is_numeric($valor) ? (int) $valor : null;
    }

    private function toDay(DateTimeInterface $dt): DateTimeImmutable
    {
        $imm = ($dt instanceof DateTimeImmutable) ? $dt : DateTimeImmutable::createFromInterface($dt);

        return $imm->setTime(0, 0, 0);
    }

    /**
     * Calcula un identificador estable del rango:
     * - Si existe data['id'] => se usa.
     * - Si no existe => hash sha1 sobre campos normalizados + start/end (Y-m-d).
     *
     * @param array<string,mixed> $data
     */
    private function computeSourceId(array $data, DateTimeImmutable $startDay, DateTimeImmutable $endDay): string
    {
        $idRaw = $data['id'] ?? null;
        if (is_scalar($idRaw) && (string) $idRaw !== '') {
            return 'id:' . (string) $idRaw;
        }

        $minStay = self::entero($data['minStay'] ?? null) ?? 2;
        if ($minStay <= 0) {
            $minStay = 2;
        }

        // ⚠️ El hash tiene que salir IGUAL que antes para los mismos datos: `(string) (float)` de
        // un número en texto es lo que era, y un rango con otro `sourceId` es un rango distinto.
        $payload = [
            'start' => $startDay->format('Y-m-d'),
            'end' => $endDay->format('Y-m-d'),
            'price' => (string) (self::decimal($data['price'] ?? null) ?? 0.0),
            'minStay' => (string) $minStay,
            'currency' => is_string($data['currency'] ?? null) ? $data['currency'] : '',
            'important' => !empty($data['important']) ? '1' : '0',
            'weight' => (string) (self::entero($data['weight'] ?? null) ?? 0),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            // ultra-fallback, debería ser rarísimo
            $json = implode('|', $payload);
        }

        return 'h:' . sha1($json);
    }
}