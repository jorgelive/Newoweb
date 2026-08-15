<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use Doctrine\ORM\EntityRepository;
use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use App\Calendar\Service\CalendarResourceCatalog;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Variante de TarifaRangesRawCalendarProvider para consumidores API/SPA (Vue).
 *
 * Diferencias respecto al provider legacy (EasyAdmin):
 * - No genera urledit/urlshow (no depende del router de Symfony ni de rutas EasyAdmin).
 * - No depende de runtime_returnTo / current_page.
 * - No hace chequeos de ROLE_* para decidir si expone un link.
 * - Expone "context" + id crudo en extendedProps para que el frontend arme su propia navegación.
 */
final class TarifaRangesSpaCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly CalendarResourceCatalog $resourceCatalog,
    ) {}

    public function supports(array $config): bool
    {
        return (($config['provider'] ?? null) === 'tarifa_ranges_spa')
            && isset($config['entity'])
            && is_string($config['entity']);
    }

    /**
     * @return list<CalendarEventDto>
     */
    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $this->assertConfig($config);

        $entities = $this->fetchEntities($from, $to, $config);

        $fields = (array) $config['fields'];
        $eventCfg = isset($config['event']) && is_array($config['event']) ? $config['event'] : [];

        $includeCurrency = (bool) ($eventCfg['includeCurrency'] ?? true);
        $titleFormat = (string) ($eventCfg['titleFormat'] ?? '{currency} {price} · {minStay}N');
        $priceDecimals = (int) ($eventCfg['priceDecimals'] ?? 2);

        $eventTime = (isset($config['eventTime']) && is_array($config['eventTime'])) ? $config['eventTime'] : [];
        [$sh, $sm, $ss] = $this->parseHms((string)($eventTime['start'] ?? '12:00:00'), [12, 0, 0]);
        [$eh, $em, $es] = $this->parseHms((string)($eventTime['end'] ?? '11:59:59'), [11, 59, 59]);

        $out = [];

        foreach ($entities as $entity) {
            // 1. Datos básicos (Fechas)
            $startRaw = $this->resolvePath($entity, (string) $fields['start']);
            $endRaw = $this->resolvePath($entity, (string) $fields['end']);

            if (!$startRaw instanceof DateTimeInterface || !$endRaw instanceof DateTimeInterface) {
                continue;
            }

            $startUi = DateTimeImmutable::createFromInterface($startRaw)->setTime($sh, $sm, $ss);
            $endUi   = DateTimeImmutable::createFromInterface($endRaw)->setTime($eh, $em, $es);

            // 2. Active / Inactive
            $isInactive = false;
            if (!empty($fields['active'])) {
                $activeVal = $this->resolvePath($entity, (string) $fields['active']);
                if ($activeVal !== null) {
                    $isInactive = ((bool) $activeVal) === false;
                }
            }

            // 3. IDs y Recursos
            $id = null;
            if (!empty($fields['id'])) {
                $id = $this->resolvePath($entity, (string) $fields['id']);
            }

            if ($id instanceof Uuid) {
                $id = (string) $id;
            }
            $id = (is_scalar($id) && $id !== '') ? $id : spl_object_id($entity);

            $resourceId = null;
            $resourceRoot = $entity;
            if (!empty($fields['resourceRoot'])) {
                $resourceRoot = $this->resolvePath($entity, (string) $fields['resourceRoot']);
            }

            if (!empty($fields['resourceId'])) {
                $rid = $this->resolvePath($entity, (string) $fields['resourceId']);
                if ($rid instanceof Uuid) {
                    $rid = (string) $rid;
                }
                $resourceId = (is_scalar($rid) && $rid !== '') ? $rid : null;
            } elseif (is_object($resourceRoot) && method_exists($resourceRoot, 'getId')) {
                $resourceId = $resourceRoot->getId();
                if ($resourceId instanceof Uuid) {
                    $resourceId = (string) $resourceId;
                }
            }

            // 4. Precio y MinStay
            $priceVal = $this->resolvePath($entity, (string) $fields['price']);
            $price = (float) ($priceVal ?? 0);

            $minStay = 2;
            if (!empty($fields['minStay'])) {
                $ms = $this->resolvePath($entity, (string) $fields['minStay']);
                if ($ms !== null) $minStay = (int) $ms;
            }

            $currencyCode = null;
            if ($includeCurrency && !empty($fields['currency'])) {
                $c = $this->resolvePath($entity, (string) $fields['currency']);
                $currencyCode = $this->scalarToStringOrNull($c);
            }

            // 5. Título y Estilos
            $title = $this->formatTitle($titleFormat, $price, $minStay, $currencyCode, $priceDecimals);
            if ($isInactive) $title = '[INACTIVO] ' . $title;
            $backgroundColor = $isInactive ? '#2b2b2b' : null;

            // 6. Prioridad (scoring de z-order)
            $prioridadScore = 0;
            $esImportante = false;

            if (!empty($fields['important'])) {
                $val = $this->resolvePath($entity, (string) $fields['important']);
                $esImportante = ((bool) $val === true);
                if ($esImportante) {
                    $prioridadScore += 10_000_000;
                }
            }

            if (!empty($fields['weight'])) {
                $val = $this->resolvePath($entity, (string) $fields['weight']);
                if (is_numeric($val)) {
                    $prioridadScore += ((int)$val * 10_000);
                }
            }

            $diff = $startUi->diff($endUi);
            $dias = (int) $diff->format('%a');
            $diasSafe = max(0, min($dias, 9999));
            $prioridadScore += (10_000 - $diasSafe);

            // 7. Tooltip
            $tooltip = null;
            if (!empty($eventCfg['tooltip']) && is_array($eventCfg['tooltip'])) {
                $lines = [];
                foreach ($eventCfg['tooltip'] as $path) {
                    $v = $this->resolvePath($entity, (string) $path);
                    $lines[] = $this->scalarToStringOrNull($v);
                }
                $tooltip = $lines;
            } else {
                $unitLabel = is_object($resourceRoot) && method_exists($resourceRoot, '__toString') ? (string) $resourceRoot : 'Recurso';
                $tooltip = [$unitLabel];

                if ($isInactive) $tooltip[] = 'ESTADO: INACTIVO';

                $tooltip[] = 'Precio Base: ' . $this->formatNumber($price, $priceDecimals) . ($currencyCode ? ' ' . $currencyCode : '');
                $tooltip[] = 'Neto al 20%: ' . $this->formatNumber($price * 0.80, $priceDecimals);
                $tooltip[] = 'Neto al 30%: ' . $this->formatNumber($price * 0.70, $priceDecimals);
                $tooltip[] = 'MinStay: ' . $minStay . ' noches';
            }

            // 8. DTO
            $out[] = new CalendarEventDto(
                id: $id,
                title: $title,
                start: $startUi,
                end: $endUi,
                resourceId: $resourceId,
                backgroundColor: $backgroundColor,
                tooltip: $tooltip,
                prioridadImportante: $prioridadScore,
                // Datos crudos, no formateados: el frontend arma su propia UI
                // (íconos, badge de minStay, color por casita) sin tener que
                // volver a parsear el título ni pedir la tarifa por REST.
                extendedProps: [
                    'context' => 'tarifaRango',
                    'tarifaRangoId' => (string) $id,
                    'active' => !$isInactive,
                    'precio' => $this->formatNumber($price, $priceDecimals),
                    'minStay' => $minStay,
                    'moneda' => $currencyCode,
                    'importante' => $esImportante,
                ],
            );
        }

        return $out;
    }

    public function getResources(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $this->assertConfig($config);
        $entities = $this->fetchEntities($from, $to, $config);
        $fields = (array) $config['fields'];

        $resourceRootPath = (string) ($fields['resourceRoot'] ?? '');
        $resourceIdPath = (string) ($fields['resourceId'] ?? '');
        $resourceTitlePath = (string) ($fields['resourceTitle'] ?? '');

        $seen = [];
        $out = [];

        foreach ($entities as $entity) {
            $resourceRoot = $entity;

            if ($resourceRootPath !== '') {
                $resourceRoot = $this->resolvePath($entity, $resourceRootPath);
            }
            if (!is_object($resourceRoot)) continue;

            $id = null;
            if ($resourceIdPath !== '') {
                $id = $this->resolvePath($entity, $resourceIdPath);
            } elseif (method_exists($resourceRoot, 'getId')) {
                $id = $resourceRoot->getId();
            }

            if ($id instanceof Uuid) $id = (string) $id;
            if (!is_scalar($id) || $id === '') continue;

            $key = (string) $id;
            if (isset($seen[$key])) continue;

            $seen[$key] = true;

            $titleVal = null;
            if ($resourceTitlePath !== '') {
                $titleVal = $this->resolvePath($entity, $resourceTitlePath);
            } elseif (method_exists($resourceRoot, '__toString')) {
                $titleVal = (string) $resourceRoot;
            }

            $title = $this->scalarToStringOrNull($titleVal) ?? ('Resource ' . $key);

            $out[] = new CalendarResourceDto(id: $key, title: $title);
        }

        // Las unidades sin rangos de tarifa en el intervalo desaparecían de la
        // grilla: el catálogo las repone (ver resources.showAll en el YAML) y
        // se encarga del orden natural + índice `orden`.
        return $this->resourceCatalog->merge(
            $out,
            $config,
            $this->resourceCatalog->targetClassOf(
                (string) $config['entity'],
                $resourceRootPath !== '' ? $resourceRootPath : $resourceIdPath
            )
        );
    }

    /**
     * @param array<string, mixed> $config La configuración del calendario, tal como llega del YAML.
     * @return list<object>
     */
    private function fetchEntities(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        /** @var class-string $entityClass La clase viene de la configuración del calendario. */
        $entityClass = (string) $config['entity'];

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

        $fields = (array) $config['fields'];
        $filters = isset($config['filters']) && is_array($config['filters']) ? $config['filters'] : [];

        $startField = (string) $fields['start'];
        $endField = (string) $fields['end'];

        /** @var \Doctrine\ORM\QueryBuilder $qb */
        $qb = $repo->createQueryBuilder('r');

        $qb
            ->andWhere(sprintf('r.%s <= :to', $startField))
            ->andWhere(sprintf('r.%s >= :from', $endField))
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $showInactive = (bool) ($filters['showInactive'] ?? false);

        if (!empty($filters['activeOnly']) && !$showInactive) {
            if (empty($fields['active'])) {
                throw new HttpException(500, 'filters.activeOnly=true requiere fields.active');
            }
            $activeField = (string) $fields['active'];

            $qb->andWhere(sprintf('r.%s = :active', $activeField))
                ->setParameter('active', true);
        }

        $qb->addOrderBy(sprintf('r.%s', $startField), 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array<string, mixed> $config La configuración del calendario, tal como llega del YAML.
     */
    private function assertConfig(array $config): void
    {
        if (empty($config['entity']) || !is_string($config['entity'])) {
            throw new HttpException(500, 'tarifa_ranges_spa requiere "entity"');
        }

        $fields = $config['fields'] ?? null;
        if (!is_array($fields)) {
            throw new HttpException(500, 'tarifa_ranges_spa requiere "fields" (array).');
        }

        foreach (['start', 'end', 'price'] as $k) {
            if (empty($fields[$k]) || !is_string($fields[$k])) {
                throw new HttpException(500, sprintf('tarifa_ranges_spa requiere fields.%s', $k));
            }
        }
    }

    /**
     * Arma el título del evento a partir del `event.titleFormat` de la config.
     *
     * Antes esta función IGNORABA el formato recibido y siempre devolvía el
     * bloque "precio (neto20 | 20% - neto30 | 30%) NN", que en la barra del
     * calendario tapaba el dato importante (el precio) con dos números que ya
     * están en el tooltip. Ahora los placeholders mandan, así que cambiar la
     * densidad del título es cuestión de tocar el YAML, no el código.
     *
     * Placeholders: {price} {currency} {minStay} {neto20} {neto30}
     */
    private function formatTitle(string $format, float $price, int $minStay, ?string $currency, int $priceDecimals): string
    {
        $titulo = strtr($format, [
            '{price}'    => $this->formatNumber($price, $priceDecimals),
            '{currency}' => $currency ?? '',
            '{minStay}'  => (string) $minStay,
            '{neto20}'   => $this->formatNumber($price * 0.80, $priceDecimals),
            '{neto30}'   => $this->formatNumber($price * 0.70, $priceDecimals),
        ]);

        // Si no hay moneda configurada, {currency} deja un hueco al inicio.
        return trim(preg_replace('/\s+/', ' ', $titulo) ?? $titulo);
    }

    private function formatNumber(float $n, int $decimals): string
    {
        return number_format($n, $decimals, '.', '');
    }

    /**
     * @param array{0: int, 1: int, 2: int} $default
     * @return array{0: int, 1: int, 2: int}
     */
    private function parseHms(string $time, array $default): array
    {
        $time = trim($time);
        if ($time === '') return $default;
        $parts = explode(':', $time);
        if (count($parts) < 2) return $default;
        return [(int)($parts[0]??$default[0]), (int)($parts[1]??$default[1]), (int)($parts[2]??$default[2])];
    }

    private function resolvePath(mixed $base, string $path): mixed
    {
        $parts = str_contains($path, '.') ? explode('.', $path) : [$path];
        $val = $base;
        foreach ($parts as $part) {
            if (!is_object($val)) return null;
            $getter = 'get' . ucfirst($part);
            if (method_exists($val, $getter)) { $val = $val->{$getter}(); continue; }
            $isser = 'is' . ucfirst($part);
            if (method_exists($val, $isser)) { $val = $val->{$isser}(); continue; }
            if (method_exists($val, $part)) { $val = $val->{$part}(); continue; }
            return null;
        }
        return $val;
    }

    private function scalarToStringOrNull(mixed $v): ?string
    {
        if ($v === null) return null;
        if (is_scalar($v) || (is_object($v) && method_exists($v, '__toString'))) return (string)$v;
        return null;
    }
}
