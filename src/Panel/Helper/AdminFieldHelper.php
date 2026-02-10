<?php

declare(strict_types=1);

namespace App\Panel\Helper;

/**
 * AdminFieldHelper.
 * * Facilita la configuración de campos dependientes en EasyAdmin mediante Stimulus.
 * Optimizado para IDs Naturales (String) y UUIDs (Binary/Hex).
 */
class AdminFieldHelper
{
    /**
     * Nombre del controlador en assets/controllers/panel/dependent-select_controller.js
     */
    private const CONTROLLER_NAME = 'panel--dependent-select';

    // Operadores de comparación en el JS
    public const OP_STRICT = 'strict';
    public const OP_JSON   = 'json_contains';
    public const OP_LIKE   = 'like';

    // Origen del dato en el elemento padre
    public const SRC_VALUE = 'value'; // El valor del <select>

    // Selectores de ámbito para buscar el campo hijo
    public const SCOPE_FORM = '.form-widget-compound';
    public const SCOPE_EA   = '.form-fieldset-body';

    /**
     * Genera el array de atributos data-* para el controlador Stimulus.
     * * @param string $childSelector Selector CSS del campo que será filtrado (ej: '.js-pms-user-target')
     * @param string $childAttr Atributo en el hijo que contiene los criterios (ej: 'user-roles')
     * @param string $operator Método de comparación (strict, json_contains, like)
     * @param string $parentSource Atributo del padre de donde sacar el valor filtro (default: value)
     * @param string|null $scope Contenedor común para limitar la búsqueda del hijo
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

            // Valores de configuración para el controlador
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
     * Aplica los atributos directamente a un campo de EasyAdmin.
     * * @param mixed $field El objeto de campo de EasyAdmin (TextField, ChoiceField, etc.)
     */
    public static function controls(
        $field,
        string $childSelector,
        string $childAttr,
        string $operator = self::OP_STRICT,
        string $parentSource = self::SRC_VALUE,
        ?string $scope = self::SCOPE_EA
    ) {
        $attrs = self::getAttributes(
            $childSelector,
            $childAttr,
            $operator,
            $parentSource,
            $scope
        );

        foreach ($attrs as $key => $value) {
            $field->setHtmlAttribute($key, $value);
        }

        return $field;
    }
}