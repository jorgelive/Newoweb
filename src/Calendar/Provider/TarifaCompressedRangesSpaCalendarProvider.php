<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Config\CamposDeTarifa;
use App\Calendar\Config\ConfiguracionCalendario;
use Doctrine\ORM\EntityRepository;
use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use App\Calendar\Service\CalendarResourceCatalog;
use App\Pms\Service\Tarifa\Engine\TarifaPricingEngine;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Variante de TarifaCompressedRangesCalendarProvider para consumidores API/SPA (Vue).
 *
 * Diferencias respecto al provider legacy (EasyAdmin):
 * - No genera urledit/urlshow (no depende del router de Symfony ni de rutas EasyAdmin).
 * - No depende de runtime_returnTo / current_page.
 * - No hace chequeos de ROLE_* para decidir si expone un link.
 * - Expone "context" + id crudo (del rango de tarifa que originó el segmento compactado)
 *   en extendedProps para que el frontend arme su propia navegación.
 *
 * Convención BLINDADA (heredada del provider legacy):
 * - NO se transforma el "día" (sin +1 / -1).
 * - rangeAccessor entrega start/end tal cual la BD (normalizado a 00:00 solo por seguridad).
 * - SOLO para UI se aplican horas con eventTime.
 *
 * @phpstan-type Validada array{entidad: string, campos: CamposDeTarifa, unit: string, start: string, end: string, price: string}
 */
final class TarifaCompressedRangesSpaCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly TarifaPricingEngine $pricingEngine,
        private readonly CalendarResourceCatalog $resourceCatalog,
    ) {}

    public function supports(ConfiguracionCalendario $config): bool
    {
        return $config->provider === 'tarifa_compressed_ranges_spa' && $config->entidad !== null;
    }

    /**
     * @return list<CalendarEventDto>
     */
    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
    {
        $valida = $this->assertConfig($config);
        $fields = $valida['campos'];

        $entities = $this->fetchEntities($from, $to, $config, $valida);

        // 1) Agrupar por resource (unidad)
        $groups = $this->groupByUnit($entities, $valida);

        // UI hours (solo visual)
        [$sh, $sm, $ss] = $this->parseHms($config->horas->inicio, [12, 0, 0]);
        [$eh, $em, $es] = $this->parseHms($config->horas->fin, [11, 59, 59]);

        // Mismo contrato de título que TarifaRangesSpaCalendarProvider: lo manda
        // el YAML por placeholders, para que ambas vistas del calendario de
        // tarifas se vean igual sin tocar dos veces el código.
        $titleFormat = $config->evento->formatoTitulo ?? '{currency} {price} · {minStay}N';
        $includeCurrency = $config->evento->incluirMoneda;

        // 2) Para cada unidad: engine => rangos compactados
        $events = [];
        foreach ($groups as $unitKey => $group) {
            $ranges = $group['ranges'];

            $logicalRanges = $this->pricingEngine->buildLogicalRangesForInterval(
                rangos: $ranges,
                from: $from,
                to: $to,
                rangeAccessor: function (object $r) use ($valida): array {
                    return $this->rangeAccessor($r, $valida);
                },
                priorityComparator: null
            );

            foreach ($logicalRanges as $idx => $lr) {
                $price = $lr->getPrice();
                $minStay = $lr->getMinStay();
                $currency = $lr->getCurrency();

                $netoBooking = $price * 0.80;
                $netoAirbnb = $price * 0.70;

                $title = $this->formatTitle($titleFormat, $price, (int) $minStay, $includeCurrency ? $currency : null);

                $unitObj = $group['unit'];
                $tooltip = [
                    $this->scalarToStringOrNull($this->resolvePath($unitObj, $fields->unitTitle ?? 'nombre'))
                    ?? (method_exists($unitObj, '__toString') ? (string)$unitObj : ('Unidad ' . (string)$unitKey)),
                    'Precio Base: ' . number_format($price, 2, '.', ''),
                    'Neto al 20%: ' . number_format($netoBooking, 2, '.', ''),
                    'Neto al 30%: ' . number_format($netoAirbnb, 2, '.', ''),
                    'MinStay: ' . $minStay,
                    $currency,
                ];
                // La moneda puede faltar, y una línea vacía no es una línea del tooltip.
                $tooltip = array_filter($tooltip, static fn (?string $linea): bool => $linea !== null);

                // Id "real" del rango de tarifa que origina este segmento (mismo criterio que el legacy)
                $sourceId = null;
                // `$lr` es un TarifaLogicalRangeDto y `getSourceId()` está en su firma: el
                // method_exists() que había aquí no comprobaba nada.
                $sid = $lr->getSourceId();
                if (is_string($sid) && str_starts_with($sid, 'id:')) {
                    $candidate = substr($sid, 3);
                    if ($candidate !== '') {
                        $sourceId = $candidate;
                    }
                }

                if ($sourceId === null && !empty($ranges)) {
                    // Mismo motivo que en rangeAccessor(): el id es un Uuid, no un
                    // escalar, y el `is_scalar()` de antes dejaba esto en null.
                    $idPath = $fields->id ?? 'id';
                    $sourceId = $this->scalarToStringOrNull($this->resolvePath($ranges[0], $idPath));
                }

                // id "estable" del evento sintético: unitKey + index
                $id = (string)$unitKey . '-' . $idx;

                // UI hours (solo visual, sin tocar días)
                $eventStart = $lr->getStart()->setTime($sh, $sm, $ss);
                $eventEnd = $lr->getEnd()->setTime($eh, $em, $es);

                if ($eventEnd <= $eventStart) {
                    $eventEnd = $lr->getEnd();
                }

                $events[] = new CalendarEventDto(
                    id: $id,
                    title: $title,
                    start: $eventStart,
                    end: $eventEnd,
                    resourceId: $unitKey,
                    tooltip: $tooltip,
                    // Datos crudos del rango GANADOR de este tramo. Además de la
                    // UI del calendario, los consume el calendario de Reservas
                    // para mostrar "el precio de este día" al hacer clic en un
                    // hueco libre (ver ReservasView.vue).
                    extendedProps: [
                        'context' => 'tarifaRangoCompactado',
                        'tarifaRangoId' => $sourceId,
                        'precio' => number_format($price, 2, '.', ''),
                        'minStay' => (int) $minStay,
                        'moneda' => $currency,
                    ],
                );
            }
        }

        return $events;
    }

    /**
     * @return list<CalendarResourceDto>
     */
    public function getResources(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
    {
        $valida = $this->assertConfig($config);

        $entities = $this->fetchEntities($from, $to, $config, $valida);
        $groups = $this->groupByUnit($entities, $valida);

        $out = [];
        foreach ($groups as $unitKey => $group) {
            $unitObj = $group['unit'];

            $titlePath = $valida['campos']->unitTitle ?? 'nombre';
            $titleVal = $this->resolvePath($unitObj, $titlePath);
            $title = $this->scalarToStringOrNull($titleVal);

            if ($title === null || $title === '') {
                $title = method_exists($unitObj, '__toString') ? (string)$unitObj : ('Unidad ' . (string)$unitKey);
            }

            $out[] = new CalendarResourceDto(id: (string) $unitKey, title: $title);
        }

        // Las unidades sin rangos de tarifa en el intervalo desaparecían de la
        // grilla: el catálogo las repone (ver resources.showAll en el YAML) y
        // se encarga del orden natural + índice `orden`.
        return $this->resourceCatalog->merge(
            $out,
            $config->recursos,
            $this->resourceCatalog->targetClassOf($valida['entidad'], $valida['unit'])
        );
    }

    /**
     * @param Validada $valida
     * @return list<object>
     */
    private function fetchEntities(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config, array $valida): array
    {
        $entityClass = $valida['entidad'];

        // `getManagerForClass()` pide `class-string`. Una clase que no existe reventaba DENTRO de
        // Doctrine con un ReflectionException; ahora es el mismo 500 con el mensaje de al lado.
        if (!class_exists($entityClass)) {
            throw new HttpException(500, sprintf('No hay ObjectManager para %s', $entityClass));
        }

        $manager = $this->managerRegistry->getManagerForClass($entityClass);
        if (!$manager instanceof ObjectManager) {
            throw new HttpException(500, sprintf('No hay ObjectManager para %s', $entityClass));
        }

        $repo = $manager->getRepository($entityClass);
        // ⚠️ `EntityRepository` y no `ObjectRepository`: abajo se llama a `createQueryBuilder()`,
        // que sólo existe en el primero.
        if (!$repo instanceof EntityRepository) {
            throw new HttpException(500, sprintf('No hay repository para %s', $entityClass));
        }

        $unitField = $valida['unit'];
        $startField = $valida['start'];
        $endField = $valida['end'];

        $qb = $repo->createQueryBuilder('r');

        // Solape: start <= to AND end >= from (sin reinterpretar inclusive/exclusive)
        $qb
            ->andWhere(sprintf('r.%s <= :to', $startField))
            ->andWhere(sprintf('r.%s >= :from', $endField))
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        // Los compactados NO miran `filters.showInactive` (los sin compactar sí).
        if ($config->filtros->soloActivos) {
            $activeField = $valida['campos']->active
                ?? throw new HttpException(500, 'filters.activeOnly=true requiere fields.active');
            $qb->andWhere(sprintf('r.%s = :active', $activeField))
                ->setParameter('active', true);
        }

        $qb->addOrderBy(sprintf('r.%s', $unitField), 'ASC')
            ->addOrderBy(sprintf('r.%s', $startField), 'ASC');

        /** @var list<object> $resultado */
        $resultado = $qb->getQuery()->getResult();

        return $resultado;
    }

    /**
     * @param list<object> $entities
     * @param Validada $valida
     * @return array<string|int, array{unit: object, ranges: list<object>}> Por unidad: sus rangos
     *         y los datos de cabecera.
     */
    private function groupByUnit(array $entities, array $valida): array
    {
        $unitPath = $valida['unit'];
        $unitIdPath = $valida['campos']->unitId ?? ($unitPath . '.id');

        $groups = [];

        foreach ($entities as $e) {
            $unitObj = $this->resolvePath($e, $unitPath);
            if (!is_object($unitObj)) {
                continue;
            }

            // PmsUnidad::getId() devuelve un Uuid (OBJETO), no un escalar. Con el
            // viejo `is_scalar()` la comprobación fallaba siempre y el id del
            // recurso caía a spl_object_id(): un entero efímero del proceso PHP,
            // distinto en cada request. Eso rompía dos cosas:
            //   1. Los eventos y los recursos se piden en requests SEPARADOS, así
            //      que sólo coincidían por casualidad (mismo orden de hidratación).
            //   2. Ningún otro calendario podía cruzar ese id con una unidad real
            //      (lo necesita ReservasView para el precio del día).
            // scalarToStringOrNull() sí resuelve el Uuid por su __toString().
            $key = $this->scalarToStringOrNull($this->resolvePath($e, $unitIdPath))
                ?? (string) spl_object_id($unitObj);

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
     * @param Validada $valida
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, price: float, minStay: int|null,
     *     currency: string|null, important: bool|null, weight: int|null, id: string|null}
     */
    private function rangeAccessor(object $r, array $valida): array
    {
        $fields = $valida['campos'];

        $start = $this->resolvePath($r, $valida['start']);
        $end = $this->resolvePath($r, $valida['end']);

        if (!$start instanceof DateTimeInterface || !$end instanceof DateTimeInterface) {
            throw new HttpException(500, 'Rango inválido: start/end deben ser DateTimeInterface');
        }

        $startDay = $this->toDay($start);
        $endDay = $this->toDay($end);

        $price = $this->aDecimal($this->resolvePath($r, $valida['price']));

        $minStay = null;
        if ($fields->minStay !== null) {
            $ms = $this->resolvePath($r, $fields->minStay);
            $minStay = is_scalar($ms) ? (int)$ms : null;
        }

        $currency = null;
        if ($fields->currency !== null) {
            $c = $this->resolvePath($r, $fields->currency);
            $currency = $this->scalarToStringOrNull($c);
        }

        $important = null;
        if ($fields->important !== null) {
            $v = $this->resolvePath($r, $fields->important);
            $important = (bool)$v;
        }

        $weight = null;
        if ($fields->weight !== null) {
            $w = $this->resolvePath($r, $fields->weight);
            $weight = is_scalar($w) ? (int)$w : null;
        }

        $id = null;
        if ($fields->id !== null) {
            // Se entrega como STRING a propósito: TarifaDailyPriceFlattener::
            // computeSourceId() descarta lo que no sea escalar y, con el Uuid
            // crudo, generaba un sourceId de tipo 'hash:...' en vez de 'id:<uuid>'.
            // Resultado: getEvents() no podía recuperar el id del rango ganador y
            // extendedProps.tarifaRangoId salía null (segmento no clicable).
            $id = $this->scalarToStringOrNull($this->resolvePath($r, $fields->id));
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
     * Arma el título del tramo compactado según el `event.titleFormat` del YAML.
     * Espejo exacto de TarifaRangesSpaCalendarProvider::formatTitle(): los netos
     * al 20%/30% siguen disponibles como placeholders, pero por defecto viven
     * solo en el tooltip para no saturar la barra.
     *
     * Placeholders: {price} {currency} {minStay} {neto20} {neto30}
     */
    private function formatTitle(string $format, float $price, int $minStay, ?string $currency): string
    {
        $titulo = strtr($format, [
            '{price}'    => number_format($price, 2, '.', ''),
            '{currency}' => $currency ?? '',
            '{minStay}'  => (string) $minStay,
            '{neto20}'   => number_format($price * 0.80, 2, '.', ''),
            '{neto30}'   => number_format($price * 0.70, 2, '.', ''),
        ]);

        return trim(preg_replace('/\s+/', ' ', $titulo) ?? $titulo);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $default
     * @return array{0: int, 1: int, 2: int}
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

        // Tras la guarda de arriba, 0 y 1 existen seguro; el 2 no.
        $h = (int) $parts[0];
        $m = (int) $parts[1];
        $s = (int)($parts[2] ?? $default[2]);

        if ($h < 0 || $h > 23) { $h = $default[0]; }
        if ($m < 0 || $m > 59) { $m = $default[1]; }
        if ($s < 0 || $s > 59) { $s = $default[2]; }

        return [$h, $m, $s];
    }

    /**
     * Lo obligatorio de la configuración, ya comprobado. Mismos mensajes y mismo orden que antes.
     *
     * @return Validada
     */
    private function assertConfig(ConfiguracionCalendario $config): array
    {
        $entidad = $config->entidad;
        if ($entidad === null || $entidad === '') {
            throw new HttpException(500, 'tarifa_compressed_ranges_spa requiere "entity"');
        }

        $campos = $config->campos ?? throw new HttpException(500, 'tarifa_compressed_ranges_spa requiere "fields" (array).');

        $valida = [
            'entidad' => $entidad,
            'campos' => $campos,
            'unit' => $campos->unit ?? throw new HttpException(500, 'tarifa_compressed_ranges_spa requiere fields.unit'),
            'start' => $campos->start ?? throw new HttpException(500, 'tarifa_compressed_ranges_spa requiere fields.start'),
            'end' => $campos->end ?? throw new HttpException(500, 'tarifa_compressed_ranges_spa requiere fields.end'),
            'price' => $campos->price ?? throw new HttpException(500, 'tarifa_compressed_ranges_spa requiere fields.price'),
        ];

        if ($config->filtros->noEsMapa) {
            throw new HttpException(500, 'filters debe ser array si existe.');
        }

        return $valida;
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

    /**
     * El precio de la entidad (un `decimal`, que Doctrine entrega como texto). Mismo resultado que
     * el `(float)` de antes para todo escalar, y `null` es 0; lo que no sea escalar también es 0,
     * en vez de un warning.
     */
    private function aDecimal(mixed $valor): float
    {
        return is_scalar($valor) ? (float) $valor : 0.0;
    }
}
