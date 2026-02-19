import { Controller } from '@hotwired/stimulus';
import tippy from 'tippy.js';

/**
 * Controlador Stimulus para FullCalendar (Versión Maestra).
 * * CARACTERÍSTICAS TÉCNICAS:
 * 1. Agnosticismo de UI: Funciona tanto con Select nativo como con Select2 (jQuery) inyectado por temas administrativos.
 * 2. Restauración de Estado Diferida: El scroll se restaura solo cuando los recursos (filas) existen realmente en el DOM.
 * 3. Navegación Contextual: Detecta la dirección del tiempo (Pasado/Futuro) para posicionar el scroll lógicamente.
 * 4. Gestión de Memoria: Limpieza estricta de listeners y observers al desconectar.
 */
export default class extends Controller {
    // Definición estricta de tipos para valores pasados desde Twig
    static values = {
        calendars: Array,           // Configuración de múltiples orígenes
        defaultView: String,        // Vista por defecto
        allDaySlot: Boolean,        // Mostrar slot de día completo
        views: { type: Object, default: {} }, // Configuración extendida de vistas
        resourceAreaWidth: { type: Number, default: 120 } // Ancho columna recursos
    };

    /**
     * Inicialización del ciclo de vida.
     */
    connect() {
        this.element.innerHTML = '';

        // Verificación de dependencia crítica
        if (typeof FullCalendar === 'undefined') {
            console.error('CRITICAL: FullCalendar no está cargado en el scope global.');
            return;
        }

        // --- Inicialización de Estado Interno ---
        this.clickCnt = 0;          // Contador para lógica de doble click manual
        this.oneClickTimer = null;  // Timer para diferenciar click simple vs doble
        this.calendar = null;       // Instancia del objeto FullCalendar
        this.currentCalendarIndex = 0; // Puntero al calendario activo

        // Flags de control de flujo
        this.isRestoring = false;   // Semáforo: Bloquea guardado de scroll mientras se restaura programáticamente
        this.lastViewRange = null;  // Memoria para detectar dirección de navegación (Prev/Next)
        this.selectElement = null;  // Referencia al elemento DOM del selector (para limpieza)

        // --- FIX CRÍTICO DE RESTAURACIÓN ---
        // Bandera para asegurar que el scroll solo se restaure la primera vez que cargan los datos,
        // garantizando que las filas existan antes de intentar mover el scroll.
        this.initialResourcesLoaded = false;

        // Clave única de almacenamiento por URL
        this.storageKey = `fc_state_${window.location.pathname}`;

        // Renderizado de UI
        this.renderSelector();

        this.calendarTitle = document.createElement('h3');
        this.calendarTitle.className = 'text-center fw-bold text-uppercase text-primary my-3';
        this.element.appendChild(this.calendarTitle);

        // Arranque del motor
        this.initCalendar();
    }

    /**
     * Limpieza de recursos al desmontar el controlador.
     */
    disconnect() {
        // Limpieza de listeners de jQuery (Select2) para evitar memory leaks
        if (this.selectElement && typeof $ !== 'undefined') {
            try {
                if ($(this.selectElement).data('select2')) {
                    $(this.selectElement).select2('destroy');
                }
                $(this.selectElement).off('change select2:select');
            } catch (e) {
                // Silencioso: El elemento podría ya no existir
            }
        }

        if (this.calendar) {
            this.calendar.destroy();
            this.calendar = null;
        }
    }

    // ==========================================
    // Getters y Configuración (Tipado Estricto)
    // ==========================================

    getCurrentConfig() {
        return this.calendarsValue[this.currentCalendarIndex];
    }

    getViewCatalog() {
        const w = this.getTimelineResourceAreaWidth();
        return {
            dayGridMonth: { type: 'dayGridMonth', buttonText: 'Calendario' },
            listMonth: { type: 'listMonth', buttonText: 'Lista' },
            resourceTimelineOneDay: {
                type: 'resourceTimeline',
                duration: { days: 1 },
                buttonText: 'Día',
                resourceAreaWidth: w
            },
            resourceTimelineOneWeek: {
                type: 'resourceTimeline',
                duration: { weeks: 1 },
                buttonText: 'Semana',
                resourceAreaWidth: w
            },
            resourceTimelineOneMonth: {
                type: 'resourceTimeline',
                duration: { months: 1 },
                buttonText: 'Mes',
                resourceAreaWidth: w,
                slotDuration: '24:00:00',
                slotLabelFormat: [{ weekday: 'short', day: 'numeric', month: 'numeric', omitCommas: true }],
                slotLabelContent: (arg) => (arg.level === 1 ? '' : arg.text)
            }
        };
    }

