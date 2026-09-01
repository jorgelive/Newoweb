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
                'media', 'table', 'help', 'wordcount', 'quickbars'
            ],
            toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter | bullist numlist | link image | removeformat | code',

            // ── La barra flotante que sale al seleccionar texto ──────────────
            //
            // ⚠️ **Antes salía sólo «Link… ⌘K», que es lo que menos se usa aquí.** No era una
            // configuración: `quickbars` NO estaba cargado, así que lo único flotante era el
            // context toolbar que el plugin `link` trae de serie. Sin `quickbars` en la lista de
            // arriba, estas tres opciones no hacen nada.
            //
            // El repertorio sale de lo que se escribe de verdad en estos campos —el cuerpo del
            // relato de un segmento— que son listas de viñetas con negritas y emojis. Por eso
            // negrita, cursiva y viñetas van primero y el enlace queda al final: sigue estando,
            // pero deja de ser lo único.
            quickbars_selection_toolbar: 'bold italic | bullist numlist | removeformat | quicklink',

            // La barra de «insertar» que aparece al poner el cursor en una línea VACÍA ofrece
            // tabla e imagen. En un relato estorba: aparece sola mientras se redacta.
            quickbars_insert_toolbar: false,
            quickbars_image_toolbar: false,

            // ── Clic derecho / pulsación larga: el menú del NAVEGADOR ────────
            //
            // ⚠️ **Devolver el menú nativo es lo único que da copiar y pegar de verdad.** El menú
            // propio de TinyMCE los trae, pero **no funcionan**: los navegadores llevan años sin
            // dejar tocar el portapapeles desde un menú, y el propio editor lleva dentro el aviso
            // de consolación —«Your browser doesn't support direct access to the clipboard. Please
            // use the Ctrl+X/C/V keyboard shortcuts instead»—. O sea que ponerlos ahí sería
            // prometer tres acciones y cumplir cero.
            //
            // El default era `link linkchecker image editimage table spellchecker
            // configurepermanentpen`, y de esos siete aquí sólo existen `link` e `image`: sin una
            // imagen seleccionada el menú se quedaba en **un solo elemento, «Link…»**. No era que
            // el enlace estuviera elegido, es que era lo único que sobrevivía.
            //
            // Con `false` el menú se queda vacío y pasa el del sistema: seleccionar todo, copiar,
            // pegar, corrector ortográfico, traducir. El formato sigue a un gesto de distancia en
            // la barra flotante de arriba, que es la que sí puede hacer su trabajo.
            contextmenu: false,

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