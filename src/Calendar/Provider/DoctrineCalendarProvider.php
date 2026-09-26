<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Config\ConfiguracionCalendario;
use App\Calendar\Config\Enlace;
use App\Calendar\Config\OpcionesDoctrineLegacy;
use Doctrine\ORM\EntityRepository;
use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use DateTimeInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Provider "legacy" compatible con el YAML actual (entity + repositorymethod + parameters + resource).
 *
 * Importante:
 * - Si config incluye `provider: ...`, este provider NO aplica.
 *   Eso evita colisiones cuando agregues providers nuevos.
 * - Hoy ningún calendario del YAML cae aquí: todos declaran `provider`. Ver
 *   {@see OpcionesDoctrineLegacy}.
 */
final class DoctrineCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $router,
    ) {}

    public function supports(ConfiguracionCalendario $config): bool
    {
        // Si el usuario fuerza un provider explícito, evitamos heurística.
        if ($config->declaraProvider) {
            return false;
        }

        // Heurística: si hay entity => Doctrine legacy.
        return $config->entidad !== null && $config->entidad !== '';
    }

    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
    {
        $entities = $this->fetchEntities($from, $to, $config);
        return $this->mapEntitiesToEventDtos($entities, $config->doctrine);
    }

    public function getResources(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
    {
        $entities = $this->fetchEntities($from, $to, $config);
        return $this->mapEntitiesToResourceDtos($entities, $config->doctrine);
    }

    /**
     * Obtiene entidades a partir del repositorymethod (recomendado) o un fallback simple.
     *
     * @return list<object>
     */
    private function fetchEntities(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
    {
        $entityClass = $config->entidad ?? '';
        $opciones = $config->doctrine;

        // `getManagerForClass()` pide `class-string`. Una clase que no existe reventaba DENTRO de
        // Doctrine con un ReflectionException; ahora es la misma excepción de al lado.
        if (!class_exists($entityClass)) {
            throw new \LogicException(sprintf('No hay ObjectManager para %s', $entityClass));
        }

        $manager = $this->managerRegistry->getManagerForClass($entityClass);
        if (!$manager instanceof ObjectManager) {
            throw new \LogicException(sprintf('No hay ObjectManager para %s', $entityClass));
        }

        $repository = $manager->getRepository($entityClass);
        // ⚠️ `EntityRepository` y no `ObjectRepository`: abajo se llama a `createQueryBuilder()`,
        // que sólo existe en el primero. Con la guarda anterior, un repositorio que no fuera de
        // Doctrine ORM la pasaba y reventaba doce líneas más abajo con «undefined method».
        if (!$repository instanceof EntityRepository) {
            throw new \LogicException(sprintf('No hay repository para %s', $entityClass));
        }

        // Usuario opcional: tus repos legacy a veces filtran por user/roles.
        $token = $this->tokenStorage->getToken();
        $user = is_object($token?->getUser()) ? $token->getUser() : null;

        if ($opciones->metodoRepositorio !== null) {
            $method = $opciones->metodoRepositorio;
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

            /** @var list<object> $resultado */
            $resultado = $qb->getQuery()->getResult();

            return $resultado;
        }

        // Fallback: requiere que config.parameters tenga start/end
        $startField = $opciones->inicio;
        $endField = $opciones->fin;

        $qb = $repository->createQueryBuilder('me');
        $qb
            ->where(sprintf('me.%s >= :firstDate AND me.%s <= :lastDate', $endField, $startField))
            ->setParameter('firstDate', $from)
            ->setParameter('lastDate', $to);

        /** @var list<object> $resultado */
        $resultado = $qb->getQuery()->getResult();

        return $resultado;
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
    private function mapEntitiesToResourceDtos(array $entities, OpcionesDoctrineLegacy $opciones): array
    {
        if (!$opciones->conRecurso) {
            return [new CalendarResourceDto(id: 'default', title: 'Default', orden: 0)];
        }

        $seen = [];
        $out = [];

        foreach ($entities as $entity) {
            $resourceRoot = $entity;

            if ($opciones->recursoRaiz !== null) {
                $resourceRoot = $this->resolvePath($entity, $opciones->recursoRaiz);
            }
            if ($resourceRoot === null) continue;

            $id = $this->idDe($this->resolvePath($resourceRoot, $opciones->recursoId));
            if ($id === null) continue;

            $key = (string) $id;
            if (isset($seen[$key])) continue;

            $seen[$key] = true;

            $titleVal = $this->resolvePath($resourceRoot, $opciones->recursoTitulo);
            $title = $this->scalarToStringOrNull($titleVal) ?? '';

            $out[] = new CalendarResourceDto(id: $id, title: $title);
        }

        // 1. Orden Natural Alfabético
        usort($out, static fn (CalendarResourceDto $a, CalendarResourceDto $b): int => strnatcasecmp($a->title, $b->title));

        // 🔥 2. Inyección del índice de Orden
        $finalOut = [];
        foreach ($out as $index => $resource) {
            $finalOut[] = new CalendarResourceDto(
                id: $resource->id,
                title: $resource->title,
                orden: $index
            );
        }

        return $finalOut;
    }

    /**
     * Mapea entidades a Events.
     *
     * @param list<object> $entities
     * @return list<CalendarEventDto>
     */
    private function mapEntitiesToEventDtos(array $entities, OpcionesDoctrineLegacy $p): array
    {
        $out = [];

        foreach ($entities as $entity) {
            $id = $this->idDe($this->resolvePath($entity, $p->id));

            $titleVal = $this->resolvePath($entity, $p->titulo);
            $title = $this->scalarToStringOrNull($titleVal) ?? '';

            $start = $this->resolvePath($entity, $p->inicio);
            $end = $this->resolvePath($entity, $p->fin);

            if (!$start instanceof DateTimeInterface || !$end instanceof DateTimeInterface) {
                continue;
            }

            // resourceId desde el root (unit / pmsUnidad / etc.)
            $resourceId = null;
            if ($p->conRecurso) {
                $resourceRoot = $entity;
                if ($p->recursoRaiz !== null) {
                    $resourceRoot = $this->resolvePath($entity, $p->recursoRaiz);
                }
                if ($resourceRoot !== null) {
                    $resourceId = $this->idDe($this->resolvePath($resourceRoot, $p->recursoId));
                }
            }

            $textColor = $p->colorTexto !== null ? $this->scalarToStringOrNull($this->resolvePath($entity, $p->colorTexto)) : null;
            $backgroundColor = $p->colorFondo !== null ? $this->scalarToStringOrNull($this->resolvePath($entity, $p->colorFondo)) : null;
            $borderColor = $p->colorBorde !== null ? $this->scalarToStringOrNull($this->resolvePath($entity, $p->colorBorde)) : null;
            $color = $p->color !== null ? $this->scalarToStringOrNull($this->resolvePath($entity, $p->color)) : null;

            // classNames: array o string con espacios
            $classNames = null;
            if ($p->clases !== null) {
                $cn = $this->resolvePath($entity, $p->clases);
                if (is_array($cn)) {
                    // `strval()` con lo que tenga texto; lo que no (una lista, un objeto sin
                    // `__toString`) avisaba o reventaba, y ahora es una clase vacía.
                    $classNames = array_values(array_map(
                        static fn (mixed $c): string => is_scalar($c) || $c === null || $c instanceof \Stringable ? (string) $c : '',
                        $cn
                    ));
                } elseif (is_string($cn) && $cn !== '') {
                    $classNames = preg_split('/\s+/', trim($cn)) ?: null;
                }
            }

            // tooltip: permite lista de paths
            $tooltip = null;
            if ($p->tooltip !== null) {
                if (is_array($p->tooltip)) {
                    $lines = [];
                    foreach ($p->tooltip as $subject) {
                        $v = $this->resolvePath($entity, $subject);
                        $s = $this->scalarToStringOrNull($v);
                        if ($s !== null && $s !== '') {
                            $lines[] = $s;
                        }
                    }
                    $tooltip = $lines;
                } else {
                    $tooltip = $this->scalarToStringOrNull($this->resolvePath($entity, $p->tooltip));
                }
            }

            // URLs con permisos (Oweb admin)
            $urledit = null;
            $urlshow = null;
            if ($p->enlaces !== null) {
                $urlId = $this->resolvePath($entity, $p->enlaces->rutaId ?? 'id');

                $urledit = $this->urlDe($p->enlaces->enlace('edit'), 'edit', $urlId);
                $urlshow = $this->urlDe($p->enlaces->enlace('show'), 'show', $urlId);
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
     * Un enlace del panel. Sin rol no hay enlace (`isGranted()` sin atributo deniega), y los
     * `params` del YAML no se leen: este provider siempre mandó sólo `id` y `tl`.
     */
    private function urlDe(?Enlace $enlace, string $nombre, mixed $urlId): ?string
    {
        if ($enlace === null || $enlace->rol === null || true !== $this->authorizationChecker->isGranted($enlace->rol)) {
            return null;
        }

        // Sin `route` esto era un `generate('')`, que revienta con «la ruta "" no existe». Sigue
        // reventando —una configuración rota tiene que verse—, pero diciendo qué falta.
        if ($enlace->nombreRuta === null) {
            throw new \LogicException(sprintf('parameters.url.%s requiere "route".', $nombre));
        }

        return $this->router->generate($enlace->nombreRuta, ['id' => $urlId, 'tl' => 'es']);
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
     * Un identificador tal como lo aceptan los DTO (texto, entero o `Stringable`, que es el `Uuid`).
     * Lo demás reventaba en el constructor del DTO con un TypeError; ahora es «sin id».
     */
    private function idDe(mixed $valor): string|int|\Stringable|null
    {
        return is_string($valor) || is_int($valor) || $valor instanceof \Stringable ? $valor : null;
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
