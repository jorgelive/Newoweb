<?php
declare(strict_types=1);

namespace App\Calendar\Service;

use App\Calendar\Config\OpcionesDeCatalogo;
use App\Calendar\Dto\CalendarResourceDto;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Catálogo de recursos (filas) del calendario.
 *
 * PROBLEMA QUE RESUELVE
 * ---------------------
 * Todos los providers derivaban los recursos de las ENTIDADES del rango
 * consultado: si una casita no tenía eventos (o rangos de tarifa) entre `from`
 * y `to`, esa fila simplemente desaparecía del calendario. Resultado: no se
 * puede ver la disponibilidad de una unidad vacía ni crear una reserva sobre
 * ella, que es justo el caso en el que más falta hace.
 *
 * Este servicio lista TODAS las unidades del catálogo (por defecto también las
 * marcadas como inactivas) y las fusiona con las derivadas de los eventos.
 *
 * CONFIGURACIÓN (bloque `resources` del calendario, todo opcional)
 * ---------------------------------------------------------------
 *   resources:
 *       showAll: true                          # ACTIVO POR DEFECTO
 *       entity: App\Pms\Entity\PmsUnidad       # si se omite se deduce (ver targetClassOf)
 *       titleField: nombre                     # si se omite se usa __toString()
 *       activeField: activo
 *       activeOnly: false                      # false = también las inactivas
 *       establecimientoField: establecimiento
 *       establecimientoId: null                # filtra por establecimiento si se indica
 *       extraFields:                           # → extendedProps.<clave>
 *           slug: slug
 *           establecimientoSlug: establecimiento.slug
 *
 * Cada recurso del catálogo expone `extendedProps.activo` para que el frontend
 * pueda atenuar/filtrar las inactivas sin volver a preguntar por REST.
 *
 * El bloque llega ya leído en {@see OpcionesDeCatalogo}, con estos valores por defecto.
 */
