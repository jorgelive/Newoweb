import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        // Encontramos el contenedor del acordeón
        const collectionItem = this.element.closest('.field-collection-item');
        if (!collectionItem) return;

        // 1. Eliminamos el botón de borrar
        const removeButton = collectionItem.querySelector('.field-collection-delete-button');
        if (removeButton) removeButton.remove();

        // 2. Aplicamos estilo de bloqueo
        collectionItem.style.borderLeft = '5px solid #ffc107';
        collectionItem.style.backgroundColor = '#fffdf0';

        // 3. Arreglamos el header del acordeón para que no quede el hueco feo
        const accordionButton = collectionItem.querySelector('.accordion-button');
        if (accordionButton) {
            accordionButton.style.boxShadow = 'none';
            accordionButton.style.borderRight = 'none';
            accordionButton.style.borderTopRightRadius = 'var(--bs-accordion-border-radius, 0.25rem)';
            accordionButton.style.borderBottomRightRadius = 'var(--bs-accordion-border-radius, 0.25rem)';
            accordionButton.style.paddingRight = '1.25rem';
        }
    }
}