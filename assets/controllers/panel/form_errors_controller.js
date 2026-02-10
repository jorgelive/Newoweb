import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        const invalid = this.element.querySelector('.is-invalid, .invalid-feedback');
        if (!invalid) return;

        // Abrir panels/accordions colapsados que contengan errores
        this.element.querySelectorAll('.collapse').forEach(collapse => {
            if (collapse.querySelector('.is-invalid, .invalid-feedback')) {
                collapse.classList.add('show');

                const button = this.element.querySelector(
                    `[data-bs-target="#${collapse.id}"]`
                );
                if (button) {
                    button.classList.remove('collapsed');
                    button.setAttribute('aria-expanded', 'true');
                }
            }
        });

        // Scroll suave al primer error
        setTimeout(() => {
            invalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 150);

        // Banner arriba (opcional, puedes quitarlo)
        const banner = document.createElement('div');
        banner.className = 'alert alert-danger shadow-sm mb-3';
        banner.innerHTML = '⚠️ Hay errores de validación. Revisa los campos marcados.';
        this.element.prepend(banner);
    }
}