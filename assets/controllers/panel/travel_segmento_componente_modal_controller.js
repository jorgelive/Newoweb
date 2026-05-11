import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    // EL GETTER: Busca la variable inyectada por Symfony en el <head>
    get apiUrl() {
        const metaTag = document.querySelector('meta[name="api-url"]');
        return metaTag ? metaTag.content : '';
    }

    connect() {
        this.catalogo = [];
        this.relId = null;

        this.inyectarModalHtml();

        const collectionItem = this.element.closest('.field-collection-item')
            || this.element.closest('.accordion-item')
            || this.element.closest('fieldset');

        if (collectionItem) {
            const idInput = collectionItem.querySelector('.rel-id-target');
            if (idInput && idInput.value) {
                this.relId = idInput.value;
                this.renderButton();
            }
        }
    }

    /**
     * Dibuja el botón de acceso al Modal justo debajo del input de Orden.
     * Ajustado milimétricamente para no desbordar columnas pequeñas (col-md-3) en pantallas wide.
     */
    renderButton() {
        if (this.element.querySelector('.btn-inline-logistica')) return;

        const btn = document.createElement('button');
        btn.type = 'button';

        // Clases ajustadas: gap-1 en lugar de gap-2 para ahorrar espacio interno
        btn.className = 'btn btn-warning text-dark fw-bold w-100 mt-3 shadow-sm border border-warning rounded d-flex justify-content-center align-items-center gap-1 btn-inline-logistica';

        // Forzamos estilos clave para vencer cualquier override de EasyAdmin y evitar desbordes
        btn.style.padding = '0.5rem'; // Padding más compacto
        btn.style.transition = 'all 0.2s ease-in-out';
        btn.style.textTransform = 'uppercase';
        btn.style.letterSpacing = '0.5px';
        btn.style.fontSize = '0.75rem'; // Fuente responsiva
        btn.style.boxSizing = 'border-box';

        // Efecto hover
        btn.onmouseenter = () => {
            btn.style.transform = 'translateY(-2px)';
            btn.style.boxShadow = '0 4px 8px rgba(0,0,0,0.15)';
            btn.style.filter = 'brightness(1.05)';
        };
        btn.onmouseleave = () => {
            btn.style.transform = 'translateY(0)';
            btn.style.boxShadow = '0 .125rem .25rem rgba(0,0,0,.075)';
            btn.style.filter = 'brightness(1)';
        };

        // El secreto responsivo: flex-shrink-0 protege el icono, text-truncate recorta el texto si falta espacio
        btn.innerHTML = '<i class="fas fa-clock fs-6 flex-shrink-0"></i> <span class="text-truncate">Logística</span>';

        btn.dataset.action = 'click->panel--travel-segmento-componente-modal#abrirModal';

        const formWidget = this.element.querySelector('.form-widget') || this.element;
        formWidget.appendChild(btn);
    }

    async abrirModal(event) {
        event.preventDefault();

        const modalEl = document.getElementById('modalTravelSegmentoComponenteAjax');
        const modal = new bootstrap.Modal(modalEl);

        document.getElementById('tscAddBtn').onclick = (e) => {
            e.preventDefault();
            this.renderRow({});
        };
        document.getElementById('tscSaveBtn').onclick = (e) => this.saveData(e);

        document.getElementById('tscLoader').style.display = 'block';
        document.getElementById('tscTableGeneral').style.display = 'none';
        document.getElementById('tscTable').style.display = 'none';
        document.getElementById('tscTbody').innerHTML = '';
        document.getElementById('tscTbodyGeneral').innerHTML = '';

        modal.show();

        try {
            const res = await fetch(`${this.apiUrl}/travel/user/travel-segmento-componente/${this.relId}`, {
                method: 'GET',
                credentials: 'include'
            });

            if (!res.ok) throw new Error('Error en la respuesta del servidor');

            const json = await res.json();
            this.catalogo = json.catalogo;

            // Dibujar data GENERAL (Solo lectura)
            if (json.dataGeneral && json.dataGeneral.length > 0) {
                document.getElementById('tscTableGeneral').style.display = 'table';
                json.dataGeneral.forEach(row => this.renderRowGeneral(row));
            }

            // Dibujar data ESPECÍFICA (Editable)
            json.data.forEach(row => this.renderRow(row));

            document.getElementById('tscLoader').style.display = 'none';
            document.getElementById('tscTable').style.display = 'table';
        } catch(e) {
            console.error(e);
            alert('Error de autenticación o conexión al cargar la logística del servidor.');
            modal.hide();
        }
    }

    renderRowGeneral(data) {
        const tbody = document.getElementById('tscTbodyGeneral');
        const tr = document.createElement('tr');
        tr.className = 'bg-light text-muted align-middle';

        tr.innerHTML = `
            <td class="text-nowrap"><i class="fas fa-lock me-2 text-secondary" title="Heredado del Párrafo Base"></i> ${data.nombre}</td>
            <td class="text-center text-nowrap">${data.hora}</td>
            <td class="text-center text-nowrap">${data.horaFin}</td>
            <td class="text-center">${data.orden}</td>
            <td class="text-center"><span class="badge bg-secondary border">Base</span></td>
        `;
        tbody.appendChild(tr);
    }

    renderRow(data) {
        const tbody = document.getElementById('tscTbody');
        const tr = document.createElement('tr');
        tr.className = 'tsc-row align-middle';

        let options = '<option value="">-- Seleccionar Insumo --</option>';
        this.catalogo.forEach(c => {
            const sel = data.componenteId === c.id ? 'selected' : '';
            options += `<option value="${c.id}" ${sel}>${c.nombre}</option>`;
        });

        tr.innerHTML = `
            <td><select class="form-select form-select-sm comp-id shadow-none" style="min-width: 180px;">${options}</select></td>
            <td><input type="time" class="form-control form-control-sm comp-ini shadow-none" style="min-width: 110px;" value="${data.hora || ''}"></td>
            <td><input type="time" class="form-control form-control-sm comp-fin shadow-none" style="min-width: 110px;" value="${data.horaFin || ''}"></td>
            <td><input type="number" class="form-control form-control-sm comp-ord text-center shadow-none" style="min-width: 70px;" value="${data.orden || 1}"></td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()" title="Eliminar fila">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    }

    async saveData(event) {
        event.preventDefault();

        const btn = document.getElementById('tscSaveBtn');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
        btn.disabled = true;

        const payload = [];
        document.querySelectorAll('.tsc-row').forEach(tr => {
            const cId = tr.querySelector('.comp-id').value;
            if (cId) {
                payload.push({
                    componenteId: cId,
                    hora: tr.querySelector('.comp-ini').value,
                    horaFin: tr.querySelector('.comp-fin').value,
                    orden: tr.querySelector('.comp-ord').value || 1
                });
            }
        });

        try {
            const res = await fetch(`${this.apiUrl}/travel/user/travel-segmento-componente/${this.relId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify(payload)
            });

            if (!res.ok) throw new Error('Fallo al guardar en el servidor');

            const modalEl = document.getElementById('modalTravelSegmentoComponenteAjax');
            bootstrap.Modal.getInstance(modalEl).hide();

        } catch (e) {
            console.error(e);
            alert('Error al guardar la logística. Revisa la consola para más detalles de CORS o Sesión.');
        } finally {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }

    inyectarModalHtml() {
        if (document.getElementById('modalTravelSegmentoComponenteAjax')) return;

        const html = `
            <div class="modal fade" id="modalTravelSegmentoComponenteAjax" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down">
                    <div class="modal-content border-0 shadow-lg">
                        <div class="modal-header bg-warning text-dark border-0">
                            <h5 class="modal-title fw-bold text-uppercase tracking-wide fs-6 fs-md-5">
                                <i class="fas fa-clock me-2"></i> Logística Operativa del Párrafo
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body bg-light p-3 p-md-4">
                            <div id="tscLoader" class="text-center py-5">
                                <i class="fas fa-circle-notch fa-spin text-warning fs-1"></i>
                                <p class="mt-2 text-muted fw-bold text-uppercase" style="font-size: 11px;">Sincronizando datos...</p>
                            </div>
                            
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-bordered bg-white shadow-sm mb-3" id="tscTableGeneral" style="display:none;">
                                    <thead class="table-light">
                                        <tr>
                                            <th colspan="5" class="text-uppercase text-secondary text-nowrap" style="font-size: 11px; letter-spacing: 1px;">
                                                <i class="fas fa-layer-group me-1"></i> Insumos Base del Párrafo (Solo Lectura)
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="tscTbodyGeneral" style="font-size: 0.85em;"></tbody>
                                </table>

                                <table class="table table-sm table-bordered bg-white shadow-sm mb-0" id="tscTable" style="display:none;">
                                    <thead class="table-light">
                                        <tr>
                                            <th colspan="5" class="text-uppercase text-primary text-nowrap" style="font-size: 11px; letter-spacing: 1px;">
                                                <i class="fas fa-edit me-1"></i> Insumos Específicos de esta Plantilla
                                            </th>
                                        </tr>
                                        <tr>
                                            <th class="text-uppercase text-muted text-nowrap" style="font-size: 11px;">Insumo Logístico / Operador</th>
                                            <th class="text-uppercase text-muted text-nowrap text-center" style="font-size: 11px;">Inicio</th>
                                            <th class="text-uppercase text-muted text-nowrap text-center" style="font-size: 11px;">Fin</th>
                                            <th class="text-uppercase text-muted text-nowrap text-center" style="font-size: 11px;">Orden</th>
                                            <th style="width:50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="tscTbody"></tbody>
                                </table>
                            </div>
                            
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2 fw-bold" id="tscAddBtn">
                                <i class="fas fa-plus me-1"></i> Inyectar Operativa Específica
                            </button>
                        </div>
                        <div class="modal-footer border-top-0 bg-light">
                            <button type="button" class="btn btn-white border shadow-sm" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary fw-bold shadow-sm" id="tscSaveBtn">
                                <i class="fas fa-save me-1"></i> Guardar Logística
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
    }
}