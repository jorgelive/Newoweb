<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Config\CamposDeTarifa;
use App\Calendar\Config\ConfiguracionCalendario;
use Doctrine\ORM\EntityRepository;
use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use App\Calendar\Service\CalendarResourceCatalog;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Provider RAW con cálculo de prioridad visual (Z-Index lógico).
 */
final class TarifaRangesRawCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $router,
        private readonly CalendarResourceCatalog $resourceCatalog,
    ) {}

    public function supports(ConfiguracionCalendario $config): bool
    {
        return $config->provider === 'tarifa_ranges_raw' && $config->entidad !== null;
    }

    /**
     * @return list<CalendarEventDto>
     */
    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
    {
        $valida = $this->assertConfig($config);
        $fields = $valida['campos'];

        $runtimeReturnTo = $config->retorno;
        $entities = $this->fetchEntities($from, $to, $config, $valida);

        $eventCfg = $config->evento;

        $includeCurrency = $eventCfg->incluirMoneda;
        $titleFormat = $eventCfg->formatoTitulo ?? '{currency} {price} | {minStay}';
        $priceDecimals = $eventCfg->decimalesPrecio;

        // 🔥 OBTENCIÓN DE HORAS ESTRICTAS DE LA CONFIGURACIÓN (Ej: 12:00:00 y 11:59:59)
        [$sh, $sm, $ss] = $this->parseHms($config->horas->inicio, [12, 0, 0]);
        [$eh, $em, $es] = $this->parseHms($config->horas->fin, [11, 59, 59]);

        $out = [];

        foreach ($entities as $entity) {
            // 1. Datos básicos (Fechas)
            $startRaw = $this->resolvePath($entity, $valida['start']);
            $endRaw = $this->resolvePath($entity, $valida['end']);

            // Si faltan fechas, saltamos sin error (seguridad)
            if (!$startRaw instanceof DateTimeInterface || !$endRaw instanceof DateTimeInterface) {
                continue;
            }

            // 🔥 APLICAMOS LAS HORAS ESTRICTAMENTE SIN TOCAR, SUMAR NI RESTAR DÍAS
            $startUi = DateTimeImmutable::createFromInterface($startRaw)->setTime($sh, $sm, $ss);
            $endUi   = DateTimeImmutable::createFromInterface($endRaw)->setTime($eh, $em, $es);

            // 2. Active / Inactive
            $isInactive = false;
            if ($fields->active !== null) {
                $activeVal = $this->resolvePath($entity, $fields->active);
                if ($activeVal !== null) {
                    $isInactive = ((bool) $activeVal) === false;
                }
            }

            // 3. IDs y Recursos
            $id = null;
            if ($fields->id !== null) {
                $id = $this->resolvePath($entity, $fields->id);
            }

            if ($id instanceof Uuid) {
                $id = (string) $id;
            }
            // `is_scalar()` deja pasar `bool` y `float`, que no son identificadores: dos eventos
            // con `true` compartirían id y FullCalendar pintaría uno solo. Se acota a los dos que
            // sí valen, y lo demás cae al identificador de objeto.
            $id = (is_string($id) || is_int($id)) && $id !== '' ? $id : spl_object_id($entity);

            $resourceId = null;
            $resourceRoot = $entity;
            if ($fields->resourceRoot !== null) {
                $resourceRoot = $this->resolvePath($entity, $fields->resourceRoot);
            }

            if ($fields->resourceId !== null) {
                $rid = $this->resolvePath($entity, $fields->resourceId);
                if ($rid instanceof Uuid) {
                    $rid = (string) $rid;
                }
                // Texto o entero: un `bool` o un `float` —que el `is_scalar()` de antes dejaba
                // pasar— reventaba en el constructor del DTO con un TypeError.
                $resourceId = (is_string($rid) || is_int($rid)) && $rid !== '' ? $rid : null;
            } elseif (is_object($resourceRoot) && method_exists($resourceRoot, 'getId')) {
                $resourceId = $this->idDeRecurso($resourceRoot->getId());
            }

            // 4. Precio y MinStay
            $price = $this->aDecimal($this->resolvePath($entity, $valida['price']));

            $minStay = 2;
            if ($fields->minStay !== null) {
                $ms = $this->resolvePath($entity, $fields->minStay);
                if (is_scalar($ms)) $minStay = (int) $ms;
            }

            $currencyCode = null;
            if ($includeCurrency && $fields->currency !== null) {
                $c = $this->resolvePath($entity, $fields->currency);
                $currencyCode = $this->scalarToStringOrNull($c);
            }

            // 5. Título y Estilos
            $title = $this->formatTitle($titleFormat, $price, $minStay, $currencyCode, $priceDecimals);
            if ($isInactive) $title = '[INACTIVO] ' . $title;
            $backgroundColor = $isInactive ? '#2b2b2b' : null;

            // =========================================================
            // 🔥 CÁLCULO DE PRIORIDAD (SCORING)
            // =========================================================
            $prioridadScore = 0;

            if ($fields->important !== null) {
                $val = $this->resolvePath($entity, $fields->important);
                if ((bool)$val === true) {
                    $prioridadScore += 10_000_000;
                }
            }

            if ($fields->weight !== null) {
                $val = $this->resolvePath($entity, $fields->weight);
                if (is_numeric($val)) {
                    $prioridadScore += ((int)$val * 10_000);
                }
            }

            $diff = $startUi->diff($endUi);
            $dias = (int) $diff->format('%a');
            $diasSafe = max(0, min($dias, 9999));
            $prioridadScore += (10_000 - $diasSafe);

            // 6. Tooltip
            $tooltip = null;
            if ($eventCfg->tooltip !== null) {
                $lines = [];
                foreach ($eventCfg->tooltip as $path) {
                    // Un campo vacío no es una línea: antes salía como una fila en blanco del
                    // tooltip (o «null», según quién lo pintara).
                    $linea = $this->scalarToStringOrNull($this->resolvePath($entity, $path));
                    if ($linea !== null) {
                        $lines[] = $linea;
                    }
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

            // 7. URLs
            $urledit = null; $urlshow = null;
            $enlaces = $eventCfg->enlaces;
            if ($enlaces !== null) {
                $urlId = $enlaces->rutaId !== null ? $this->resolvePath($entity, $enlaces->rutaId) : $id;

                // Aquí el rol es OBLIGATORIO: sin uno legible no hay enlace.
                $edit = $enlaces->enlace('edit');
                if ($edit !== null && $edit->rol !== null && $edit->nombreRuta !== null && true === $this->authorizationChecker->isGranted($edit->rol)) {
                    $params = array_merge(['entityId' => $urlId, 'tl' => 'es'], $edit->parametros);
                    if ($runtimeReturnTo) $params['returnTo'] = $runtimeReturnTo;
                    $urledit = $this->router->generate($edit->nombreRuta, $params);
                }

                $show = $enlaces->enlace('show');
                if ($show !== null && $show->rol !== null && $show->nombreRuta !== null && true === $this->authorizationChecker->isGranted($show->rol)) {
                    $params = array_merge(['entityId' => $urlId, 'tl' => 'es'], $show->parametros);
                    if ($runtimeReturnTo) $params['returnTo'] = $runtimeReturnTo;
                    $urlshow = $this->router->generate($show->nombreRuta, $params);
                }
            }

            // 8. DTO
            $out[] = new CalendarEventDto(
                id: $id,
                title: $title,
                start: $startUi, // <- Hora exacta del YAML, día exacto de BD
                end: $endUi,     // <- Hora exacta del YAML, día exacto de BD
                resourceId: $resourceId,
                textColor: null,
                backgroundColor: $backgroundColor,
                borderColor: null,
                color: null,
                classNames: null,
                urledit: $urledit,
                urlshow: $urlshow,
                tooltip: $tooltip,
                prioridadImportante: $prioridadScore
            );
        }

        return $out;
    }

    public function getResources(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
    {
        $valida = $this->assertConfig($config);
        $entities = $this->fetchEntities($from, $to, $config, $valida);
        $fields = $valida['campos'];

        $resourceRootPath = $fields->resourceRoot ?? '';
        $resourceIdPath = $fields->resourceId ?? '';
        $resourceTitlePath = $fields->resourceTitle ?? '';

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
            $config->recursos,
            $this->resourceCatalog->targetClassOf(
                $valida['entidad'],
                $resourceRootPath !== '' ? $resourceRootPath : $resourceIdPath
            )
        );
    }

    /**
     * @param array{entidad: string, campos: CamposDeTarifa, start: string, end: string, price: string} $valida
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

        $filters = $config->filtros;

        $startField = $valida['start'];
        $endField = $valida['end'];

        $qb = $repo->createQueryBuilder('r');

        $qb
            ->andWhere(sprintf('r.%s <= :to', $startField))
            ->andWhere(sprintf('r.%s >= :from', $endField))
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $showInactive = $filters->mostrarInactivos;

        if ($filters->soloActivos && !$showInactive) {
            $activeField = $valida['campos']->active;
            if ($activeField === null) {
                throw new HttpException(500, 'filters.activeOnly=true requiere fields.active');
            }

            $qb->andWhere(sprintf('r.%s = :active', $activeField))
                ->setParameter('active', true);
        }

        $qb->addOrderBy(sprintf('r.%s', $startField), 'ASC');

        /** @var list<object> $resultado */
        $resultado = $qb->getQuery()->getResult();

        return $resultado;
    }

    /**
     * Lo obligatorio de la configuración, ya comprobado: la entidad y las tres rutas sin las que
     * no hay evento. Mismos mensajes y mismo orden que antes, que es lo que ya conoce quien lee
     * el log.
     *
     * @return array{entidad: string, campos: CamposDeTarifa, start: string, end: string, price: string}
     */
    private function assertConfig(ConfiguracionCalendario $config): array
    {
        $entidad = $config->entidad;
        if ($entidad === null || $entidad === '') {
            throw new HttpException(500, 'tarifa_ranges_raw requiere "entity"');
        }

        $campos = $config->campos ?? throw new HttpException(500, 'tarifa_ranges_raw requiere "fields" (array).');

        return [
            'entidad' => $entidad,
            'campos' => $campos,
            'start' => $campos->start ?? throw new HttpException(500, 'tarifa_ranges_raw requiere fields.start'),
            'end' => $campos->end ?? throw new HttpException(500, 'tarifa_ranges_raw requiere fields.end'),
            'price' => $campos->price ?? throw new HttpException(500, 'tarifa_ranges_raw requiere fields.price'),
        ];
    }

    private function formatTitle(string $format, float $price, int $minStay, ?string $currency, int $priceDecimals): string
    {
        // 1. Calculamos los valores de referencia (Booking 20%, Airbnb 30%)
        // Como te piden mostrar lo que queda (el neto), multiplicamos por 0.80 y 0.70 respectivamente.
        $netoBooking = $price * 0.80;
        $netoAirbnb = $price * 0.70;

        // 2. Formateamos los números según los decimales solicitados
        $strPrice = $this->formatNumber($price, $priceDecimals);
        $strBooking = $this->formatNumber($netoBooking, $priceDecimals);
        $strAirbnb = $this->formatNumber($netoAirbnb, $priceDecimals);

        // 3. Construimos el string exacto que te pidieron:
        // Ejemplo: 52.00 (41.60 | 20% - 36.40 | 30%) 2N
        return sprintf('%s (%s | 20%% - %s | 30%%) %dN',
            $strPrice,
            $strBooking,
            $strAirbnb,
            $minStay
        );
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
        // Tras la guarda de arriba, 0 y 1 existen seguro. El 2 no —«14:30» sin segundos es
        // normal— y por eso ése sí conserva su respaldo.
        return [(int) $parts[0], (int) $parts[1], (int) ($parts[2] ?? $default[2])];
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

    /**
     * El precio de la entidad (un `decimal`, que Doctrine entrega como texto). Mismo resultado que
     * el `(float)` de antes para todo escalar, y `null` es 0; lo que no sea escalar también es 0,
     * en vez de un warning.
     */
    private function aDecimal(mixed $valor): float
    {
        return is_scalar($valor) ? (float) $valor : 0.0;
    }

    /**
     * El id de un recurso tal como lo acepta el DTO. El `Uuid` pasa a texto como antes; cualquier
     * otra cosa que no sea texto, entero o `Stringable` reventaba en el constructor del DTO, y
     * ahora es «sin recurso».
     */
    private function idDeRecurso(mixed $valor): string|int|\Stringable|null
    {
        if ($valor instanceof Uuid) {
            return (string) $valor;
        }

        return is_string($valor) || is_int($valor) || $valor instanceof \Stringable ? $valor : null;
    }
}
