// assets/controllers/panel/accordion_toggle_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["item"];

    connect() {
        // Registramos el evento en cada ítem de la colección
        this.itemTargets.forEach((el) => {
            el.addEventListener('hidden.bs.collapse', (event) => {
                // Evitamos que eventos de elementos anidados (sub-acordeones) disparen la lógica
                if (event.target !== el) return;

                this._openNext(el);
            });
        });
    }

    /**
     * Abre el siguiente elemento en el ciclo.
     * @param {HTMLElement} currentEl - El elemento que se acaba de cerrar.
     */
    _openNext(currentEl) {
        const items = this.itemTargets;
        const currentIndex = items.indexOf(currentEl);

        // Calculamos el índice del siguiente (con retorno al inicio mediante módulo)
        const nextIndex = (currentIndex + 1) % items.length;
        const nextEl = items[nextIndex];

        if (nextEl) {
            const collapse = bootstrap.Collapse.getOrCreateInstance(nextEl);
            collapse.show();
        }
    }
}