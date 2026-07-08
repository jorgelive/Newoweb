import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        childClass: String,
        url: String,
        paramName: String,
        searchParam: String
    }

    connect() {
        // 🔥 Pasamos 'true' para indicar que es la carga inicial de la página
        this._scheduleFilter(this.element.value, true);
    }

    updateUrl(event) {
        // 🔥 Pasamos 'false' porque el usuario acaba de cambiar el select padre manualmente
        this._scheduleFilter(event.target.value, false);
    }

    _findChildSelect() {
        const selector = 'select.' + this.childClassValue;
        let child = null;

        const row = this.element.closest('.row');
        if (row) child = row.querySelector(selector);
        if (child) return child;

        const compound = this.element.closest('.form-widget-compound, .accordion-item, .form-fieldset');
        if (compound) child = compound.querySelector(selector);
        if (child) return child;

        const form = this.element.closest('form');
        if (form) child = form.querySelector(selector);
        if (child) return child;

        return document.querySelector(selector);
    }

    _scheduleFilter(parentId, isInitialLoad = false, attempts = 0) {
        const childSelect = this._findChildSelect();

        if (!childSelect) {
            console.warn(`[DependentAjax] No se encontró el elemento en el DOM: select.${this.childClassValue}`);
            return;
        }

        if (!childSelect.tomselect) {
            if (attempts < 20) {
                setTimeout(() => this._scheduleFilter(parentId, isInitialLoad, attempts + 1), 100);
            } else {
                console.error('[DependentAjax] Timeout: Se localizó el select, pero TomSelect no se inicializó.');
            }
            return;
        }

        this._applyFilter(childSelect, parentId, isInitialLoad);
    }

    _applyFilter(childSelect, parentId, isInitialLoad) {
        const ts = childSelect.tomselect;

        // 🔥 CAPTURAMOS EL VALOR GUARDADO EN BASE DE DATOS ANTES DE BORRAR
        let valueToRestore = null;
        if (isInitialLoad) {
            valueToRestore = ts.getValue();
        }

        // Limpieza silenciosa (true = no dispara el evento 'change' en cascada)
        ts.clear(true);
        ts.clearOptions();

        if (!parentId) return;

        const apiUrl = new URL(this.urlValue);
        apiUrl.searchParams.set(this.paramNameValue, parentId);

        const fetchData = (query, callback) => {
            const fetchUrl = new URL(apiUrl.toString());

            if (query && this.hasSearchParamValue) {
                fetchUrl.searchParams.set(this.searchParamValue, query);
            }

            fetch(fetchUrl.toString(), {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'include'
            })
                .then(r => r.ok ? r.json() : [])
                .then(data => {
                    const items = data['hydra:member'] || data.member || data.items || (Array.isArray(data) ? data : []);

                    const options = items.map(item => {
                        const id = item.tarifaId || item.proveedorServicioId || item.id || item['@id'];
                        const label = item.etiquetaOpciones || item.nombreInterno || item.nombre || 'Opción sin nombre';

                        return {
                            value: id,
                            text: label,
                            entityId: id,
                            entityAsString: label
                        };
                    });

                    callback(options);
                })
                .catch(err => {
                    console.error('[DependentAjax] Error de red:', err);
                    callback([]);
                });
        };

        ts.settings.load = fetchData;

        fetchData('', (options) => {
            if (options.length > 0) {
                ts.addOption(options);

                // 🔥 RESTAURAMOS EL VALOR ORIGINAL SI ESTAMOS EN CARGA INICIAL
                if (isInitialLoad && valueToRestore) {
                    ts.setValue(valueToRestore, true);
                }

                ts.sync();
            }
        });
    }
}