import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/*
 * Controlador GENÉRICO para Carga Masiva (Versión Final)
 * - Integra TomSelect
 * - Soluciona el bucle de clics del explorador de archivos
 */
export default class extends Controller {
    static targets = ["selector", "dropzone", "input", "errorMsg", "log"];

    static values = {
        url: String,
        paramName: { type: String, default: 'item_id' },
        fileParam: { type: String, default: 'file' }
    };

    connect() {
        if (!this.hasUrlValue) return;

        // 1. Iniciar TomSelect con configuración visual de EasyAdmin
        this.initTomSelect();

        // 2. Estado inicial
        this.disableDropzone();
    }

    disconnect() {
        if (this.tsInstance) this.tsInstance.destroy();
    }

    /* ======================================================
     * LÓGICA TOM SELECT
     * ====================================================== */
    initTomSelect() {
        this.tsInstance = new TomSelect(this.selectorTarget, {
            create: false,
            sortField: { field: "text", direction: "asc" },
            placeholder: "Buscar destino...",
            plugins: ['dropdown_input'],
            onChange: (value) => {
                this.onSelectChange(value);
            }
        });
    }

    onSelectChange(value) {
        if (value) {
            this.enableDropzone();
            this.hideError();
        } else {
            this.disableDropzone();
        }
    }

    /* ======================================================
     * EVENTOS DE INTERACCIÓN (EL FIX DEL CLICK)
     * ====================================================== */

    triggerInputClick(event) {
        // 🛑 FIX CRÍTICO: Detener el bucle infinito.
        // Si el clic viene del propio input (que está dentro del div), no hacemos nada.
        if (event.target === this.inputTarget) {
            return;
        }

        if(event) event.preventDefault();

        if (this.isDropzoneEnabled()) {
            // Disparamos el clic en el input fantasma
            this.inputTarget.click();
        } else {
            this.showError('Selecciona una opción de la lista primero.');
            // Hacemos foco en el buscador de TomSelect
            if(this.tsInstance) this.tsInstance.focus();
        }
    }

    onInputChange(event) {
        if (event.target.files.length > 0) {
            this.handleUploadProcess(event.target.files);
        }
    }

    onDragOver(event) {
        event.preventDefault();
        if (this.isDropzoneEnabled()) {
            this.dropzoneTarget.classList.add('drag-over');
        }
    }

    onDragLeave(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove('drag-over');
    }

    onDrop(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove('drag-over');

        if (this.isDropzoneEnabled()) {
            this.handleUploadProcess(event.dataTransfer.files);
        } else {
            this.showError('Primero selecciona un Ítem de la lista.');
        }
    }

    /* ======================================================
     * LÓGICA DE SUBIDA (AJAX)
     * ====================================================== */

    async handleUploadProcess(files) {
        const idValue = this.selectorTarget.value;
        if (!idValue) return;

        for (const file of files) {
            await this.uploadSingleFile(file, idValue);
        }

        // Limpiamos el input para permitir subir el mismo archivo de nuevo si se desea
        this.inputTarget.value = '';
    }

    async uploadSingleFile(file, idValue) {
        // Crear log visual
        const logEntry = document.createElement('div');
        logEntry.className = 'd-flex align-items-center p-2 border-bottom bg-white';
        logEntry.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-primary me-2"></i> ${file.name}`;
        this.logTarget.prepend(logEntry);

        const formData = new FormData();
        formData.append(this.fileParamValue, file);
        formData.append(this.paramNameValue, idValue);

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) throw new Error(response.statusText);

            // Éxito
            logEntry.className = 'd-flex align-items-center p-2 border-bottom bg-success-subtle';
            logEntry.innerHTML = `<i class="fa-solid fa-check text-success me-2"></i> <strong>${file.name}</strong> <span class="badge bg-success ms-auto">OK</span>`;

        } catch (error) {
            // Error
            logEntry.className = 'd-flex align-items-center p-2 border-bottom bg-danger-subtle';
            logEntry.innerHTML = `<i class="fa-solid fa-triangle-exclamation text-danger me-2"></i> ${file.name} <span class="badge bg-danger ms-auto">Error</span>`;
            console.error(error);
        }
    }

    /* ======================================================
     * UTILIDADES
     * ====================================================== */

    isDropzoneEnabled() {
        return this.selectorTarget.value !== "";
    }

    enableDropzone() {
        this.dropzoneTarget.classList.remove('disabled');
        this.dropzoneTarget.style.opacity = '1';
        this.dropzoneTarget.style.cursor = 'pointer';
        this.dropzoneTarget.classList.add('border-primary');
    }

    disableDropzone() {
        this.dropzoneTarget.classList.add('disabled');
        this.dropzoneTarget.style.opacity = '0.6';
        this.dropzoneTarget.style.cursor = 'not-allowed';
        this.dropzoneTarget.classList.remove('border-primary');
    }

    showError(msg) {
        this.errorMsgTarget.textContent = msg;
        this.errorMsgTarget.classList.remove('d-none');
    }

    hideError() {
        this.errorMsgTarget.classList.add('d-none');
    }
}