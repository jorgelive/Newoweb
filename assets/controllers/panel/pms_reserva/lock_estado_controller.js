import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        codigo: String
    }

    connect() {
        const idBase = this.element.id.replace('_estado', '');

        const isOtaInput = document.getElementById(`${idBase}_isOta`);
        const refCanalInput = document.getElementById(`${idBase}_referenciaCanal`);

        const isOta = (isOtaInput && isOtaInput.checked) || (refCanalInput && refCanalInput.value.trim() !== '');

        if (isOta && String(this.element.value) === String(this.codigoValue)) {

            this.element.setAttribute('readonly', 'readonly');
            this.element.classList.add('is-disabled');

            setTimeout(() => {
                if (this.element.tomselect) {
                    this.element.tomselect.lock();

                    const controlUi = this.element.tomselect.control;
                    controlUi.classList.add('disabled');
                    controlUi.style.backgroundColor = 'var(--form-control-bg-disabled, #e9ecef)';
                    controlUi.style.color = 'var(--form-control-disabled-color, #6c757d)';
                    controlUi.style.cursor = 'not-allowed';
                    controlUi.style.boxShadow = 'none';

                    const arrow = this.element.tomselect.control.querySelector('.ts-arrow');
                    if (arrow) {
                        arrow.style.display = 'none';
                    }
                }
            }, 150);
        }
    }
}