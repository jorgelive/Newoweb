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
     * @param mixed $field Instancia del campo de EasyAdmin (ej: AssociationField).
     * @param string $childClass Clase CSS que identifica al elemento select hijo en el DOM.
     * @param string $endpointUrl URL absoluta del endpoint de API Platform a consultar.
     * @param string $paramName Parámetro GET obligatorio que espera la API para filtrar por la entidad padre (ej: 'componente_id', 'proveedor.id').
     * @param string|null $searchParam Parámetro GET opcional para búsquedas dinámicas tipeadas en TomSelect (ej: 'nombreInterno', 'nombre').
     * @return mixed El mismo objeto de campo modificado con los atributos HTML correspondientes.
     */
    public static function controlsAjax(
        $field,
        string $childClass,
        string $endpointUrl,
        string $paramName,
        ?string $searchParam = null
    ) {
        $controllerName = 'panel--dependent-select-ajax';

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
     */
    public static function controls(
        $field,
        string $childSelector,
        string $childAttr,
        string $operator = self::OP_STRICT,
        string $parentSource = self::SRC_VALUE,
        ?string $scope = self::SCOPE_EA
    ) {
        $attrs = self::getAttributes($childSelector, $childAttr, $operator, $parentSource, $scope);
        foreach ($attrs as $key => $value) {
            $field->setHtmlAttribute($key, $value);
        }
        return $field;
    }
}