import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        title: { type: String, default: '¿Estás completamente seguro?' },
        text: { type: String, default: 'Esta acción no se puede deshacer.' },
        icon: { type: String, default: 'warning' }, // warning, error, success, info, question
        confirmButtonText: { type: String, default: 'Sí, continuar' },
        cancelButtonText: { type: String, default: 'Cancelar' },
        confirmColor: { type: String, default: '#E07845' }, // Tu color naranja corporativo
        cancelColor: { type: String, default: '#64748b' }   // Slate 500
    }

    ask(event) {
        // 1. Detenemos la acción natural (el envío del form o la navegación del enlace)
        event.preventDefault();

        // 2. Disparamos SweetAlert2 con los valores inyectados desde el DOM
        Swal.fire({
            title: this.titleValue,
            text: this.textValue,
            icon: this.iconValue,
            showCancelButton: true,
            confirmButtonColor: this.confirmColorValue,
            cancelButtonColor: this.cancelColorValue,
            confirmButtonText: this.confirmButtonTextValue,
            cancelButtonText: this.cancelButtonTextValue,
            reverseButtons: true // Pone el botón de cancelar a la izquierda (mejor UX)
        }).then((result) => {
            if (result.isConfirmed) {
                this.executeAction();
            }
        });
    }

    executeAction() {
        // Si el controlador está montado en una etiqueta <form>
        if (this.element.tagName === 'FORM') {
            this.element.submit();
        }
        // Si el controlador está montado en una etiqueta <a>
        else if (this.element.tagName === 'A') {
            window.location.href = this.element.href;
        }
        // Si el controlador está montado en un botón suelto dentro de un form
        else if (this.element.closest('form')) {
            this.element.closest('form').submit();
        }
    }
}