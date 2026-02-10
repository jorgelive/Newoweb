<?php
declare(strict_types=1);

namespace App\Pms\Service\Tarifa\Engine;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeInterface;
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
     * @param callable|null $priorityComparator fn($a,$b): int
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
            $data = $rangeAccessor($r);

            if (!isset($data['start'], $data['end'], $data['price'])) {
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
            $data['sourceId'] = $this->computeSourceId($data, $rs, $re);

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
                // 1) important: true gana
                $ai = !empty($a['data']['important']) ? 1 : 0;
                $bi = !empty($b['data']['important']) ? 1 : 0;
                if ($ai !== $bi) {
                    return $bi <=> $ai; // (a mejor si es más importante)
                }

                // 2) weight: más grande gana
                $ap = (int) ($a['data']['weight'] ?? 0);
                $bp = (int) ($b['data']['weight'] ?? 0);
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
                $aidRaw = $a['data']['id'] ?? 0;
                $bidRaw = $b['data']['id'] ?? 0;

                $aid = is_numeric($aidRaw) ? (int) $aidRaw : 0;
                $bid = is_numeric($bidRaw) ? (int) $bidRaw : 0;

                return $bid <=> $aid; // (a mejor si tiene id mayor)
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
                if (is_array($fb) && isset($fb['price'])) {
                    $fbMinStay = isset($fb['minStay']) ? (int) $fb['minStay'] : 2;
                    if ($fbMinStay <= 0) {
                        $fbMinStay = 2;
                    }

                    $fbSourceId = isset($fb['sourceId']) && is_string($fb['sourceId']) && $fb['sourceId'] !== ''
                        ? $fb['sourceId']
                        : 'base';

                    $daily[$day->format('Y-m-d')] = [
                        'price' => (float) ($fb['price'] ?? 0),
                        'minStay' => $fbMinStay,
                        'currency' => isset($fb['currency']) ? (string) $fb['currency'] : null,
                        'sourceId' => (string) $fbSourceId,
                    ];
                }

                continue;
            }

            if ($best !== null) {
                $data = $best['data'];

                $minStay = isset($data['minStay']) ? (int) $data['minStay'] : 2;
                if ($minStay <= 0) {
                    $minStay = 2;
                }

                $daily[$day->format('Y-m-d')] = [
                    'price' => (float) ($data['price'] ?? 0),
                    'minStay' => $minStay,
                    'currency' => isset($data['currency']) ? (string) $data['currency'] : null,
                    'sourceId' => (string) $data['sourceId'],
                ];
            }
        }

        return $daily;
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

        $minStay = isset($data['minStay']) ? (int) $data['minStay'] : 2;
        if ($minStay <= 0) {
            $minStay = 2;
        }

        $payload = [
            'start' => $startDay->format('Y-m-d'),
            'end' => $endDay->format('Y-m-d'),
            'price' => (string) (float) ($data['price'] ?? 0),
            'minStay' => (string) $minStay,
            'currency' => isset($data['currency']) ? (string) $data['currency'] : '',
            'important' => !empty($data['important']) ? '1' : '0',
            'weight' => (string) (int) ($data['weight'] ?? 0),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            // ultra-fallback, debería ser rarísimo
            $json = implode('|', $payload);
        }

        return 'h:' . sha1($json);
    }
}