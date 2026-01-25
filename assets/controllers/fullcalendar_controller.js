import { Controller } from '@hotwired/stimulus';
import tippy from 'tippy.js';

export default class extends Controller {
    static values = {
        calendars: Array,
        defaultView: String,
        allDaySlot: Boolean,
        views: { type: Object, default: {} },

        // ✅ Nuevo: ancho por defecto del resource area (para TODOS los timelines)
        resourceAreaWidth: { type: Number, default: 120 }
    };

    connect() {
        this.element.innerHTML = '';
        if (typeof FullCalendar === 'undefined') return;

        this.clickCnt = 0;
        this.oneClickTimer = null;
        this.calendar = null;
        this.currentCalendarIndex = 0;

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

    // =========================
    // Catálogo interno de vistas
    // =========================
    getViewCatalog() {
        const w = this.getTimelineResourceAreaWidth(); // 👈 aplica a todos los timelines

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
        // ✅ Acepta número (px) o string tipo "15%" si algún día lo quieres pasar así
        const v = this.resourceAreaWidthValue;

        if (typeof v === 'number' && isFinite(v) && v > 0) {
            return `${Math.round(v)}px`;
        }

        // fallback ultra seguro
        return '120px';
    }

    /**
     * viewsValue whitelist (ordenada).
     * Soporta:
     *  - objeto {1:'dayGridMonth',2:'listMonth',3:'resourceTimelineOneMonth'}
     *  - array ['dayGridMonth','listMonth',...]
     */
    getAllowedViews() {
        const catalog = this.getViewCatalog();
        const v = this.viewsValue;

        let requested = [];

        if (Array.isArray(v)) {
            requested = v;
        } else if (v && typeof v === 'object') {
            requested = Object.keys(v)
                .sort((a, b) => Number(a) - Number(b))
                .map((k) => v[k]);
        }

        requested = requested
            .map((x) => (x == null ? '' : String(x)).trim())
            .filter((x) => x.length > 0)
            .filter((x) => !!catalog[x]);

        if (requested.length === 0) {
            requested = ['dayGridMonth', 'listMonth', 'resourceTimelineOneMonth'];
        }

        return requested;
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
        const savedScroll = localStorage.getItem(`${this.storageKey}_scroll`);

        const allowedViews = this.getAllowedViews();
        const catalog = this.getViewCatalog();

        const initialDate = savedDate || new Date().toISOString().slice(0, 10);

        const defaultView = (this.defaultViewValue && catalog[this.defaultViewValue])
            ? this.defaultViewValue
            : allowedViews[0];

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

            // ✅ Placeholder de 5px en el chunk central (sin CSS global)
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
                // ✅ botón vacío usado como separador
                spacer10: {
                    text: '',
                    click: () => {}
                }
            },

            views: viewsConfig,

            datesSet: (info) => {
                const d = this.calendar.getDate();
                localStorage.setItem(`${this.storageKey}_date`, d.toISOString().slice(0, 10));
                localStorage.setItem(`${this.storageKey}_view`, info.view.type);

                if (this.calendarTitle) {
                    const title = String(info.view.title || '');
                    this.calendarTitle.innerText = title ? (title.charAt(0).toUpperCase() + title.slice(1)) : '';
                }
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

                        if (savedScroll && parseFloat(savedScroll) > 0) this.applyScroll(savedScroll);
                        else this.scrollToToday();
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

                tippy(info.el, {
                    content: finalContent,
                    allowHTML: true,
                    appendTo: document.body,
                    placement: 'top'
                });

                if (info.event.extendedProps.urlshow || info.event.extendedProps.urledit) {
                    info.el.style.cursor = 'pointer';
                }
            }
        });

        this.calendar.render();

        // ✅ aplica estilo inline al placeholder (sin CSS global)
        setTimeout(() => {
            const spacer = this.element.querySelector('.fc-spacer10-button');
            if (!spacer) return;

            spacer.style.width = '10x';
            spacer.style.minWidth = '10px';
            spacer.style.padding = '0';
            spacer.style.margin = '0';
            spacer.style.border = '0';
            spacer.style.background = 'transparent';
            spacer.style.boxShadow = 'none';
            spacer.style.cursor = 'default';
        }, 0);

        this.setupScrollListener();

        if (savedScroll && parseFloat(savedScroll) > 0) {
            this.applyScroll(savedScroll);
        }
    }

    setupScrollListener() {
        this.element.addEventListener('scroll', (e) => {
            const target = e.target;
            if (target && target.classList && target.classList.contains('fc-scroller')) {
                const left = target.scrollLeft;
                if (left > 0) localStorage.setItem(`${this.storageKey}_scroll`, String(left));
            }
        }, true);
    }

    applyScroll(value) {
        const numericValue = parseFloat(value) || 0;

        const attemptScroll = (count) => {
            const scroller = this.element.querySelector('.fc-scroller-h, .fc-scroller');
            if (scroller && scroller.scrollWidth > scroller.clientWidth) {
                scroller.scrollLeft = numericValue;
            } else if (count < 10) {
                setTimeout(() => attemptScroll(count + 1), 150);
            }
        };

        attemptScroll(0);
    }

    scrollToToday() {
        if (!this.calendar || !String(this.calendar.view.type || '').includes('resourceTimeline')) return;

        setTimeout(() => {
            const todayEl = this.element.querySelector('.fc-day-today');
            const scrollers = this.element.querySelectorAll('.fc-scroller');

            let mainScroller = null;
            scrollers.forEach((s) => {
                if (s.scrollWidth > s.clientWidth) mainScroller = s;
            });

            if (todayEl && mainScroller) {
                const targetLeft = todayEl.offsetLeft;
                mainScroller.scrollTo({ left: targetLeft - 80, behavior: 'smooth' });
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