import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        const idBase = this.element.id.substring(0, this.element.id.lastIndexOf('_'));

        const isOtaInput = document.getElementById(`${idBase}_isOta`);
        const refCanalInput = document.getElementById(`${idBase}_referenciaCanal`);

        const isOta = (isOtaInput && isOtaInput.checked) || (refCanalInput && refCanalInput.value.trim() !== '');

        if (isOta) {
            this.element.setAttribute('readonly', 'readonly');
            this.element.style.backgroundColor = 'var(--form-control-bg-disabled, #e9ecef)';
            this.element.style.color = 'var(--form-control-disabled-color, #6c757d)';
            this.element.style.cursor = 'not-allowed';
            this.element.style.pointerEvents = 'none';
        }
    }
}