final class CalendarResourceCatalog
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {}

    /**
     * Fusiona los recursos derivados de los eventos con el catálogo completo,
     * ordena alfabéticamente (orden natural) e inyecta el índice `orden`.
     *
     * El catálogo manda sobre lo derivado: si una unidad está en ambos lados se
     * conserva la versión del catálogo (título canónico + extendedProps.activo).
     *
     * @param list<CalendarResourceDto> $derived recursos deducidos de las entidades del rango
     * @param string|null $defaultEntity clase a usar si el YAML no trae resources.entity
     * @return list<CalendarResourceDto>
     */
    public function merge(array $derived, OpcionesDeCatalogo $opciones, ?string $defaultEntity = null): array
    {
        $out = [];
        $seen = [];

        foreach ($this->fetchCatalog($opciones, $defaultEntity) as $resource) {
            $key = (string) $resource->id;
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $resource;
        }

        foreach ($derived as $resource) {
            $key = (string) $resource->id;
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $resource;
        }

        return $this->sortAndIndex($out);
    }

    /**
     * Ordena por título (orden natural, insensible a mayúsculas) e inyecta el
     * índice `orden`. Extraído de los 6 providers, que repetían este bloque.
     *
     * @param list<CalendarResourceDto> $resources
     * @return list<CalendarResourceDto>
     */
    public function sortAndIndex(array $resources): array
    {
        usort(
            $resources,
            static fn (CalendarResourceDto $a, CalendarResourceDto $b): int => strnatcasecmp($a->title, $b->title)
        );

        $out = [];
        foreach ($resources as $index => $resource) {
            $out[] = new CalendarResourceDto(
                id: $resource->id,
                title: $resource->title,
                orden: $index,
                extendedProps: $resource->extendedProps,
            );
        }

        return $out;
    }

    /**
     * Deduce la clase del recurso a partir de la asociación del provider
     * (p.ej. PmsTarifaRango::$unidad => PmsUnidad), para que `showAll` funcione
     * sin obligar a repetir `resources.entity` en cada calendario del YAML.
     *
     * Sólo resuelve el PRIMER tramo del path: `unidad.id` => la asociación
     * `unidad`. Devuelve null si no hay asociación (el catálogo queda inactivo).
     */
    public function targetClassOf(?string $ownerClass, ?string $associationPath): ?string
    {
        if ($ownerClass === null || $ownerClass === '' || $associationPath === null || $associationPath === '') {
            return null;
        }

        $association = str_contains($associationPath, '.')
            ? explode('.', $associationPath)[0]
            : $associationPath;

        // `getManagerForClass()` pide `class-string`, y esto viene de la configuración del
        // calendario: una clase que no existe se comprueba aquí, no dentro de Doctrine.
        if (!class_exists($ownerClass)) {
            return null;
        }

        $em = $this->managerRegistry->getManagerForClass($ownerClass);
        if (!$em instanceof EntityManagerInterface) {
            return null;
        }

        $meta = $em->getClassMetadata($ownerClass);
        if (!$meta->hasAssociation($association)) {
            return null;
        }

        return $meta->getAssociationTargetClass($association) ?: null;
    }

    /**
     * @return list<CalendarResourceDto>
     */
    private function fetchCatalog(OpcionesDeCatalogo $opciones, ?string $defaultEntity): array
    {
        if (!$opciones->mostrarTodos) {
            return [];
        }

        $entityClass = $opciones->entidad ?? $defaultEntity ?? '';
        if ($entityClass === '' || !class_exists($entityClass)) {
            return [];
        }

        $em = $this->managerRegistry->getManagerForClass($entityClass);
        if (!$em instanceof EntityManagerInterface) {
            return [];
        }

        $meta = $em->getClassMetadata($entityClass);
        $qb = $em->getRepository($entityClass)->createQueryBuilder('u');

        $activeField = $opciones->campoActivo;
        if ($opciones->soloActivos && $meta->hasField($activeField)) {
            $qb->andWhere(sprintf('u.%s = :__activo', $activeField))
                ->setParameter('__activo', true);
        }

        // ⚠️ Ids en TEXTO contra una asociación `binary(16)`: sin tipo de parámetro, un UUID en texto
        // no casa con nada y el catálogo sale vacío sin error (comprobado contra la base local el
        // 26/09/2026: cero unidades con el id real de su establecimiento; ver `CLAUDE.md`). Hoy ningún
        // calendario lo usa (`establecimientoId: null` en todos); si alguno lo necesita, hay que
        // tiparlo aquí antes de fiarse de él.
        $establecimientoField = $opciones->campoEstablecimiento;
        if ($opciones->establecimientoIds !== [] && $meta->hasAssociation($establecimientoField)) {
            $qb->andWhere(sprintf('u.%s IN (:__establecimientos)', $establecimientoField))
                ->setParameter('__establecimientos', $opciones->establecimientoIds);
        }

        $titleField = $opciones->campoTitulo;

        // Campos sueltos que el frontend necesita del recurso y que no son ni el
        // título ni el estado: `extendedProps.<clave> => <ruta de getters>`. Se
        // declaran en el YAML porque son específicos de cada calendario (el de
        // reservas quiere los slugs para enlazar al catálogo público), y este
        // servicio es genérico: no debe saber qué es una PmsUnidad.
        $extraFields = $opciones->camposExtra;

        /** @var list<object> $unidades La clase la dice el YAML: lo único seguro es que es una entidad. */
        $unidades = $qb->getQuery()->getResult();

        $out = [];
        foreach ($unidades as $unit) {
            if (!method_exists($unit, 'getId')) {
                continue;
            }

            $id = $this->toStringOrNull($unit->getId());
            if ($id === null) {
                continue;
            }

            $props = ['activo' => $this->resolveActivo($unit, $activeField)];

            foreach ($extraFields as $clave => $ruta) {
                $props[$clave] = $this->resolveRuta($unit, $ruta);
            }

            $out[] = new CalendarResourceDto(
                id: $id,
                title: $this->resolveTitle($unit, $titleField) ?? ('Recurso ' . $id),
                extendedProps: $props,
            );
        }

        return $out;
    }

    private function resolveTitle(object $unit, string $titleField): ?string
    {
        if ($titleField !== '') {
            $getter = 'get' . ucfirst($titleField);
            if (method_exists($unit, $getter)) {
                $title = $this->toStringOrNull($unit->{$getter}());
                if ($title !== null) {
                    return $title;
                }
            }
        }

        if (method_exists($unit, '__toString')) {
            return $this->toStringOrNull((string) $unit);
        }

        if (method_exists($unit, 'getNombre')) {
            return $this->toStringOrNull($unit->getNombre());
        }

        return null;
    }

    /**
     * Resuelve una ruta de getters separada por puntos: `establecimiento.slug`
     * llama a getEstablecimiento()->getSlug(). Devuelve null en cuanto un tramo
     * no existe o es null, para que un recurso incompleto no reviente el feed.
     */
    private function resolveRuta(object $unit, string $ruta): ?string
    {
        $valor = $unit;

        foreach (explode('.', $ruta) as $tramo) {
            if (!is_object($valor)) {
                return null;
            }

            $getter = 'get' . ucfirst($tramo);
            $isser = 'is' . ucfirst($tramo);

            if (method_exists($valor, $getter)) {
                $valor = $valor->{$getter}();
            } elseif (method_exists($valor, $isser)) {
                $valor = $valor->{$isser}();
            } else {
                return null;
            }
        }

        return $this->toStringOrNull($valor);
    }

    private function resolveActivo(object $unit, string $activeField): bool
    {
        foreach (['is' . ucfirst($activeField), 'get' . ucfirst($activeField)] as $method) {
            if (method_exists($unit, $method)) {
                return (bool) $unit->{$method}();
            }
        }

        // Sin campo de estado, el recurso se considera utilizable.
        return true;
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            $s = (string) $value;

            return $s === '' ? null : $s;
        }

        return null;
    }
}
