<?php

declare(strict_types=1);

namespace App\Calendar\Config;

use App\Dto\Lee;

/**
 * La configuración del provider Doctrine «legacy» (`DoctrineCalendarProvider`): la que aplica
 * cuando un calendario trae `entity` y NO trae `provider`.
 *
 * ⚠️ **Ningún calendario del YAML actual la usa** (todos declaran `provider`). Se tipa igual porque
 * el provider sigue enchufado al registro y un calendario nuevo sin `provider` caería en él.
 *
 * ```yaml
 * repositorymethod: findParaCalendario     # opcional: recibe ['from','to','user'], devuelve un QueryBuilder
 * parameters:                              # rutas de getters del evento
 *     start: inicio                        # por defecto 'start'
 *     end: fin                             # por defecto 'end'
 *     id: id                               # por defecto 'id' (también si viene vacío)
 *     title: descripcion                   # por defecto 'title'
 *     tooltip: [descripcion, referenciaCanal]   # o una sola ruta
 *     url: { id: id, edit: {role, route}, show: {role, route} }
 * resource:                                # sin él, un único recurso «Default»
 *     root: pmsUnidad
 *     id: id
 *     title: nombre
 * ```
 */
final class OpcionesDoctrineLegacy
{
    /**
     * @param string|list<string>|null $tooltip Una ruta, o una por línea.
     */
    public function __construct(
        public readonly ?string $metodoRepositorio = null,
        public readonly string $inicio = 'start',
        public readonly string $fin = 'end',
        public readonly string $id = 'id',
        public readonly string $titulo = 'title',
        public readonly ?string $colorTexto = null,
        public readonly ?string $colorFondo = null,
        public readonly ?string $colorBorde = null,
        public readonly ?string $color = null,
        public readonly ?string $clases = null,
        public readonly string|array|null $tooltip = null,
        public readonly ?EnlacesDeEvento $enlaces = null,
        public readonly bool $conRecurso = false,
        public readonly ?string $recursoRaiz = null,
        public readonly string $recursoId = 'id',
        public readonly string $recursoTitulo = 'title',
    ) {}

    /** @param array<mixed> $config La configuración entera del calendario. */
    public static function fromArray(array $config): self
    {
        $p = Lee::mapa($config['parameters'] ?? null);
        $recurso = Lee::mapa($config['resource'] ?? null);
        $tooltip = $p['tooltip'] ?? null;

        return new self(
            metodoRepositorio: self::noVacio($config['repositorymethod'] ?? null),
            inicio: Lee::texto($p['start'] ?? null) ?? 'start',
            fin: Lee::texto($p['end'] ?? null) ?? 'end',
            // `?:` y no `??`: un `id` vacío también caía al de por defecto.
            id: self::noVacio($p['id'] ?? null) ?? 'id',
            titulo: Lee::texto($p['title'] ?? null) ?? 'title',
            colorTexto: Lee::texto($p['textColor'] ?? null),
            colorFondo: Lee::texto($p['backgroundColor'] ?? null),
            colorBorde: Lee::texto($p['borderColor'] ?? null),
            color: Lee::texto($p['color'] ?? null),
            clases: Lee::texto($p['classNames'] ?? null),
            tooltip: is_array($tooltip) ? Lee::listaDeTextos($tooltip) : Lee::texto($tooltip),
            enlaces: is_array($p['url'] ?? null) ? EnlacesDeEvento::fromArray($p['url']) : null,
            // Un bloque vacío es «sin recurso», como el `empty()` del provider.
            conRecurso: $recurso !== [],
            recursoRaiz: self::noVacio($recurso['root'] ?? null),
            recursoId: Lee::texto($recurso['id'] ?? null) ?? 'id',
            recursoTitulo: Lee::texto($recurso['title'] ?? null) ?? 'title',
        );
    }

    /** Texto que no sea «vacío» en el sentido de `empty()`: ni `''` ni `'0'`. */
    private static function noVacio(mixed $valor): ?string
    {
        $texto = Lee::texto($valor);

        return $texto === null || $texto === '' || $texto === '0' ? null : $texto;
    }
}
