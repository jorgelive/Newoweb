import { Controller } from '@hotwired/stimulus';

/**
 * Controlador genérico para campos dentro de una colección.
 * Evalúa si la fila pertenece a un evento OTA y bloquea completamente la interacción
 * (soportando inputs estándar y componentes TomSelect de EasyAdmin).
 */
export default class extends Controller {
    connect() {
        const idBase = this.element.id.substring(0, this.element.id.lastIndexOf('_'));

        const isOtaInput = document.getElementById(`${idBase}_isOta`);
        const refCanalInput = document.getElementById(`${idBase}_referenciaCanal`);

        const isOta = (isOtaInput && isOtaInput.checked) || (refCanalInput && refCanalInput.value.trim() !== '');

        if (isOta) {
            // 1. Bloqueo DOM Estándar (Para Fechas y Textos)
            this.element.setAttribute('readonly', 'readonly');
            this.element.style.pointerEvents = 'none'; // Desactiva clics en calendarios

            // 2. Soporte para TomSelect (Para AssociationFields como Unidad)
            // Usamos un timeout porque EasyAdmin inicializa TomSelect de forma asíncrona
            setTimeout(() => {
                if (this.element.tomselect) {
                    this.element.tomselect.lock();

                    const controlUi = this.element.tomselect.control;
                    controlUi.classList.add('disabled');
                    controlUi.style.backgroundColor = 'var(--form-control-bg-disabled, #e9ecef)';
                    controlUi.style.color = 'var(--form-control-disabled-color, #6c757d)';
                    controlUi.style.cursor = 'not-allowed';
                    controlUi.style.boxShadow = 'none';

                    const arrow = controlUi.querySelector('.ts-arrow');
                    if (arrow) {
                        arrow.style.display = 'none';
                    }
                } else {
                    // Si no es TomSelect, aplicamos estilos de bloqueo estándar a inputs/fechas
                    this.element.style.backgroundColor = 'var(--form-control-bg-disabled, #e9ecef)';
                    this.element.style.color = 'var(--form-control-disabled-color, #6c757d)';
                    this.element.style.cursor = 'not-allowed';
                }
            }, 150);
        }
    }
}