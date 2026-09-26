<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Config\CamposDeTarifa;
use App\Calendar\Config\ConfiguracionCalendario;
use App\Calendar\Config\Enlace;
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
 *
 * @phpstan-type Validada array{entidad: string, campos: CamposDeTarifa, unit: string, start: string, end: string, price: string}
 */
final class TarifaCompressedRangesCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly TarifaPricingEngine $pricingEngine,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $router,
        private readonly CalendarResourceCatalog $resourceCatalog,
    ) {}

    public function supports(ConfiguracionCalendario $config): bool
    {
        return $config->provider === 'tarifa_compressed_ranges' && $config->entidad !== null;
    }

    /**
     * @return list<CalendarEventDto>
     */
    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
    {
        $valida = $this->assertConfig($config);
        $fields = $valida['campos'];

        // 🔥 1. CAPTURA DEL PASAPORTE (TOKEN BASE64)
        $runtimeReturnTo = $config->retorno;

        $entities = $this->fetchEntities($from, $to, $config, $valida);

        // 1) Agrupar por resource (unidad)
        $groups = $this->groupByUnit($entities, $valida);

        // UI hours (solo visual)
        [$sh, $sm, $ss] = $this->parseHms($config->horas->inicio, [12, 0, 0]);
        [$eh, $em, $es] = $this->parseHms($config->horas->fin, [11, 59, 59]);

        // `event.url` y, si no hay, el `url` de la raíz.
        $urlCfg = $config->evento->enlaces ?? $config->enlacesRaiz;

        // 2) Para cada unidad: engine => rangos compactados
        $events = [];
        foreach ($groups as $unitKey => $group) {
            $unitObj = $group['unit'];
            $ranges = $group['ranges'];

            $logicalRanges = $this->pricingEngine->buildLogicalRangesForInterval(
                rangos: $ranges,
                from: $from,
                to: $to,
                rangeAccessor: function (object $r) use ($valida): array {
                    return $this->rangeAccessor($r, $valida);
                },
                priorityComparator: null // default flattener (important/weight/id)
            );

            foreach ($logicalRanges as $idx => $lr) {
                $price = $lr->getPrice();
                $minStay = $lr->getMinStay();
                $currency = $lr->getCurrency();

                // 🔥 CÁLCULO DE NETOS (Booking 20% y Airbnb 30%)
                $netoBooking = $price * 0.80;
                $netoAirbnb = $price * 0.70;

                // Título formateado: 52.00 (41.60 | 20% - 36.40 | 30%) 2N
                $title = sprintf('%s (%s | 20%% - %s | 30%%) %dN',
                    number_format($price, 2, '.', ''),
                    number_format($netoBooking, 2, '.', ''),
                    number_format($netoAirbnb, 2, '.', ''),
                    $minStay
                );

                // Tooltip extendido con el desglose de netos
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

                // --- Lógica de URLs (Mantenida intacta) ---
                $urledit = null;
                $urlshow = null;

                $urlId = null;
                if ($urlCfg !== null) {
                    // `$lr` es un TarifaLogicalRangeDto y `getSourceId()` está en su firma:
                    // el method_exists() que había aquí no comprobaba nada.
                    $sid = $lr->getSourceId();
                    if (is_string($sid) && str_starts_with($sid, 'id:')) {
                        $candidate = substr($sid, 3);
                        if ($candidate !== '') {
                            $urlId = $candidate;
                        }
                    }

                    if ($urlId === null && !empty($ranges)) {
                        $idPath = $fields->id ?? 'id';
                        $firstId = $this->resolvePath($ranges[0], $idPath);
                        if (is_scalar($firstId) && (string)$firstId !== '') {
                            $urlId = (string)$firstId;
                        }
                    }

                    if ($urlId !== null) {
                        $urlshow = $this->urlDe($urlCfg->enlace('show'), 'show', $urlId, $runtimeReturnTo);
                        $urledit = $this->urlDe($urlCfg->enlace('edit'), 'edit', $urlId, $runtimeReturnTo);
                    }
                }

                // id "estable": unitKey + index
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
                    urledit: $urledit,
                    urlshow: $urlshow,
                    tooltip: $tooltip,
                );
            }
        }

        return $events;
    }

    /**
     * Un enlace del panel, si el rol lo permite. Aquí el rol es obligatorio y los `params` del YAML
     * van DEBAJO del `entityId`: no pueden pisarlo.
     */
    private function urlDe(?Enlace $enlace, string $nombre, string $urlId, ?string $runtimeReturnTo): ?string
    {
        if ($enlace === null || $enlace->rol === null || !$this->authorizationChecker->isGranted($enlace->rol)) {
            return null;
        }

        // Sin `route` esto era un `generate('')`, que revienta con «la ruta "" no existe». Sigue
        // siendo un 500 —una configuración rota tiene que verse—, pero diciendo qué falta.
        if ($enlace->nombreRuta === null) {
            throw new HttpException(500, sprintf('tarifa_compressed_ranges: url.%s requiere "route".', $nombre));
        }

        $params = array_merge($enlace->parametros, ['entityId' => $urlId]);
        if (!empty($runtimeReturnTo)) {
            $params['returnTo'] = $runtimeReturnTo;
        }

        return $this->router->generate($enlace->nombreRuta, $params);
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
     * @return array<string|int, array{unit: object, ranges: list<object>}> Por unidad: sus
     *         rangos y los datos de cabecera.
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

            // ⚠️ `PmsUnidad::getId()` es un Uuid, no un escalar: aquí cae SIEMPRE a
            // `spl_object_id()`. La variante SPA ya lo resuelve por texto (ver su comentario); este
            // provider legacy se deja como está para no cambiar lo que pinta el panel viejo.
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
     * El `id` va CRUDO a propósito (un Uuid): el flattener lo descarta por no ser escalar y el
     * segmento se identifica por hash. Es lo que hace que aquí nunca salgan enlaces —ver
     * `docs/Calendar_architecture.md` §5—; la variante SPA lo pasa a texto.
     *
     * @param Validada $valida
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, price: float, minStay: int|null,
     *     currency: string|null, important: bool|null, weight: int|null, id: mixed}
     */
    private function rangeAccessor(object $r, array $valida): array
    {
        $fields = $valida['campos'];

        $start = $this->resolvePath($r, $valida['start']);
        $end = $this->resolvePath($r, $valida['end']);

        if (!$start instanceof DateTimeInterface || !$end instanceof DateTimeInterface) {
            throw new HttpException(500, 'Rango inválido: start/end deben ser DateTimeInterface');
        }

        // Normaliza a día (00:00) solo para estabilidad interna (sin +1 / -1)
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
            $id = $this->resolvePath($r, $fields->id);
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
            throw new HttpException(500, 'tarifa_compressed_ranges requiere "entity"');
        }

        $campos = $config->campos ?? throw new HttpException(500, 'tarifa_compressed_ranges requiere "fields" (array).');

        $valida = [
            'entidad' => $entidad,
            'campos' => $campos,
            'unit' => $campos->unit ?? throw new HttpException(500, 'tarifa_compressed_ranges requiere fields.unit'),
            'start' => $campos->start ?? throw new HttpException(500, 'tarifa_compressed_ranges requiere fields.start'),
            'end' => $campos->end ?? throw new HttpException(500, 'tarifa_compressed_ranges requiere fields.end'),
            'price' => $campos->price ?? throw new HttpException(500, 'tarifa_compressed_ranges requiere fields.price'),
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
