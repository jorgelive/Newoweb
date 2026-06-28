<?php

declare(strict_types=1);

namespace App\Panel\Helper;

/**
 * AdminFieldHelper.
 * Facilita la configuración de campos dependientes en EasyAdmin mediante Stimulus.
 * Optimizado para IDs Naturales (String) y UUIDs (Binary/Hex).
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
     * Aplica atributos para un selector dependiente que se alimenta de API Platform vía AJAX.
     */
    public static function controlsAjax(
        $field,
        string $childClass,
        string $endpointUrl
    ) {
        $controllerName = 'panel--dependent-select-ajax';

        $field->setHtmlAttribute('data-controller', $controllerName);
        $field->setHtmlAttribute('data-action', 'change->' . $controllerName . '#updateUrl');
        $field->setHtmlAttribute('data-' . $controllerName . '-child-class-value', $childClass);
        $field->setHtmlAttribute('data-' . $controllerName . '-url-value', $endpointUrl);

        return $field;
    }

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