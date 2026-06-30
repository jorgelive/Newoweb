import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';

export default class extends Controller {

    get apiUrl() {
        const metaTag = document.querySelector('meta[name="api-url"]');
        return metaTag ? metaTag.content : '';
    }

    connect() {
        this.catalogo = [];
        this.relId = null;
        this.inyectarModalHtml();

        const collectionItem = this.element.closest('.field-collection-item') || this.element.closest('.accordion-item') || this.element.closest('fieldset');
        if (collectionItem) {
            const idInput = collectionItem.querySelector('.rel-id-target');
            if (idInput && idInput.value) {
                this.relId = idInput.value;
                this.renderButton();
            }
        }
    }

    renderButton() {
        if (this.element.querySelector('.btn-inline-logistica')) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-warning text-dark fw-bold w-100 mt-3 shadow-sm border border-warning rounded d-flex justify-content-center align-items-center gap-1 btn-inline-logistica';
        btn.style.padding = '0.5rem';
        btn.style.transition = 'all 0.2s ease-in-out';
        btn.style.textTransform = 'uppercase';
        btn.style.letterSpacing = '0.5px';
        btn.style.fontSize = '0.75rem';
        btn.style.boxSizing = 'border-box';

        btn.onmouseenter = () => { btn.style.transform = 'translateY(-2px)'; btn.style.boxShadow = '0 4px 8px rgba(0,0,0,0.15)'; btn.style.filter = 'brightness(1.05)'; };
        btn.onmouseleave = () => { btn.style.transform = 'translateY(0)'; btn.style.boxShadow = '0 .125rem .25rem rgba(0,0,0,.075)'; btn.style.filter = 'brightness(1)'; };

        btn.innerHTML = '<i class="fas fa-clock fs-6 flex-shrink-0"></i> <span class="text-truncate">Logística</span>';
        btn.dataset.action = 'click->panel--travel-segmento-componente-modal#abrirModal';

        const formWidget = this.element.querySelector('.form-widget') || this.element;
        formWidget.appendChild(btn);
    }

