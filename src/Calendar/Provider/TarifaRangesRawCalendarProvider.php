<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Provider RAW con cálculo de prioridad visual (Z-Index lógico).
 */
final class TarifaRangesRawCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $router,
    ) {}

    public function supports(array $config): bool
    {
        return (($config['provider'] ?? null) === 'tarifa_ranges_raw')
            && isset($config['entity'])
            && is_string($config['entity']);
    }

    /**
     * @return list<CalendarEventDto>
     */
    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $this->assertConfig($config);

        $runtimeReturnTo = $config['runtime_returnTo'] ?? null;
        $entities = $this->fetchEntities($from, $to, $config);

        $fields = (array) $config['fields'];
        $eventCfg = isset($config['event']) && is_array($config['event']) ? $config['event'] : [];

        $includeCurrency = (bool) ($eventCfg['includeCurrency'] ?? true);
        $titleFormat = (string) ($eventCfg['titleFormat'] ?? '{currency} {price} | {minStay}');
        $priceDecimals = (int) ($eventCfg['priceDecimals'] ?? 2);

        // UI hours
        $eventTime = (isset($config['eventTime']) && is_array($config['eventTime'])) ? $config['eventTime'] : [];
        [$sh, $sm, $ss] = $this->parseHms((string)($eventTime['start'] ?? '12:00:00'), [12, 0, 0]);
        [$eh, $em, $es] = $this->parseHms((string)($eventTime['end'] ?? '11:59:59'), [11, 59, 59]);

        $out = [];

        foreach ($entities as $entity) {
            // 1. Datos básicos (Fechas)
            $start = $this->resolvePath($entity, (string) $fields['start']);
            $end = $this->resolvePath($entity, (string) $fields['end']);

            // Si faltan fechas, saltamos sin error (seguridad)
            if (!$start instanceof DateTimeInterface || !$end instanceof DateTimeInterface) {
                continue;
            }

            // Aplicar horas UI
            $startUi = DateTimeImmutable::createFromInterface($start)->setTime($sh, $sm, $ss);
            $endUi = DateTimeImmutable::createFromInterface($end)->setTime($eh, $em, $es);
            if ($endUi <= $startUi) {
                $endUi = DateTimeImmutable::createFromInterface($end);
            }

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
            $id = (is_scalar($id) && $id !== '') ? $id : spl_object_id($entity);

            $resourceId = null;
            $resourceRoot = $entity;
            if (!empty($fields['resourceRoot'])) {
                $resourceRoot = $this->resolvePath($entity, (string) $fields['resourceRoot']);
            }
            if (!empty($fields['resourceId'])) {
                $rid = $this->resolvePath($entity, (string) $fields['resourceId']);
                $resourceId = (is_scalar($rid) && $rid !== '') ? $rid : null;
            } elseif (is_object($resourceRoot) && method_exists($resourceRoot, 'getId')) {
                $resourceId = $resourceRoot->getId();
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

            // =========================================================
            // 🔥 CÁLCULO DE PRIORIDAD (SCORING)
            // =========================================================
            // Jerarquía: Importante > Peso > Duración Corta

            $prioridadScore = 0;

            // A. Importancia (+10,000,000) - Gana a todo
            if (!empty($fields['important'])) {
                $val = $this->resolvePath($entity, (string) $fields['important']);
                if ((bool)$val === true) {
                    $prioridadScore += 10_000_000;
                }
            }

            // B. Peso (+10,000 * peso) - Gana a la duración
            if (!empty($fields['weight'])) {
                $val = $this->resolvePath($entity, (string) $fields['weight']);
                if (is_numeric($val)) {
                    $prioridadScore += ((int)$val * 10_000);
                }
            }

            // C. Duración Invertida (+10,000 - días)
            // Menos días = Más puntaje (Rango corto queda encima)
            $diff = $start->diff($end);
            $dias = (int) $diff->format('%a');
            // Clamp para seguridad: máx 9999 días para no restar demasiado
            $diasSafe = max(0, min($dias, 9999));

            $prioridadScore += (10_000 - $diasSafe);


            // 6. Tooltip
            $tooltip = null;
            if (!empty($eventCfg['tooltip']) && is_array($eventCfg['tooltip'])) {
                $lines = [];
                foreach ($eventCfg['tooltip'] as $path) {
                    $v = $this->resolvePath($entity, (string) $path);
                    $lines[] = $this->scalarToStringOrNull($v);
                }
                $tooltip = $lines;
            } else {
                $unitLabel = is_object($resourceRoot) && method_exists($resourceRoot, '__toString') ? (string) $resourceRoot : 'Resource';
                $tooltip = [$unitLabel];
                if ($isInactive) $tooltip[] = 'INACTIVO';
                $tooltip[] = 'Precio: ' . $this->formatNumber($price, $priceDecimals);
                $tooltip[] = 'MinStay: ' . $minStay;
                if ($currencyCode) $tooltip[] = $currencyCode;
            }

            // 7. URLs
            $urledit = null; $urlshow = null;
            if (isset($eventCfg['url']) && is_array($eventCfg['url'])) {
                $urlCfg = $eventCfg['url'];
                $urlId = isset($urlCfg['id']) ? $this->resolvePath($entity, (string) $urlCfg['id']) : $id;

                if (isset($urlCfg['edit']) && is_array($urlCfg['edit']) && isset($urlCfg['edit']['role'], $urlCfg['edit']['route']) && true === $this->authorizationChecker->isGranted($urlCfg['edit']['role'])) {
                    $params = array_merge(['entityId' => $urlId, 'tl' => 'es'], $urlCfg['edit']['params'] ?? []);
                    if ($runtimeReturnTo) $params['returnTo'] = $runtimeReturnTo;
                    $urledit = $this->router->generate((string) $urlCfg['edit']['route'], $params);
                }

                if (isset($urlCfg['show']) && is_array($urlCfg['show']) && isset($urlCfg['show']['role'], $urlCfg['show']['route']) && true === $this->authorizationChecker->isGranted($urlCfg['show']['role'])) {
                    $params = array_merge(['entityId' => $urlId, 'tl' => 'es'], $urlCfg['show']['params'] ?? []);
                    if ($runtimeReturnTo) $params['returnTo'] = $runtimeReturnTo;
                    $urlshow = $this->router->generate((string) $urlCfg['show']['route'], $params);
                }
            }

            // 8. DTO con Prioridad
            $out[] = new CalendarEventDto(
                id: $id,
                title: $title,
                start: $startUi,
                end: $endUi,
                resourceId: $resourceId,
                textColor: null,
                backgroundColor: $backgroundColor,
                borderColor: null,
                color: null,
                classNames: null,
                urledit: $urledit,
                urlshow: $urlshow,
                tooltip: $tooltip,
                // Pasamos el cálculo final. Si no hubo configuración de peso/importante,
                // al menos llevará el puntaje de duración inversa (eventos cortos ganan).
                prioridadImportante: $prioridadScore
            );
        }

        return $out;
    }

    /**
     * @return list<CalendarResourceDto>
     */
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

            if (!is_object($resourceRoot)) {
                continue;
            }

            $id = null;
            if ($resourceIdPath !== '') {
                $id = $this->resolvePath($entity, $resourceIdPath);
            } elseif (method_exists($resourceRoot, 'getId')) {
                $id = $resourceRoot->getId();
            }

            if (!is_scalar($id) || $id === '') {
                continue;
            }

            $key = (string) $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $titleVal = null;
            if ($resourceTitlePath !== '') {
                $titleVal = $this->resolvePath($entity, $resourceTitlePath);
            } elseif (method_exists($resourceRoot, '__toString')) {
                $titleVal = (string) $resourceRoot;
            }

            $title = $this->scalarToStringOrNull($titleVal) ?? ('Resource ' . $key);

            $out[] = new CalendarResourceDto(id: $id, title: $title);
        }

        usort($out, static fn (CalendarResourceDto $a, CalendarResourceDto $b): int => (string) $a->id <=> (string) $b->id);

        return $out;
    }

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

    private function assertConfig(array $config): void
    {
        if (empty($config['entity']) || !is_string($config['entity'])) {
            throw new HttpException(500, 'tarifa_ranges_raw requiere "entity"');
        }

        $fields = $config['fields'] ?? null;
        if (!is_array($fields)) {
            throw new HttpException(500, 'tarifa_ranges_raw requiere "fields" (array).');
        }

        foreach (['start', 'end', 'price'] as $k) {
            if (empty($fields[$k]) || !is_string($fields[$k])) {
                throw new HttpException(500, sprintf('tarifa_ranges_raw requiere fields.%s', $k));
            }
        }
    }

    private function formatTitle(string $format, float $price, int $minStay, ?string $currency, int $priceDecimals): string
    {
        $repl = [
            '{price}' => $this->formatNumber($price, $priceDecimals),
            '{minStay}' => (string) $minStay,
            '{currency}' => $currency ?? '',
        ];
        $title = strtr($format, $repl);
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        return $title !== '' ? $title : ($this->formatNumber($price, $priceDecimals) . ' | ' . $minStay);
    }

    private function formatNumber(float $n, int $decimals): string
    {
        return number_format($n, $decimals, '.', '');
    }

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