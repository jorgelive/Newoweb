<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use App\Pms\Service\Tarifa\Engine\TarifaPricingEngine;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Provider para calendarios basados en rangos de tarifa que necesitan "compactación"
 * (usa TarifaPricingEngine: flatten + compressor).
 *
 * Convención BLINDADA:
 * - NO se transforma el "día" (sin +1 / -1).
 * - rangeAccessor entrega start/end tal cual la BD (normalizado a 00:00 solo por seguridad).
 * - SOLO para UI se aplican horas con eventTime.
 *
 * Config esperada:
 * provider: tarifa_compressed_ranges
 * entity: ...
 * fields: unit/unitId/unitTitle/start/end/price/...
 * eventTime:
 * start: '12:00:00'
 * end: '11:59:59'
 */
final class TarifaCompressedRangesCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly TarifaPricingEngine $pricingEngine,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $router,
    ) {}

    public function supports(array $config): bool
    {
        return (($config['provider'] ?? null) === 'tarifa_compressed_ranges')
            && isset($config['entity'])
            && is_string($config['entity']);
    }

    /**
     * @return list<CalendarEventDto>
     */
    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $this->assertConfig($config);

        // 🔥 1. CAPTURA DEL PASAPORTE (TOKEN BASE64)
        $runtimeReturnTo = $config['runtime_returnTo'] ?? null;

        $entities = $this->fetchEntities($from, $to, $config);

        // 1) Agrupar por resource (unidad)
        $groups = $this->groupByUnit($entities, $config);

        // UI hours (solo visual)
        $eventTime = (isset($config['eventTime']) && is_array($config['eventTime'])) ? $config['eventTime'] : [];
        [$sh, $sm, $ss] = $this->parseHms((string)($eventTime['start'] ?? '12:00:00'), [12, 0, 0]);
        [$eh, $em, $es] = $this->parseHms((string)($eventTime['end'] ?? '11:59:59'), [11, 59, 59]);

        // 2) Para cada unidad: engine => rangos compactados
        $events = [];
        foreach ($groups as $unitKey => $group) {
            $unitObj = $group['unit'];
            $ranges = $group['ranges'];
            $fields = (array)($config['fields'] ?? []);

            $logicalRanges = $this->pricingEngine->buildLogicalRangesForInterval(
                rangos: $ranges,
                from: $from,
                to: $to,
                rangeAccessor: function (object $r) use ($config): array {
                    return $this->rangeAccessor($r, $config);
                },
                priorityComparator: null // default flattener (important/weight/id)
            );

            foreach ($logicalRanges as $idx => $lr) {
                $price = $lr->getPrice();
                $minStay = $lr->getMinStay();
                $currency = $lr->getCurrency();

                $title = number_format($price, 2, '.', '') . ' | ' . $minStay;

                $tooltip = [
                    $this->scalarToStringOrNull($this->resolvePath($unitObj, (string)($config['fields']['unitTitle'] ?? 'nombre')))
                    ?? (method_exists($unitObj, '__toString') ? (string)$unitObj : ('Unidad ' . (string)$unitKey)),
                    'Precio: ' . number_format($price, 2, '.', ''),
                    'MinStay: ' . $minStay,
                    $currency,
                ];

                // URLs edit/show (Oweb/EasyAdmin)
                $urledit = null;
                $urlshow = null;

                $urlCfg = null;
                if (isset($config['event']['url']) && is_array($config['event']['url'])) {
                    $urlCfg = $config['event']['url'];
                } elseif (isset($config['url']) && is_array($config['url'])) {
                    $urlCfg = $config['url'];
                }

                $urlId = null;
                if ($urlCfg !== null) {
                    if (method_exists($lr, 'getSourceId')) {
                        $sid = $lr->getSourceId();
                        if (is_string($sid) && str_starts_with($sid, 'id:')) {
                            $candidate = substr($sid, 3);
                            if ($candidate !== '') {
                                $urlId = $candidate;
                            }
                        }
                    }

                    if ($urlId === null && !empty($ranges)) {
                        $idPath = (string)($fields['id'] ?? 'id');
                        $firstId = $this->resolvePath($ranges[0], $idPath);
                        if (is_scalar($firstId) && (string)$firstId !== '') {
                            $urlId = (string)$firstId;
                        }
                    }

                    if ($urlId !== null) {
                        // --- URL SHOW ---
                        if (isset($urlCfg['show']) && is_array($urlCfg['show'])) {
                            $show = $urlCfg['show'];
                            if (isset($show['role']) && $this->authorizationChecker->isGranted((string)$show['role'])) {
                                $params = [];
                                if (isset($show['params']) && is_array($show['params'])) {
                                    $params = $show['params'];
                                }

                                $params = array_merge($params, ['entityId' => $urlId]);

                                // 🔥 2. INYECCIÓN returnTo
                                if (!empty($runtimeReturnTo)) {
                                    $params['returnTo'] = $runtimeReturnTo;
                                }

                                $urlshow = $this->router->generate((string)$show['route'], $params);
                            }
                        }

                        // --- URL EDIT ---
                        if (isset($urlCfg['edit']) && is_array($urlCfg['edit'])) {
                            $edit = $urlCfg['edit'];
                            if (isset($edit['role']) && $this->authorizationChecker->isGranted((string)$edit['role'])) {
                                $params = [];
                                if (isset($edit['params']) && is_array($edit['params'])) {
                                    $params = $edit['params'];
                                }
                                $params = array_merge($params, ['entityId' => $urlId]);

                                // 🔥 2. INYECCIÓN returnTo
                                if (!empty($runtimeReturnTo)) {
                                    $params['returnTo'] = $runtimeReturnTo;
                                }

                                $urledit = $this->router->generate((string)$edit['route'], $params);
                            }
                        }
                    }
                }

                // id "estable": unitKey + index
                $id = (string)$unitKey . '-' . $idx;

                // UI hours (solo visual, sin tocar días)
                $eventStart = $lr->getStart()->setTime($sh, $sm, $ss);
                $eventEnd = $lr->getEnd()->setTime($eh, $em, $es);

                // Safety mínima (sin inventar días)
                if ($eventEnd <= $eventStart) {
                    $eventEnd = $lr->getEnd();
                }

                $events[] = new CalendarEventDto(
                    id: $id,
                    title: $title,
                    start: $eventStart,
                    end: $eventEnd,
                    resourceId: $unitKey,
                    urledit: $urledit,
                    urlshow: $urlshow,
                    tooltip: $tooltip,
                );
            }
        }

        return $events;
    }

    /**
     * @return list<CalendarResourceDto>
     */
    public function getResources(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $this->assertConfig($config);

        $entities = $this->fetchEntities($from, $to, $config);
        $groups = $this->groupByUnit($entities, $config);

        $out = [];
        foreach ($groups as $unitKey => $group) {
            $unitObj = $group['unit'];

            $titlePath = (string)($config['fields']['unitTitle'] ?? 'nombre');
            $titleVal = $this->resolvePath($unitObj, $titlePath);
            $title = $this->scalarToStringOrNull($titleVal);

            if ($title === null || $title === '') {
                $title = method_exists($unitObj, '__toString') ? (string)$unitObj : ('Unidad ' . (string)$unitKey);
            }

            $out[] = new CalendarResourceDto(id: $unitKey, title: $title);
        }

        usort($out, static fn (CalendarResourceDto $a, CalendarResourceDto $b): int => (string)$a->id <=> (string)$b->id);

        return $out;
    }

    /**
     * @return list<object>
     */
    private function fetchEntities(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $entityClass = (string) $config['entity'];

        $manager = $this->managerRegistry->getManagerForClass($entityClass);
        if (!$manager instanceof ObjectManager) {
            throw new HttpException(500, sprintf('No hay ObjectManager para %s', $entityClass));
        }

        $repo = $manager->getRepository($entityClass);
        if (!$repo instanceof ObjectRepository) {
            throw new HttpException(500, sprintf('No hay repository para %s', $entityClass));
        }

        $fields = (array)($config['fields'] ?? []);
        $filters = (array)($config['filters'] ?? []);

        $unitField = (string)$fields['unit'];
        $startField = (string)$fields['start'];
        $endField = (string)$fields['end'];

        /** @var QueryBuilder $qb */
        $qb = $repo->createQueryBuilder('r');

        // Solape: start <= to AND end >= from (sin reinterpretar inclusive/exclusive)
        $qb
            ->andWhere(sprintf('r.%s <= :to', $startField))
            ->andWhere(sprintf('r.%s >= :from', $endField))
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if (!empty($filters['activeOnly']) && isset($fields['active'])) {
            $activeField = (string)$fields['active'];
            $qb->andWhere(sprintf('r.%s = :active', $activeField))
                ->setParameter('active', true);
        } elseif (!empty($filters['activeOnly']) && !isset($fields['active'])) {
            throw new HttpException(500, 'filters.activeOnly=true requiere fields.active');
        }

        $qb->addOrderBy(sprintf('r.%s', $unitField), 'ASC')
            ->addOrderBy(sprintf('r.%s', $startField), 'ASC');

        /** @var list<object> */
        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<object> $entities
     * @return array<string|int, array{unit:object, ranges:list<object>}>
     */
    private function groupByUnit(array $entities, array $config): array
    {
        $fields = (array)$config['fields'];

        $unitPath = (string)$fields['unit'];
        $unitIdPath = (string)($fields['unitId'] ?? ($unitPath . '.id'));

        $groups = [];

        foreach ($entities as $e) {
            $unitObj = $this->resolvePath($e, $unitPath);
            if (!is_object($unitObj)) {
                continue;
            }

            $unitId = $this->resolvePath($e, $unitIdPath);
            if (!is_scalar($unitId) || $unitId === '') {
                $unitId = spl_object_id($unitObj);
            }

            $key = is_int($unitId) ? $unitId : (string)$unitId;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'unit' => $unitObj,
                    'ranges' => [],
                ];
            }

            $groups[$key]['ranges'][] = $e;
        }

        return $groups;
    }

    /**
     * Range accessor para TarifaPricingEngine (sin inclusive/exclusive shifts).
     *
     * @return array{
     * start:DateTimeInterface,
     * end:DateTimeInterface,
     * price:float,
     * minStay?:int|null,
     * currency?:string|null,
     * important?:bool,
     * weight?:int,
     * id?:int|string
     * }
     */
    private function rangeAccessor(object $r, array $config): array
    {
        $fields = (array)$config['fields'];

        $start = $this->resolvePath($r, (string)$fields['start']);
        $end = $this->resolvePath($r, (string)$fields['end']);

        if (!$start instanceof DateTimeInterface || !$end instanceof DateTimeInterface) {
            throw new HttpException(500, 'Rango inválido: start/end deben ser DateTimeInterface');
        }

        // Normaliza a día (00:00) solo para estabilidad interna (sin +1 / -1)
        $startDay = $this->toDay($start);
        $endDay = $this->toDay($end);

        $priceVal = $this->resolvePath($r, (string)$fields['price']);
        $price = (float) $priceVal;

        $minStay = null;
        if (isset($fields['minStay'])) {
            $ms = $this->resolvePath($r, (string)$fields['minStay']);
            $minStay = $ms !== null ? (int)$ms : null;
        }

        $currency = null;
        if (isset($fields['currency'])) {
            $c = $this->resolvePath($r, (string)$fields['currency']);
            $currency = $this->scalarToStringOrNull($c);
        }

        $important = null;
        if (isset($fields['important'])) {
            $v = $this->resolvePath($r, (string)$fields['important']);
            $important = (bool)$v;
        }

        $weight = null;
        if (isset($fields['weight'])) {
            $w = $this->resolvePath($r, (string)$fields['weight']);
            $weight = $w !== null ? (int)$w : null;
        }

        $id = null;
        if (isset($fields['id'])) {
            $id = $this->resolvePath($r, (string)$fields['id']);
        }

        return [
            'start' => $startDay,
            'end' => $endDay,
            'price' => $price,
            'minStay' => $minStay,
            'currency' => $currency,
            'important' => $important,
            'weight' => $weight,
            'id' => $id,
        ];
    }

    /**
     * @param array{0:int,1:int,2:int} $default
     * @return array{0:int,1:int,2:int}
     */
    private function parseHms(string $time, array $default): array
    {
        $time = trim($time);
        if ($time === '') {
            return $default;
        }

        $parts = explode(':', $time);
        if (count($parts) < 2 || count($parts) > 3) {
            return $default;
        }

        $h = (int)($parts[0] ?? $default[0]);
        $m = (int)($parts[1] ?? $default[1]);
        $s = (int)($parts[2] ?? $default[2]);

        if ($h < 0 || $h > 23) { $h = $default[0]; }
        if ($m < 0 || $m > 59) { $m = $default[1]; }
        if ($s < 0 || $s > 59) { $s = $default[2]; }

        return [$h, $m, $s];
    }

    private function assertConfig(array $config): void
    {
        if (empty($config['entity']) || !is_string($config['entity'])) {
            throw new HttpException(500, 'tarifa_compressed_ranges requiere "entity"');
        }

        $fields = $config['fields'] ?? null;
        if (!is_array($fields)) {
            throw new HttpException(500, 'tarifa_compressed_ranges requiere "fields" (array).');
        }

        foreach (['unit', 'start', 'end', 'price'] as $k) {
            if (empty($fields[$k]) || !is_string($fields[$k])) {
                throw new HttpException(500, sprintf('tarifa_compressed_ranges requiere fields.%s', $k));
            }
        }

        if (isset($config['filters']) && !is_array($config['filters'])) {
            throw new HttpException(500, 'filters debe ser array si existe.');
        }
    }

    private function toDay(DateTimeInterface $dt): DateTimeImmutable
    {
        $imm = ($dt instanceof DateTimeImmutable) ? $dt : DateTimeImmutable::createFromInterface($dt);
        return $imm->setTime(0, 0, 0);
    }

    private function resolvePath(mixed $base, string $path): mixed
    {
        $parts = str_contains($path, '.') ? explode('.', $path) : [$path];
        $val = $base;

        foreach ($parts as $part) {
            $getter = 'get' . ucfirst($part);
            $isser = 'is' . ucfirst($part);

            if (!is_object($val)) {
                return null;
            }

            if (method_exists($val, $getter)) {
                $val = $val->{$getter}();
                continue;
            }

            if (method_exists($val, $isser)) {
                $val = $val->{$isser}();
                continue;
            }

            return null;
        }

        return $val;
    }

    private function scalarToStringOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_scalar($v)) {
            $s = (string) $v;
            return $s === '' ? null : $s;
        }
        if (is_object($v) && method_exists($v, '__toString')) {
            $s = (string) $v;
            return $s === '' ? null : $s;
        }
        return null;
    }
}