    getTimelineResourceAreaWidth() {
        const v = this.resourceAreaWidthValue;
        return (typeof v === 'number' && isFinite(v) && v > 0) ? `${Math.round(v)}px` : '120px';
    }

    getAllowedViews() {
        const catalog = this.getViewCatalog();
        const v = this.viewsValue;
        let requested = [];

        if (Array.isArray(v)) {
            requested = v;
        } else if (v && typeof v === 'object') {
            requested = Object.keys(v).sort((a, b) => Number(a) - Number(b)).map((k) => v[k]);
        }

        requested = requested
            .map((x) => (x == null ? '' : String(x)).trim())
            .filter((x) => x.length > 0 && !!catalog[x]);

        return requested.length > 0 ? requested : ['dayGridMonth', 'listMonth', 'resourceTimelineOneMonth'];
    }

    // ==========================================
    // Renderizado del Selector (Estrategia Híbrida)
    // ==========================================

    renderSelector() {
        const calendars = this.calendarsValue;
        if (!calendars || calendars.length <= 1) return;

        const select = document.createElement('select');
        select.classList.add('form-select', 'form-select-sm', 'mb-3', 'd-inline-block', 'w-auto');

        calendars.forEach((cal, index) => {
            const option = document.createElement('option');
            option.value = index;
            option.text = cal.nombre;
            if (index === this.currentCalendarIndex) option.selected = true;
            select.appendChild(option);
        });

        this.selectElement = select;

        // 1. Listener Nativo (Vanilla JS / Tom Select)
        select.addEventListener('change', (e) => {
            const val = parseInt(e.target.value, 10);
            this.handleSelectorChange(val);
        });

        this.element.prepend(select);

        // 2. Listener jQuery (Soporte Legacy / Select2)
        // Detectamos si el entorno usa jQuery para engancharnos a los eventos sintéticos de Select2
        if (typeof $ !== 'undefined') {
            setTimeout(() => {
                const $select = $(select);
                // 'select2:select' es el evento específico que dispara la librería cuando eliges una opción
                $select.on('change select2:select', (e) => {
                    const val = parseInt($(e.target).val(), 10);
                    this.handleSelectorChange(val);
                });
            }, 50); // Delay técnico para permitir inicialización del tema
        }
    }

    /**
     * Gatekeeper: Centraliza el cambio de calendario evitando llamadas duplicadas
     * si ambos listeners (Nativo y jQuery) disparan.
     */
    handleSelectorChange(newIndex) {
        if (isNaN(newIndex)) return;

        if (this.currentCalendarIndex !== newIndex) {
            this.currentCalendarIndex = newIndex;
            this.refreshCalendarData();
        }
    }

    // ==========================================
    // Inicialización del Motor FullCalendar
    // ==========================================

