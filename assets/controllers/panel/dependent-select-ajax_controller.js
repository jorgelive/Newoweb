import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        childClass: String,
        url: String
    }

    connect() {
        this._scheduleFilter(this.element.value);
    }

    updateUrl(event) {
        this._scheduleFilter(event.target.value);
    }

    _scheduleFilter(parentId, attempts = 0) {
        const row = this.element.closest('.row');
        if (!row) return;

        const childSelect = row.querySelector('.' + this.childClassValue);

        if (!childSelect) {
            console.warn('[DependentAjax] Campo hijo no localizado en la fila actual.');
            return;
        }

        if (!childSelect.tomselect) {
            if (attempts < 20) {
                setTimeout(() => this._scheduleFilter(parentId, attempts + 1), 100);
            } else {
                console.error('[DependentAjax] Timeout esperando la inicialización de TomSelect.');
            }
            return;
        }

        this._applyFilter(childSelect, parentId);
    }

    _applyFilter(childSelect, parentId) {
        const ts = childSelect.tomselect;

        ts.clear();
        ts.clearOptions();

        if (!parentId) return;

        const apiUrl = new URL(this.urlValue);
        apiUrl.searchParams.set('componente_id', parentId);

        const fetchData = (query, callback) => {
            const fetchUrl = new URL(apiUrl.toString());

            if (query) {
                fetchUrl.searchParams.set('nombreInterno', query);
            }

            fetch(fetchUrl.toString(), {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'include'
            })
                .then(r => r.ok ? r.json() : [])
                .then(data => {
                    const items = data.member || data.items || (Array.isArray(data) ? data : []);

                    const options = items.map(item => {
                        const id = item.tarifaId || item.id || item['@id'];

                        // 🔥 AHORA LEEMOS LA ETIQUETA COMPLETA DESDE PHP
                        const label = item.etiquetaOpciones || `${item.nombreInterno || 'Sin nombre'} (${item.moneda?.id || ''} ${item.monto || '0.00'})`;

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
                    console.error('[DependentAjax]', err);
                    callback([]);
                });
        };

        // Sobreescribimos el motor de búsqueda nativo
        ts.settings.load = fetchData;

        // Ejecución manual para llenar el dropdown instantáneamente
        fetchData('', (options) => {
            if (options.length > 0) {
                ts.addOption(options);
                ts.refreshOptions(false);
            }
        });
    }
}