    async abrirModal(event) {
        event.preventDefault();
        const modalEl = document.getElementById('modalTravelSegmentoComponenteAjax');
        const modal = new bootstrap.Modal(modalEl, { focus: false }); // 🔥 Evita que Bootstrap secuestre el foco

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
            const res = await fetch(`${this.apiUrl}/travel/user/travel-segmento-componente/${this.relId}`, { method: 'GET', credentials: 'include' });
            if (!res.ok) throw new Error('Error en la respuesta del servidor');
            const json = await res.json();
            this.catalogo = json.catalogo;

            if (json.dataGeneral && json.dataGeneral.length > 0) {
                document.getElementById('tscTableGeneral').style.display = 'table';
                json.dataGeneral.forEach(row => this.renderRowGeneral(row));
            }

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
            <td class="text-nowrap p-2"><i class="fas fa-lock me-2 text-secondary"></i> ${data.nombre}</td>
            <td class="text-muted p-2" style="font-size: 0.85em;">${data.tarifaNombre || 'Auto / Varias'}</td>
            <td class="text-center p-2">${data.dia || '-'}</td> <td class="text-center text-nowrap p-2">${data.hora || '--:--'}</td>
            <td class="text-center text-nowrap p-2">${data.horaFin || '--:--'}</td>
            <td class="text-center text-nowrap text-uppercase p-2" style="font-size: 0.85em;">${data.modo || 'INCLUIDO'}</td>
            <td class="text-center p-2">${data.orden || 1}</td>
            <td class="text-center p-2"><span class="badge bg-secondary border">Base</span></td>
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

        // Construcción inicial del Select de Tarifas
        let tarifaOptions = '<option value="">Automático / Varias</option>';
        if (data.componenteId) {
            const compSelect = this.catalogo.find(c => c.id === data.componenteId);
            if (compSelect && compSelect.tarifas) {
                compSelect.tarifas.forEach(t => {
                    const sel = data.tarifaId === t.id ? 'selected' : '';
                    tarifaOptions += `<option value="${t.id}" ${sel}>${t.nombre}</option>`;
                });
            }
        }

        const modoActual = data.modo || 'incluido';
        const opcionesModo = `
            <option value="incluido" ${modoActual === 'incluido' ? 'selected' : ''}>Incluido</option>
            <option value="opcional" ${modoActual === 'opcional' ? 'selected' : ''}>Opcional</option>
            <option value="no_incluido" ${modoActual === 'no_incluido' ? 'selected' : ''}>No Incluido</option>
            <option value="cortesia" ${modoActual === 'cortesia' ? 'selected' : ''}>Cortesía</option>
        `;

        tr.innerHTML = `
            <td class="p-1">
                <select class="form-select form-select-sm comp-id shadow-none tom-select-target w-100">${options}</select>
            </td>
            <td class="p-1">
                <select class="form-select form-select-sm comp-tarifa shadow-none w-100">${tarifaOptions}</select>
            </td>
            <td class="p-1">
                <input type="number" min="1" class="form-control form-control-sm comp-dia text-center shadow-none w-100" value="${data.dia || 1}">
            </td>
            <td class="p-1">
                <input type="text" class="form-control form-control-sm comp-ini shadow-none text-center flatpickr-time bg-white w-100" value="${data.hora || ''}" placeholder="HH:MM">
            </td>
            <td class="p-1">
                <input type="text" class="form-control form-control-sm comp-fin shadow-none text-center flatpickr-time bg-white w-100" value="${data.horaFin || ''}" placeholder="HH:MM">
            </td>
            <td class="p-1">
                <select class="form-select form-select-sm comp-modo shadow-none text-center w-100">${opcionesModo}</select>
            </td>
            <td class="p-1">
                <input type="number" class="form-control form-control-sm comp-ord text-center shadow-none w-100" value="${data.orden || 1}">
            </td>
            <td class="p-1 text-center">
                <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="this.closest('tr').remove()" title="Eliminar fila">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);

        // 🔥 INICIALIZACIÓN DE TOM-SELECT
        const selectElement = tr.querySelector('.tom-select-target');
        if (selectElement) {
            const controller = this; // 🔥 Guardamos el scope de Stimulus

            new TomSelect(selectElement, {
                create: false,
                plugins: ['dropdown_input'],
                sortField: { field: "text", direction: "asc" },
                placeholder: "Buscar insumo logístico...",
                dropdownParent: 'body',
                render: { no_results: function(data, escape) { return '<div class="no-results p-2 text-muted">No se encontraron resultados</div>'; } },

                onChange: function(value) { // 🔥 function() normal para no perder el 'this' de TomSelect
                    const tarifaSelect = tr.querySelector('.comp-tarifa');
                    tarifaSelect.innerHTML = '<option value="">Automático / Varias</option>';
                    if (value) {
                        // Usamos la variable 'controller' para acceder a catalogo
                        const comp = controller.catalogo.find(c => c.id === value);
                        if (comp && comp.tarifas) {
                            comp.tarifas.forEach(t => { tarifaSelect.innerHTML += `<option value="${t.id}">${t.nombre}</option>`; });
                        }
                    }
                    this.blur(); // Ahora sí ocultará el menú al seleccionar sin errores
                }
            });
        }

        // 🔥 INICIALIZACIÓN DE FLATPICKR
        const timeInputs = tr.querySelectorAll('.flatpickr-time');
        timeInputs.forEach(input => {
            flatpickr(input, {
                enableTime: true,
                noCalendar: true,
                dateFormat: "H:i",
                time_24hr: true,
                allowInput: true,
                onReady: function(selectedDates, dateStr, instance) {
                    instance.calendarContainer.style.width = "100px";
                    instance.calendarContainer.style.minWidth = "100px";
                }
            });
        });
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
                    tarifaId: tr.querySelector('.comp-tarifa').value || null,
                    dia: tr.querySelector('.comp-dia').value || null,
                    hora: tr.querySelector('.comp-ini').value,
                    horaFin: tr.querySelector('.comp-fin').value,
                    modo: tr.querySelector('.comp-modo').value,
                    orden: tr.querySelector('.comp-ord').value || 1
                });
            }
        });

        try {
            const res = await fetch(`${this.apiUrl}/travel/user/travel-segmento-componente/${this.relId}`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify(payload)
            });
            if (!res.ok) throw new Error('Fallo al guardar en el servidor');
            bootstrap.Modal.getInstance(document.getElementById('modalTravelSegmentoComponenteAjax')).hide();
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
            <style>
                .ts-wrapper.focus { z-index: 9999 !important; }
                .ts-dropdown { z-index: 9999 !important; }
                .flatpickr-calendar { z-index: 99999 !important; }
                #modalTravelSegmentoComponenteAjax .table-responsive { overflow: visible !important; }
                #modalTravelSegmentoComponenteAjax .modal-body { z-index: 10; position: relative; }
                #modalTravelSegmentoComponenteAjax .modal-footer { z-index: 1; position: relative; }
            </style>
            
            <div class="modal fade" id="modalTravelSegmentoComponenteAjax" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down" style="max-width: 1400px;">
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
                            
                            <div class="table-responsive mb-3 pb-5">
                                <table class="table table-sm table-bordered bg-white shadow-sm mb-3" id="tscTableGeneral" style="display:none;">
                                    <thead class="table-light">
                                        <tr>
                                            <th colspan="7" class="text-uppercase text-secondary text-nowrap" style="font-size: 11px; letter-spacing: 1px;">
                                                <i class="fas fa-layer-group me-1"></i> Insumos Base del Párrafo (Solo Lectura)
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="tscTbodyGeneral" style="font-size: 0.85em;"></tbody>
                                </table>

                                <table class="table table-sm table-bordered bg-white shadow-sm mb-0" id="tscTable" style="display:none;">
                                    <thead class="table-light">
                                        <tr>
                                            <th colspan="7" class="text-uppercase text-primary text-nowrap" style="font-size: 11px; letter-spacing: 1px;">
                                                <i class="fas fa-edit me-1"></i> Insumos Específicos de esta Plantilla
                                            </th>
                                        </tr>
                                        <tr>
                                            <th class="text-uppercase text-muted align-middle" style="font-size: 11px;">Insumo</th>
                                            <th class="text-uppercase text-muted align-middle" style="font-size: 11px; width: 180px;">Tarifa</th>
                                            <th class="text-uppercase text-muted text-center align-middle" style="font-size: 11px; width: 50px;">Día</th>
                                            <th class="text-uppercase text-muted text-center align-middle" style="font-size: 11px; width: 80px;">Inicio</th>
                                            <th class="text-uppercase text-muted text-center align-middle" style="font-size: 11px; width: 80px;">Fin</th>
                                            <th class="text-uppercase text-muted text-center align-middle" style="font-size: 11px; width: 100px;">Modo</th>
                                            <th class="text-uppercase text-muted text-center align-middle" style="font-size: 11px; width: 50px;">Ord</th>
                                            <th style="width: 45px;"></th>
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