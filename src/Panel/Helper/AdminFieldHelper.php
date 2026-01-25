<?php

namespace App\Panel\Helper;

class AdminFieldHelper
{
    // --- NOMBRE DEL CONTROLADOR DE STIMULUS ---
    // Al estar en assets/controllers/panel/dependent-select_controller.js
    // El nombre cambia a "panel--dependent-select"
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
            // AQUI USAMOS EL NUEVO NOMBRE
            'data-controller' => self::CONTROLLER_NAME,
            // Los values también deben llevar el prefijo del controlador
            'data-action' => 'change->' . self::CONTROLLER_NAME . '#onChange',

            // OJO: Stimulus espera que los values coincidan con el nombre del controlador
            // data-panel--dependent-select-child-selector-value
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