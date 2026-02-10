/* assets/controllers/panel/tinymce_controller.js */
import { Controller } from '@hotwired/stimulus';

/*
 * Uso: <textarea data-controller="panel--tinymce"></textarea>
 */
export default class extends Controller {
    connect() {
        // Generar ID único si falta (necesario para TinyMCE)
        if (!this.element.id) {
            this.element.id = 'tinymce_' + Math.random().toString(36).substr(2, 9);
        }

        this._initTinyMCE();
    }

    disconnect() {
        // LIMPIEZA: Si borras la fila en EasyAdmin, destruimos la instancia
        if (window.tinymce && window.tinymce.get(this.element.id)) {
            window.tinymce.remove('#' + this.element.id);
        }
    }

    _initTinyMCE() {
        // Esperar a que cargue el CDN si aún no está listo
        if (!window.tinymce) {
            setTimeout(() => this._initTinyMCE(), 100);
            return;
        }

        // Evitar doble inicialización
        if (window.tinymce.get(this.element.id)) return;

        window.tinymce.init({
            target: this.element,
            height: 300,
            menubar: false,
            branding: false,
            promotion: false,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview', 'anchor',
                'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'media', 'table', 'help', 'wordcount'
            ],
            toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter | bullist numlist | link image | removeformat | code',

            // Configuración de imagen (Solo URL)
            image_title: true,
            automatic_uploads: false,
            file_picker_types: 'image',
            image_advtab: true,

            // Configuración técnica
            convert_urls: false,
            relative_urls: false,
            remove_script_host: false,
            entity_encoding: "raw",
            entities: "160,nbsp",
            verify_html: false,

            // Sincronización con el textarea original
            setup: (editor) => {
                editor.on('change', () => {
                    editor.save();
                    // Avisar a otros scripts que hubo cambios
                    this.element.dispatchEvent(new Event('input', { bubbles: true }));
                });
            }
        });
    }
}