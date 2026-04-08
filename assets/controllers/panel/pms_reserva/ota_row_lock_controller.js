import { Controller } from '@hotwired/stimulus';

/**
 * Controlador genérico para campos estándar (inputs, dates, numbers) dentro de una colección.
 * Evalúa si la fila pertenece a un evento OTA y bloquea completamente la interacción
 * y el aspecto visual del campo para garantizar su inmutabilidad.
 */
export default class extends Controller {
    connect() {
        // Obtenemos la raíz del ID de forma dinámica.
        // Ej: "PmsReserva_eventosCalendario_0_inicio" -> corta en el último "_" -> "PmsReserva_eventosCalendario_0"
        const idBase = this.element.id.substring(0, this.element.id.lastIndexOf('_'));

        const isOtaInput = document.getElementById(`${idBase}_isOta`);
        const isOta = isOtaInput && isOtaInput.checked;

        if (isOta) {
            // Bloqueo DOM estándar
            this.element.setAttribute('readonly', 'readonly');

            // Maquillaje visual (colores de EasyAdmin para campos deshabilitados)
            this.element.style.backgroundColor = 'var(--form-control-bg-disabled, #e9ecef)';
            this.element.style.color = 'var(--form-control-disabled-color, #6c757d)';
            this.element.style.cursor = 'not-allowed';

            // 🔥 TRUCO VITAL: En los inputs type="date/datetime", el 'readonly' a veces
            // no bloquea el clic en el ícono del calendario. Esto desactiva el mouse por completo.
            this.element.style.pointerEvents = 'none';
        }
    }
}