    initCalendar() {
        const calendarEl = document.createElement('div');
        calendarEl.style.minHeight = '800px';
        this.element.appendChild(calendarEl);

        const savedDate = localStorage.getItem(`${this.storageKey}_date`);
        const savedView = localStorage.getItem(`${this.storageKey}_view`);

        const allowedViews = this.getAllowedViews();
        const catalog = this.getViewCatalog();

        const initialDate = savedDate || new Date().toISOString().slice(0, 10);
        const defaultView = (this.defaultViewValue && catalog[this.defaultViewValue]) ? this.defaultViewValue : allowedViews[0];
        const initialView = (savedView && allowedViews.includes(savedView)) ? savedView : defaultView;

        const viewsConfig = {};
        allowedViews.forEach((viewId) => { viewsConfig[viewId] = catalog[viewId]; });

        this.calendar = new FullCalendar.Calendar(calendarEl, {
            schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
            locale: 'es',
            timeZone: 'local',
            initialDate,
            initialView,
            allDaySlot: this.allDaySlotValue,
            nowIndicator: true,
            contentHeight: 'auto',
            editable: false,
            resourceAreaHeaderContent: this.getCurrentConfig().nombre,
            refetchResourcesOnNavigate: true,
            eventOrder: '-prioridadImportante,-duration,start',
            eventOrderStrict: true,

            headerToolbar: {
                left: 'hoyButton prev,next',
                center: 'spacer10',
                right: allowedViews.join(',')
            },

            customButtons: {
                hoyButton: {
                    text: 'Hoy',
                    click: () => {
                        this.calendar.today();
                        setTimeout(() => this.scrollToToday(), 150);
                    }
                },
                spacer10: { text: '', click: () => {} }
            },

            views: viewsConfig,

            // --- CALLBACKS DEL SISTEMA ---

            /**
             * Ejecutado al cambiar rango de fechas.
             * Maneja la lógica de "Scroll Inteligente" basada en la dirección de navegación.
             */
            datesSet: (info) => {
                const newDateStr = this.calendar.getDate().toISOString().slice(0, 10);
                localStorage.setItem(`${this.storageKey}_date`, newDateStr);
                localStorage.setItem(`${this.storageKey}_view`, info.view.type);

                if (this.calendarTitle) {
                    const title = String(info.view.title || '');
                    this.calendarTitle.innerText = title ? (title.charAt(0).toUpperCase() + title.slice(1)) : '';
                }

                // Determinación de dirección de navegación (Prev vs Next)
                if (this.lastViewRange && info.view.type.includes('resourceTimeline')) {
                    const oldStart = this.lastViewRange.start.getTime();
                    const newStart = info.start.getTime();
                    const scroller = this.getMainScroller();

                    if (scroller) {
                        if (newStart > oldStart) {
                            // Usuario avanza -> Scroll al inicio (0)
                            this.applyScroll(0);
                        } else if (newStart < oldStart) {
                            // Usuario retrocede -> Scroll al final
                            this.applyScroll(scroller.scrollWidth);
                        }
                    }
                } else {
                    // Carga inicial o cambio de vista simple: Intento de restauración básica
                    const saved = localStorage.getItem(`${this.storageKey}_scroll`);
                    if (saved) this.applyScroll(saved);
                }

                this.lastViewRange = { start: info.start, end: info.end };
            },

            /**
             * Carga de Recursos.
             * PUNTO CRÍTICO: Aquí se restaura el scroll inicial.
             */
            resources: (fetchInfo, success, failure) => {
                const config = this.getCurrentConfig();
                const url = new URL(config.resourceUrl, window.location.origin);
                url.searchParams.set('start', fetchInfo.startStr);
                url.searchParams.set('end', fetchInfo.endStr);
                url.searchParams.set('_t', Date.now()); // Cache busting

                fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'include'
                })
                    .then((r) => r.json())
                    .then((data) => {
                        success(Array.isArray(data) ? data : (data?.data || data?.resources || []));

                        // --- LÓGICA DE RESTAURACIÓN DE SCROLL DIFERIDA ---
                        // Si es la primera vez que cargamos los recursos en esta sesión del controlador,
                        // intentamos restaurar el scroll AHORA, que sabemos que las filas existen.
                        if (!this.initialResourcesLoaded) {
                            this.initialResourcesLoaded = true;
                            const saved = localStorage.getItem(`${this.storageKey}_scroll`);
                            if (saved) {
                                // Pequeño delay para asegurar renderizado en el DOM
                                setTimeout(() => this.applyScroll(saved), 50);
                            }
                        }
                    })
                    .catch(failure);
            },

            events: (fetchInfo, success, failure) => {
                const config = this.getCurrentConfig();
                const url = new URL(config.eventUrl, window.location.origin);
                url.searchParams.append('start', fetchInfo.startStr);
                url.searchParams.append('end', fetchInfo.endStr);
                url.searchParams.append('current_page', btoa(window.location.href));
                url.searchParams.set('_t', Date.now());

                fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'include'
                })
                    .then((r) => r.json())
                    .then((data) => success(Array.isArray(data) ? data : (data?.data || data?.events || [])))
                    .catch(failure);
            },

            dateClick: (info) => {
                this.clickCnt++;
                if (this.clickCnt === 1) {
                    this.oneClickTimer = setTimeout(() => { this.clickCnt = 0; }, 400);
                } else if (this.clickCnt === 2) {
                    clearTimeout(this.oneClickTimer);
                    this.clickCnt = 0;
                    if (allowedViews.includes('resourceTimelineOneDay')) {
                        this.calendar.changeView('resourceTimelineOneDay');
                        this.calendar.gotoDate(info.date);
                    }
                }
            },

            eventClick: (info) => {
                info.jsEvent.preventDefault();
                this.clickCnt++;
                if (this.clickCnt === 1) {
                    this.oneClickTimer = setTimeout(() => {
                        this.clickCnt = 0;
                        if (info.event.extendedProps.urlshow) window.location.href = info.event.extendedProps.urlshow;
                    }, 400);
                } else if (this.clickCnt === 2) {
                    clearTimeout(this.oneClickTimer);
                    this.clickCnt = 0;
                    if (info.event.extendedProps.urledit) window.location.href = info.event.extendedProps.urledit;
                }
            },

            eventDidMount: (info) => {
                const tooltipContent = info.event.extendedProps.tooltip;
                let finalContent = info.event.title;
                if (Array.isArray(tooltipContent)) finalContent = tooltipContent.join('<br>');
                else if (tooltipContent) finalContent = tooltipContent;

                if (info.el._tippy) info.el._tippy.destroy();
                tippy(info.el, { content: finalContent, allowHTML: true, appendTo: document.body, placement: 'top' });

                if (info.event.extendedProps.urlshow || info.event.extendedProps.urledit) {
                    info.el.style.cursor = 'pointer';
                }
            }
        });

        this.calendar.render();

        setTimeout(() => {
            const spacer = this.element.querySelector('.fc-spacer10-button');
            if (spacer) {
                Object.assign(spacer.style, {
                    width: '10px', minWidth: '10px', padding: '0', margin: '0',
                    border: '0', background: 'transparent', boxShadow: 'none', cursor: 'default'
                });
            }
        }, 0);

        this.setupScrollListener();
    }

    // ==========================================
    // Lógica de Scroll y Persistencia
    // ==========================================

    setupScrollListener() {
        const key = `${this.storageKey}_scroll`;
        this.element.addEventListener('scroll', (e) => {
            // Ignoramos eventos de scroll generados programáticamente por la restauración
            if (this.isRestoring) return;

            const t = e.target;
            if (t && t.classList && (t.classList.contains('fc-scroller-h') || t.classList.contains('fc-scroller'))) {
                const left = t.scrollLeft || 0;
                localStorage.setItem(key, String(left));
            }
        }, true);
    }

    getMainScroller() {
        const candidates = Array.from(this.element.querySelectorAll('.fc-scroller-h, .fc-scroller'));
        // El candidato ideal es aquel que tiene overflow real (scrollWidth > clientWidth)
        const best = candidates.find(s => s.scrollWidth > s.clientWidth + 5);
        return best || candidates[0] || null;
    }

    applyScroll(value) {
        const numericValue = parseFloat(value);
        if (isNaN(numericValue) || numericValue < 0) return;

        this.isRestoring = true; // SEMÁFORO ROJO: Bloquear listeners de guardado

        const attempt = (count) => {
            const scroller = this.getMainScroller();
            // Verificar que el scroller existe y tiene capacidad de scroll
            if (scroller && scroller.scrollWidth > scroller.clientWidth) {
                scroller.scrollLeft = numericValue;
                // Liberar semáforo tras estabilización
                setTimeout(() => { this.isRestoring = false; }, 150);
                return;
            }
            // Reintentar hasta ~1 segundo
            if (count < 20) {
                setTimeout(() => attempt(count + 1), 50);
            } else {
                this.isRestoring = false;
            }
        };

        attempt(0);
    }

    scrollToToday() {
        if (!this.calendar || !String(this.calendar.view.type || '').includes('resourceTimeline')) return;

        setTimeout(() => {
            const todayEl = this.element.querySelector('.fc-day-today');
            const scroller = this.getMainScroller();

            if (todayEl && scroller) {
                const targetLeft = todayEl.offsetLeft;
                this.isRestoring = true;
                scroller.scrollTo({ left: targetLeft - 80, behavior: 'smooth' });
                setTimeout(() => {
                    localStorage.setItem(`${this.storageKey}_scroll`, String(scroller.scrollLeft || 0));
                    this.isRestoring = false;
                }, 600);
            }
        }, 200);
    }

    // ==========================================
    // Lógica de Refresco (Cambio de Calendario)
    // ==========================================

    refreshCalendarData() {
        if (!this.calendar) return;

        const config = this.getCurrentConfig();

        // 1. Actualizar Header
        this.calendar.setOption('resourceAreaHeaderContent', config.nombre);

        // 2. Limpiar persistencia del calendario anterior
        localStorage.removeItem(`${this.storageKey}_scroll`);

        // 3. Feedback visual (limpieza)
        this.calendar.removeAllEvents();

        // 4. Recarga asíncrona
        setTimeout(() => {
            // Resetear bandera para permitir nueva restauración en el nuevo calendario
            this.initialResourcesLoaded = false;

            // Forzar petición de red (el timestamp _t asegurará que no sea caché)
            this.calendar.refetchResources();
            this.calendar.refetchEvents();
        }, 10);
    }
}