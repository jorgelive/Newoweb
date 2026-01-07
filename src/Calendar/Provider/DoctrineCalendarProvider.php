<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use DateTimeInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Provider "legacy" compatible con el YAML actual (entity + repositorymethod + parameters + resource).
 *
 * Importante:
 * - Si config incluye `provider: ...`, este provider NO aplica.
 *   Eso evita colisiones cuando agregues providers nuevos.
 */
final class DoctrineCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $router,
    ) {}

    public function supports(array $config): bool
    {
        // Si el usuario fuerza un provider explícito, evitamos heurística.
        if (array_key_exists('provider', $config)) {
            return false;
        }

        // Heurística: si hay entity => Doctrine legacy.
        return isset($config['entity']) && is_string($config['entity']) && $config['entity'] !== '';
    }

    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $entities = $this->fetchEntities($from, $to, $config);
        return $this->mapEntitiesToEventDtos($entities, $config);
    }

    public function getResources(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $entities = $this->fetchEntities($from, $to, $config);
        return $this->mapEntitiesToResourceDtos($entities, $config);
    }

    /**
     * Obtiene entidades a partir del repositorymethod (recomendado) o un fallback simple.
     *
     * @return list<object>
     */
    private function fetchEntities(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $entityClass = (string) $config['entity'];

        $manager = $this->managerRegistry->getManagerForClass($entityClass);
        if (!$manager instanceof ObjectManager) {
            throw new \LogicException(sprintf('No hay ObjectManager para %s', $entityClass));
        }

        $repository = $manager->getRepository($entityClass);
        if (!$repository instanceof ObjectRepository) {
            throw new \LogicException(sprintf('No hay repository para %s', $entityClass));
        }

        // Usuario opcional: tus repos legacy a veces filtran por user/roles.
        $token = $this->tokenStorage->getToken();
        $user = is_object($token?->getUser()) ? $token->getUser() : null;

        if (!empty($config['repositorymethod'])) {
            $method = (string) $config['repositorymethod'];
            if (!method_exists($repository, $method)) {
                throw new \LogicException(sprintf('Repository %s no tiene método %s', get_class($repository), $method));
            }

            $criteria = ['from' => $from, 'to' => $to, 'user' => $user];
            $qb = $repository->{$method}($criteria);

            // tus repos deben devolver QueryBuilder (no Query)
            if ($qb instanceof Query) {
                throw new \LogicException(sprintf('El método %s::%s debe devolver QueryBuilder, no Query.', get_class($repository), $method));
            }
            if (!$qb instanceof QueryBuilder) {
                throw new \LogicException(sprintf('El método %s::%s debe devolver QueryBuilder.', get_class($repository), $method));
            }

            return $qb->getQuery()->getResult();
        }

        // Fallback: requiere que config.parameters tenga start/end
        $p = isset($config['parameters']) && is_array($config['parameters']) ? $config['parameters'] : [];
        $startField = (string) ($p['start'] ?? 'start');
        $endField = (string) ($p['end'] ?? 'end');

        /** @var QueryBuilder $qb */
        $qb = $repository->createQueryBuilder('me');
        $qb
            ->where(sprintf('me.%s >= :firstDate AND me.%s <= :lastDate', $endField, $startField))
            ->setParameter('firstDate', $from)
            ->setParameter('lastDate', $to);

        return $qb->getQuery()->getResult();
    }

    /**
     * Mapea entidades a Resources (scheduler).
     *
     * La deduplicación usa un "key" (string) derivado del id del resource.
     * Eso evita repetir la misma unidad varias veces si vienen múltiples reservas/eventos.
     *
     * @param list<object> $entities
     * @return list<CalendarResourceDto>
     */
    private function mapEntitiesToResourceDtos(array $entities, array $config): array
    {
        $resourceCfg = isset($config['resource']) && is_array($config['resource']) ? $config['resource'] : null;
        if (empty($resourceCfg)) {
            return [new CalendarResourceDto(id: 'default', title: 'Default')];
        }

        $seen = [];
        $out = [];

        foreach ($entities as $entity) {
            $resourceRoot = $entity;

            // root: por ejemplo "unit" (ReservaReserva->getUnit()->...)
            if (!empty($resourceCfg['root'])) {
                $resourceRoot = $this->resolvePath($entity, (string) $resourceCfg['root']);
            }
            if ($resourceRoot === null) {
                continue;
            }

            $id = $this->resolvePath($resourceRoot, (string) ($resourceCfg['id'] ?? 'id'));
            if ($id === null) {
                continue;
            }

            $key = (string) $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $titleVal = $this->resolvePath($resourceRoot, (string) ($resourceCfg['title'] ?? 'title'));
            $title = $this->scalarToStringOrNull($titleVal) ?? '';

            $out[] = new CalendarResourceDto(id: $id, title: $title);
        }

        usort($out, static fn (CalendarResourceDto $a, CalendarResourceDto $b): int => (string) $a->id <=> (string) $b->id);

        return $out;
    }

    /**
     * Mapea entidades a Events.
     *
     * @param list<object> $entities
     * @return list<CalendarEventDto>
     */
    private function mapEntitiesToEventDtos(array $entities, array $config): array
    {
        $p = isset($config['parameters']) && is_array($config['parameters']) ? $config['parameters'] : [];
        $resourceCfg = isset($config['resource']) && is_array($config['resource']) ? $config['resource'] : null;

        $out = [];

        foreach ($entities as $entity) {
            $id = $this->resolvePath($entity, (string) (($p['id'] ?? null) ?: 'id'));

            $titleVal = $this->resolvePath($entity, (string) ($p['title'] ?? 'title'));
            $title = $this->scalarToStringOrNull($titleVal) ?? '';

            $start = $this->resolvePath($entity, (string) ($p['start'] ?? 'start'));
            $end = $this->resolvePath($entity, (string) ($p['end'] ?? 'end'));

            if (!$start instanceof DateTimeInterface || !$end instanceof DateTimeInterface) {
                continue;
            }

            // resourceId desde el root (unit / pmsUnidad / etc.)
            $resourceId = null;
            if (!empty($resourceCfg)) {
                $resourceRoot = $entity;
                if (!empty($resourceCfg['root'])) {
                    $resourceRoot = $this->resolvePath($entity, (string) $resourceCfg['root']);
                }
                if ($resourceRoot !== null) {
                    $resourceId = $this->resolvePath($resourceRoot, (string) ($resourceCfg['id'] ?? 'id'));
                }
            }

            $textColor = isset($p['textColor']) ? $this->scalarToStringOrNull($this->resolvePath($entity, (string) $p['textColor'])) : null;
            $backgroundColor = isset($p['backgroundColor']) ? $this->scalarToStringOrNull($this->resolvePath($entity, (string) $p['backgroundColor'])) : null;
            $borderColor = isset($p['borderColor']) ? $this->scalarToStringOrNull($this->resolvePath($entity, (string) $p['borderColor'])) : null;
            $color = isset($p['color']) ? $this->scalarToStringOrNull($this->resolvePath($entity, (string) $p['color'])) : null;

            // classNames: array o string con espacios
            $classNames = null;
            if (isset($p['classNames'])) {
                $cn = $this->resolvePath($entity, (string) $p['classNames']);
                if (is_array($cn)) {
                    $classNames = array_values(array_map('strval', $cn));
                } elseif (is_string($cn) && $cn !== '') {
                    $classNames = preg_split('/\s+/', trim($cn)) ?: null;
                }
            }

            // tooltip: permite lista de paths
            $tooltip = null;
            if (isset($p['tooltip'])) {
                $tooltipCfg = $p['tooltip'];
                if (is_array($tooltipCfg)) {
                    $lines = [];
                    foreach ($tooltipCfg as $subject) {
                        $v = $this->resolvePath($entity, (string) $subject);
                        $s = $this->scalarToStringOrNull($v);
                        if ($s !== null && $s !== '') {
                            $lines[] = $s;
                        }
                    }
                    $tooltip = $lines;
                } else {
                    $tooltip = $this->scalarToStringOrNull($this->resolvePath($entity, (string) $tooltipCfg));
                }
            }

            // URLs con permisos (Sonata admin)
            $urledit = null;
            $urlshow = null;
            if (isset($p['url']) && is_array($p['url'])) {
                $urlCfg = $p['url'];
                $urlId = $this->resolvePath($entity, (string) ($urlCfg['id'] ?? 'id'));

                if (isset($urlCfg['edit']) && true === $this->authorizationChecker->isGranted($urlCfg['edit']['role'])) {
                    $urledit = $this->router->generate((string) $urlCfg['edit']['route'], ['id' => $urlId, 'tl' => 'es']);
                }
                if (isset($urlCfg['show']) && true === $this->authorizationChecker->isGranted($urlCfg['show']['role'])) {
                    $urlshow = $this->router->generate((string) $urlCfg['show']['route'], ['id' => $urlId, 'tl' => 'es']);
                }
            }

            $out[] = new CalendarEventDto(
                id: $id ?? spl_object_id($entity),
                title: $title,
                start: $start,
                end: $end,
                resourceId: $resourceId,
                textColor: $textColor,
                backgroundColor: $backgroundColor,
                borderColor: $borderColor,
                color: $color,
                classNames: $classNames,
                urledit: $urledit,
                urlshow: $urlshow,
                tooltip: $tooltip,
            );
        }

        return $out;
    }

    /**
     * Resuelve "a.b.c" -> $obj->getA()->getB()->getC().
     *
     * - Si falta un getter intermedio: null
     * - No lanza excepción a propósito: esto permite configs flexibles,
     *   pero si quieres, luego podemos agregar un "config validator" más estricto.
     */
    private function resolvePath(mixed $base, string $path): mixed
    {
        $parts = str_contains($path, '.') ? explode('.', $path) : [$path];
        $val = $base;

        foreach ($parts as $part) {
            $getter = 'get' . ucfirst($part);
            if (!is_object($val) || !method_exists($val, $getter)) {
                return null;
            }
            $val = $val->{$getter}();
        }

        return $val;
    }

    /**
     * Normaliza a string:
     * - escalares -> string
     * - objetos con __toString -> string
     * - otros -> null
     */
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