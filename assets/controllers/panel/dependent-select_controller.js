import { Controller } from '@hotwired/stimulus';

// Ubicación: assets/controllers/panel/dependent-select_controller.js
// Identificador Stimulus: panel--dependent-select

export default class extends Controller {
    static values = {
        childSelector: String,
        matchAttr: String,
        operator: String,
        filterBy: String,
        scopeSelector: String
    }

    connect() {
        this.onChange();
    }

    onChange() {
        const parentValue = this._getParentValue();
        const childSelect = this._getChildSelect();

        if (!childSelect) return;

        if (!parentValue) {
            this._resetChild(childSelect);
            return;
        }

        let hasValidSelection = false;

        Array.from(childSelect.options).forEach(option => {
            if (option.value === '') {
                option.hidden = false;
                return;
            }

            const childDataValue = option.getAttribute(`data-${this.matchAttrValue}`);
            const isVisible = this._compare(parentValue, childDataValue);

            option.hidden = !isVisible;

            if (isVisible && option.selected) {
                hasValidSelection = true;
            }
        });

        if (!hasValidSelection) {
            childSelect.value = '';
            childSelect.dispatchEvent(new Event('change'));
        }
    }

    _getParentValue() {
        if (this.filterByValue === 'value') {
            return this.element.value;
        }
        const selectedOption = this.element.options[this.element.selectedIndex];
        return selectedOption ? selectedOption.getAttribute(this.filterByValue) : null;
    }

    _getChildSelect() {
        const scope = this.element.closest(this.scopeSelectorValue);
        return scope ? scope.querySelector(this.childSelectorValue) : null;
    }

    _compare(parentValue, childValue) {
        if (!childValue) return false;

        switch (this.operatorValue) {
            case 'json_contains':
                try {
                    const array = JSON.parse(childValue);
                    return Array.isArray(array) && array.includes(parentValue);
                } catch (e) {
                    return false;
                }
            case 'like':
                return childValue.includes(parentValue);
            case 'strict':
            default:
                return childValue == parentValue;
        }
    }

    _resetChild(select) {
        Array.from(select.options).forEach(option => option.hidden = option.value !== '');
        select.value = '';
        select.dispatchEvent(new Event('change'));
    }
}