import { Controller } from '@hotwired/stimulus';
import tippy from 'tippy.js';

export default class extends Controller {
    static values = {
        calendars: Array,
        defaultView: String,
        allDaySlot: Boolean,
        views: { type: Object, default: {} },
        resourceAreaWidth: { type: Number, default: 120 }
    };

    connect() {
        this.element.innerHTML = '';
        if (typeof FullCalendar === 'undefined') return;

        this.clickCnt = 0;
        this.oneClickTimer = null;
        this.calendar = null;
        this.currentCalendarIndex = 0;
        this.isRestoring = false; // Bloqueo para evitar sobrescribir el scroll durante restore

        this.storageKey = `fc_state_${window.location.pathname}`;

        this.renderSelector();

        this.calendarTitle = document.createElement('h3');
        this.calendarTitle.className = 'text-center fw-bold text-uppercase text-primary my-3';
        this.element.appendChild(this.calendarTitle);

        this.initCalendar();
    }

    disconnect() {
        if (this.calendar) {
            this.calendar.destroy();
            this.calendar = null;
        }
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

    renderSelector() {
        const calendars = this.calendarsValue;
        if (!calendars || calendars.length <= 1) return;

        const select = document.createElement('select');
        select.classList.add('form-select', 'form-select-sm', 'mb-3', 'd-inline-block', 'w-auto');

        calendars.forEach((cal, index) => {
            const option = document.createElement('option');
            option.value = index;
            option.text = cal.nombre;
            select.appendChild(option);
        });

        select.addEventListener('change', (e) => {
            this.currentCalendarIndex = parseInt(e.target.value, 10);
            this.refreshCalendarData();
        });

        this.element.prepend(select);
    }

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
            eventOrder: '-prioridadImportante,duration,start',

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

            datesSet: (info) => {
                localStorage.setItem(`${this.storageKey}_date`, this.calendar.getDate().toISOString().slice(0, 10));
                localStorage.setItem(`${this.storageKey}_view`, info.view.type);

                if (this.calendarTitle) {
                    const title = String(info.view.title || '');
                    this.calendarTitle.innerText = title ? (title.charAt(0).toUpperCase() + title.slice(1)) : '';
                }

                const saved = localStorage.getItem(`${this.storageKey}_scroll`);
                if (saved) this.applyScroll(saved);
            },

            resources: (fetchInfo, success, failure) => {
                const config = this.getCurrentConfig();
                const url = new URL(config.resourceUrl, window.location.origin);
                url.searchParams.set('start', fetchInfo.startStr);
                url.searchParams.set('end', fetchInfo.endStr);

                fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'include'
                })
                    .then((r) => r.json())
                    .then((data) => {
                        success(Array.isArray(data) ? data : (data?.data || data?.resources || []));

                        const saved = localStorage.getItem(`${this.storageKey}_scroll`);
                        if (saved && parseFloat(saved) > 0) {
                            this.applyScroll(saved);
                        } else {
                            this.scrollToToday();
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
                    if (allowedViews.includes('resourceTimelineOneDay')) this.calendar.changeView('resourceTimelineOneDay');
                    this.calendar.gotoDate(info.date);
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
                if (info.event.extendedProps.urlshow || info.event.extendedProps.urledit) info.el.style.cursor = 'pointer';
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

        // ✅ Restauración inicial forzada (por si los recursos ya estaban en caché o tardan)
        const currentScroll = localStorage.getItem(`${this.storageKey}_scroll`);
        if (currentScroll) {
            this.applyScroll(currentScroll);
        }
    }

    setupScrollListener() {
        const key = `${this.storageKey}_scroll`;

        this.element.addEventListener('scroll', (e) => {
            // Si estamos en proceso de restauración, ignoramos los eventos de scroll
            // generados por el propio código para no guardar basura.
            if (this.isRestoring) return;

            const t = e.target;
            if (t && t.classList && (t.classList.contains('fc-scroller-h') || t.classList.contains('fc-scroller'))) {
                const left = t.scrollLeft || 0;
                localStorage.setItem(key, String(left));
            }
        }, true);
    }

    getMainScroller() {
        // Buscamos el scroller que tiene overflow horizontal (el área de las reservas)
        const candidates = Array.from(this.element.querySelectorAll('.fc-scroller-h, .fc-scroller'));
        const best = candidates.find(s => s.scrollWidth > s.clientWidth + 5);
        return best || candidates[0] || null;
    }

    applyScroll(value) {
        const numericValue = parseFloat(value);
        if (isNaN(numericValue) || numericValue < 0) return;

        this.isRestoring = true;

        const attempt = (count) => {
            const scroller = this.getMainScroller();
            // Verificamos que el scroller esté listo y tenga contenido para scrollear
            if (scroller && scroller.scrollWidth > scroller.clientWidth) {
                scroller.scrollLeft = numericValue;

                // Liberamos el bloqueo después de un pequeño margen para que
                // FullCalendar termine de estabilizar el DOM
                setTimeout(() => { this.isRestoring = false; }, 250);
                return;
            }

            // Si no está listo, reintentamos (Beds24 API v2 a veces tarda en inyectar filas)
            if (count < 25) {
                setTimeout(() => attempt(count + 1), 100);
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

                // Al terminar la animación smooth, guardamos la nueva posición
                setTimeout(() => {
                    localStorage.setItem(`${this.storageKey}_scroll`, String(scroller.scrollLeft || 0));
                    this.isRestoring = false;
                }, 600);
            }
        }, 400);
    }

    getCurrentConfig() {
        return this.calendarsValue[this.currentCalendarIndex];
    }

    refreshCalendarData() {
        const config = this.getCurrentConfig();
        this.calendar.setOption('resourceAreaHeaderContent', config.nombre);
        this.calendar.refetchResources();
        this.calendar.refetchEvents();
    }
}