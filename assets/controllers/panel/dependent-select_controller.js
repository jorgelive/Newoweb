import { Controller } from '@hotwired/stimulus';

/**
 * Controller: panel--dependent-select
 * Gestiona la lógica de filtrado entre selects dependientes.
 * Compatible con: IDs Naturales (String), UUIDs y IDs clásicos (Int).
 */
export default class extends Controller {
    static values = {
        childSelector: String,
        matchAttr: String,
        operator: String,
        filterBy: String,
        scopeSelector: String
    }

    connect() {
        // Ejecutamos al conectar para aplicar filtros si ya hay valores seleccionados
        this.onChange();
    }

    onChange() {
        const parentValue = this._getParentValue();
        const childSelect = this._getChildSelect();

        if (!childSelect) return;

        // Si el padre no tiene selección, reseteamos el hijo
        if (!parentValue || parentValue === "") {
            this._resetChild(childSelect);
            return;
        }

        let hasValidSelection = false;

        Array.from(childSelect.options).forEach(option => {
            // La opción vacía (placeholder) siempre debe ser visible
            if (option.value === '') {
                option.hidden = false;
                return;
            }

            const childDataValue = option.getAttribute(`data-${this.matchAttrValue}`);
            const isVisible = this._compare(parentValue, childDataValue);

            option.hidden = !isVisible;

            // Verificamos si la opción que estaba seleccionada sigue siendo válida
            if (isVisible && option.selected) {
                hasValidSelection = true;
            }
        });

        // Si la selección previa ya no es válida tras el filtro, reseteamos el valor del hijo
        if (!hasValidSelection) {
            childSelect.value = '';
            // Disparamos el evento para encadenar múltiples niveles de dependencia
            childSelect.dispatchEvent(new Event('change'));
        }
    }

    /**
     * Obtiene el valor del elemento padre según la configuración.
     */
    _getParentValue() {
        if (this.filterByValue === 'value') {
            return this.element.value;
        }
        const selectedOption = this.element.options[this.element.selectedIndex];
        return selectedOption ? selectedOption.getAttribute(this.filterByValue) : null;
    }

    /**
     * Localiza el elemento hijo dentro del ámbito (scope) definido.
     */
    _getChildSelect() {
        const scope = this.element.closest(this.scopeSelectorValue);
        return scope ? scope.querySelector(this.childSelectorValue) : null;
    }

    /**
     * Lógica de comparación de valores.
     * Soporta strings directos, coincidencia parcial y búsqueda en arreglos JSON.
     */
    _compare(parentValue, childValue) {
        if (childValue === null || childValue === undefined) return false;

        switch (this.operatorValue) {
            case 'json_contains':
                try {
                    // Útil para roles: parentValue = 'ROLE_CLEANING', childValue = '["ROLE_USER", "ROLE_CLEANING"]'
                    const array = JSON.parse(childValue);
                    return Array.isArray(array) && array.includes(parentValue);
                } catch (e) {
                    console.warn(`[DependentSelect] Error parsing JSON for value: ${childValue}`);
                    return false;
                }
            case 'like':
                return String(childValue).includes(String(parentValue));
            case 'strict':
            default:
                // Usamos comparación estricta de strings ahora que los IDs son consistentes
                return String(childValue) === String(parentValue);
        }
    }

    /**
     * Limpia el select hijo ocultando todas las opciones excepto el placeholder.
     */
    _resetChild(select) {
        Array.from(select.options).forEach(option => {
            option.hidden = (option.value !== '');
        });
        select.value = '';
        select.dispatchEvent(new Event('change'));
    }
}