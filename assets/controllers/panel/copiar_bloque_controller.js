/* assets/controllers/panel/copiar_bloque_controller.js */
import { Controller } from '@hotwired/stimulus';

/*
 * Copiar de un tirón el contenido de un bloque de datos crudos.
 *
 * Uso: <div data-controller="panel--copiar-bloque"> … <pre data-panel--copiar-bloque-target="fuente">
 *
 * ⚠️ **Existe porque «Seleccionar todo» del menú del navegador selecciona el DOCUMENTO.** Al
 * devolver el menú nativo —lo que hizo que copiar y pegar volvieran a funcionar— se hereda esa
 * regla: el navegador no sabe que el payload es una unidad, así que marca la página entera y lo
 * copiado sale con la cabecera, el menú lateral y las etiquetas del panel. No es algo que el CSS
 * pueda acotar: `user-select: all` lo arreglaría a cambio de impedir seleccionar un trozo, que es
 * justo lo que se hace la mitad de las veces.
 *
 * El botón va al objetivo real —llevarse el dato— sin tocar nada de lo nativo: la selección con
 * pulsación larga, arrastrar para leer y el scroll horizontal siguen exactamente igual.
 */
export default class extends Controller {
    static targets = ['fuente', 'boton'];

    async copiar() {
        const texto = this.fuenteTarget.textContent ?? '';

        try {
            await navigator.clipboard.writeText(texto);
            this._avisar('¡Copiado!', true);
        } catch {
            // El portapapeles exige contexto seguro y permiso. Cuando no lo hay, en vez de un
            // error se deja el bloque SELECCIONADO: el usuario remata con Ctrl+C o con «Copiar»
            // del menú del sistema, que sobre una selección ya hecha sí funciona.
            this._seleccionar();
            this._avisar('Seleccionado — copia con Ctrl+C', false);
        }
    }

    /** Marca el bloque entero, y sólo el bloque. */
    _seleccionar() {
        const seleccion = window.getSelection();
        if (!seleccion) return;

        const rango = document.createRange();
        rango.selectNodeContents(this.fuenteTarget);
        seleccion.removeAllRanges();
        seleccion.addRange(rango);
    }

    _avisar(texto, ok) {
        if (!this.hasBotonTarget) return;

        const original = this.botonTarget.dataset.textoOriginal ?? this.botonTarget.textContent;
        this.botonTarget.dataset.textoOriginal = original;
        this.botonTarget.textContent = texto;
        this.botonTarget.classList.toggle('is-ok', ok);

        window.setTimeout(() => {
            this.botonTarget.textContent = this.botonTarget.dataset.textoOriginal;
            this.botonTarget.classList.remove('is-ok');
        }, 1800);
    }
}
