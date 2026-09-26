<?php

declare(strict_types=1);

namespace App\Panel\Helper;

/**
 * AdminFieldHelper.
 * Facilita la configuración de campos dependientes en EasyAdmin mediante Stimulus.
 * Diseñado de forma estrictamente agnóstica y abstracta para ser reutilizado en cualquier módulo del sistema.
 */
class AdminFieldHelper
{
    private const CONTROLLER_NAME = 'panel--dependent-select';

    public const OP_STRICT = 'strict';
    public const OP_JSON   = 'json_contains';
    public const OP_LIKE   = 'like';
    public const SRC_VALUE = 'value';
    public const SCOPE_FORM = '.form-widget-compound';
    public const SCOPE_EA   = '.form-fieldset-body';

    /**
     * Genera la colección de atributos de datos base para el controlador síncrono estándar.
     * @return array<string, string> Los `data-*` que lee el controlador Stimulus.
     */
    public static function getAttributes(
        string $childSelector,
        string $childAttr,
        string $operator = self::OP_STRICT,
        string $parentSource = self::SRC_VALUE,
        ?string $scope = self::SCOPE_FORM
    ): array {
        $attrs = [
            'data-controller' => self::CONTROLLER_NAME,
            'data-action' => 'change->' . self::CONTROLLER_NAME . '#onChange',
            'data-' . self::CONTROLLER_NAME . '-child-selector-value' => $childSelector,
            'data-' . self::CONTROLLER_NAME . '-match-attr-value' => $childAttr,
            'data-' . self::CONTROLLER_NAME . '-operator-value' => $operator,
            'data-' . self::CONTROLLER_NAME . '-filter-by-value' => $parentSource,
        ];

        if ($scope) {
            $attrs['data-' . self::CONTROLLER_NAME . '-scope-selector-value'] = $scope;
        }

        return $attrs;
    }

    /**
     * Aplica atributos de Stimulus para un selector dependiente asíncrono alimentado por AJAX.
     * Desacoplado al 100% de nombres de columnas o lógicas de negocio específicas de las entidades.
     *
     * ⚠️ Genérico: el campo que ENTRA es el que SALE, con su tipo. Con `mixed` de ida y vuelta,
     * lo que se encadenaba detrás —`->setColumns()`, `->hideOnIndex()`— era una llamada sobre
     * `mixed` que el análisis no podía comprobar (nivel 9 de PHPStan).
     *
     * @template T of object
     * @param T $field Instancia del campo de EasyAdmin (ej: AssociationField).
     * @return T
     * @param string $childClass Clase CSS que identifica al elemento select hijo en el DOM.
     * @param string $endpointUrl URL absoluta del endpoint de API Platform a consultar.
     * @param string $paramName Parámetro GET obligatorio que espera la API para filtrar por la entidad padre (ej: 'componente_id', 'proveedor.id').
     * @param string|null $searchParam Parámetro GET opcional para búsquedas dinámicas tipeadas en TomSelect (ej: 'nombreInterno', 'nombre').
     */
    public static function controlsAjax(
        object $field,
        string $childClass,
        string $endpointUrl,
        string $paramName,
        ?string $searchParam = null
    ): object {
        $controllerName = 'panel--dependent-select-ajax';
        // Los campos de EasyAdmin traen `setHtmlAttribute()` por `FieldTrait`, no por su interfaz:
        // se comprueba aquí, que es además lo que le dice al análisis que la llamada existe.
        if (!method_exists($field, 'setHtmlAttribute')) {
            throw new \InvalidArgumentException(sprintf('%s no admite atributos HTML: no es un campo de EasyAdmin.', $field::class));
        }

        $field->setHtmlAttribute('data-controller', $controllerName);
        $field->setHtmlAttribute('data-action', 'change->' . $controllerName . '#updateUrl');
        $field->setHtmlAttribute('data-' . $controllerName . '-child-class-value', $childClass);
        $field->setHtmlAttribute('data-' . $controllerName . '-url-value', $endpointUrl);
        $field->setHtmlAttribute('data-' . $controllerName . '-param-name-value', $paramName);

        if (null !== $searchParam) {
            $field->setHtmlAttribute('data-' . $controllerName . '-search-param-value', $searchParam);
        }

        return $field;
    }

    /**
     * Vincula el comportamiento dependiente local (síncrono) sobre colecciones precargadas en el DOM.
     *
     * @template T of object
     * @param T $field
     * @return T
     */
    public static function controls(
        // ⚠️ `mixed` y no `FieldInterface`: la interfaz de EasyAdmin declara sólo `new()`,
        // `getAsDto()` y `__clone()`. `setHtmlAttribute()` la traen los campos CONCRETOS por
        // `FieldTrait`, así que tipar contra la interfaz compila pero rompe la llamada.
        object $field,
        string $childSelector,
        string $childAttr,
        string $operator = self::OP_STRICT,
        string $parentSource = self::SRC_VALUE,
        ?string $scope = self::SCOPE_EA
    ): object {
        // Los campos de EasyAdmin traen `setHtmlAttribute()` por `FieldTrait`, no por su interfaz:
        // se comprueba aquí, que es además lo que le dice al análisis que la llamada existe.
        if (!method_exists($field, 'setHtmlAttribute')) {
            throw new \InvalidArgumentException(sprintf('%s no admite atributos HTML: no es un campo de EasyAdmin.', $field::class));
        }
        $attrs = self::getAttributes($childSelector, $childAttr, $operator, $parentSource, $scope);
        foreach ($attrs as $key => $value) {
            $field->setHtmlAttribute($key, $value);
        }

        return $field;
    }
}