// assets/controllers/accordion_filters_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        // Buscamos el botón de filtros globalmente en el documento
        // EasyAdmin le pone la clase .action-filters
        this.filterBtn = document.querySelector('.action-filters');

        if (!this.filterBtn) return;

        // 1. Estado Inicial: Si el acordeón NO tiene la clase 'show', ocultamos el botón
        if (!this.element.classList.contains('show')) {
            this.filterBtn.style.display = 'none';
        }

        // 2. Escuchar eventos de Bootstrap en el elemento colapsable
        // show.bs.collapse -> Se dispara inmediatamente cuando empieza a abrirse
        this.element.addEventListener('show.bs.collapse', this.showFilters);

        // hide.bs.collapse -> Se dispara inmediatamente cuando empieza a cerrarse
        this.element.addEventListener('hide.bs.collapse', this.hideFilters);
    }

    disconnect() {
        // Limpieza de eventos al desconectar (buena práctica)
        if (this.element) {
            this.element.removeEventListener('show.bs.collapse', this.showFilters);
            this.element.removeEventListener('hide.bs.collapse', this.hideFilters);
        }
    }

    showFilters = () => {
        if (this.filterBtn) this.filterBtn.style.display = '';
    }

    hideFilters = () => {
        if (this.filterBtn) this.filterBtn.style.display = 'none';
    }
}