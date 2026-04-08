import { Controller } from '@hotwired/stimulus';

/**
 * Controlador para evaluar la regla de negocio de estados terminales OTA.
 * Busca el campo 'isOta' correspondiente a su misma fila en la colección
 * y bloquea el selector de estado si es una OTA cancelada.
 */
export default class extends Controller {
    static values = {
        codigo: String // El ID que representa el estado "Cancelada" (pasado desde PHP)
    }

    connect() {
        // 1. Extraemos la raíz del ID generado por Symfony para esta fila específica.
        // Ejemplo: "PmsReserva_eventosCalendario_0_estado" -> "PmsReserva_eventosCalendario_0"
        const idBase = this.element.id.replace('_estado', '');

        // 2. Buscamos el checkbox 'isOta' de esta misma fila
        const isOtaInput = document.getElementById(`${idBase}_isOta`);

        // Verificamos si existe en el DOM y si está marcado
        const isOta = isOtaInput && isOtaInput.checked;

        // 3. Aplicamos la regla de negocio estricta: SÓLO si es OTA y está Cancelada
        if (isOta && this.element.value === this.codigoValue) {

            // Bloqueo estándar en el DOM
            this.element.setAttribute('readonly', 'readonly');
            this.element.classList.add('is-disabled');

            // Bloqueo a nivel de la instancia de TomSelect (si existe)
            setTimeout(() => {
                if (this.element.tomselect) {
                    this.element.tomselect.lock();
                }
            }, 150);
        }
    }
}