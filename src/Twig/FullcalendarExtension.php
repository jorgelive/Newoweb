<?php

namespace App\Twig;

use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;

class FullcalendarExtension extends AbstractExtension
{
    private RouterInterface $_router;

    public function __construct(RouterInterface $router)
    {
        $this->_router = $router;
    }

    public function getName()
    {
        return 'fullcalendar';
    }

    public function getFunctions()
    {
        return [
            new \Twig\TwigFunction(
                'fullcalendar',
                [$this, 'fullcalendar'],
                ['is_safe' => ['html']]
            ),
        ];
    }

    /**
     * EVENTOS – URL absoluta según host definido en routing
     */
    public function generateUrl($calendar)
    {
        $exists = $this->_router->getRouteCollection()->get('app_fullcalendar_load_event');
        if (null === $exists) {
            return null;
        }

        return $this->_router->generate(
            'app_fullcalendar_load_event',
            ['calendar' => $calendar],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    /**
     * RESOURCES – URL absoluta según host definido en routing
     */
    public function generateResourceUrl($calendar)
    {
        $exists = $this->_router->getRouteCollection()->get('app_fullcalendar_load_resource');
        if (null === $exists) {
            return null;
        }

        return $this->_router->generate(
            'app_fullcalendar_load_resource',
            ['calendar' => $calendar],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    /**
     * Render principal del FullCalendar
     */
    public function fullcalendar($caller, $calendars, $defaultView = null, $views = [], $allDaySlot = false)
    {
        if (empty($views)) {
            $views = ['timeGridWeek', 'dayGridMonth', 'listMonth', 'resourceTimelineOneDay'];
        }

        if (empty($defaultView)) {
            $defaultView = 'dayGridMonth';
        }

        if (!is_array($calendars) && is_string($calendars)) {
            $calendars = ['Default' => $calendars];
        }

        if (!is_array($views) && is_string($views)) {
            $views[] = $views;
        }

        $views = implode(' ', $views);

        $calendarsUrls = [];
        foreach ($calendars as $key => $calendar) {
            $calendarsUrls[] = [
                'nombre'   => $key,
                'event'    => $this->generateUrl($calendar),
                'resource' => $this->generateResourceUrl($calendar),
            ];
        }

        $arrayKeys         = array_keys($calendarsUrls);
        $defaultLabel      = $calendarsUrls[$arrayKeys[0]]['nombre'];
        $defaultEventUrl   = $calendarsUrls[$arrayKeys[0]]['event'];
        $defaultResourceUrl= $calendarsUrls[$arrayKeys[0]]['resource'];

        $calendarsUrlsJson = json_encode($calendarsUrls);

        $allDaySlot   = $allDaySlot ? 'true' : 'false';
        $initialdate  = (new \DateTime('today'))->format('Y-m-d');

        $script = <<<JS
    $(document).ready(function() {

        let resourceUrl = '$defaultResourceUrl';
        const s = $("<select style=\\"margin-top: 10px; margin-left: 10px;\\" id=\\"calendarSelector\\" />");
        let oneClickTimer = null;

        const data = $calendarsUrlsJson;

        s.change(function() {
            calendar.removeAllEvents();

            const removeEvents = calendar.getEventSources();
            removeEvents.forEach(src => src.remove());

            // Volvemos a añadir la fuente de eventos con credenciales
            calendar.addEventSource(function(fetchInfo, success, failure) {
                $.ajax({
                    url: data[s.val()]['event'],
                    data: {
                        start: fetchInfo.startStr,
                        end:   fetchInfo.endStr
                    },
                    xhrFields: { withCredentials: true },   // credenciales explícitas
                    crossDomain: true,
                    success: success,
                    error: failure
                });
            });

            resourceUrl = data[s.val()]['resource'];
            calendar.refetchResources();

            calendar.setOption('resourceAreaHeaderContent', data[s.val()]['nombre']);
        });

        let renderDropdown = false;
        let idx = 0;
        for (const val in data) {
            $("<option />", { text: data[val]['nombre'], value: val }).appendTo(s);
            idx++;
        }
        if (idx > 1) {
            renderDropdown = true;
        }

        if (renderDropdown === true) {
            $("#calendar").before(s);
        }

        // 🔹 GET RESOURCES con credenciales explícitas
        function getResources(start, end, _timezone, handleData) {
            const params = {
                start: start.toISOString().slice(0, 10),
                end:   end.toISOString().slice(0, 10)
            };

            $.ajax({
                url: resourceUrl,
                data: params,
                xhrFields: { withCredentials: true },   // 👈 AQUÍ VAN LAS CREDENCIALES
                crossDomain: true,
                success: function(data) {
                    handleData(data);
                },
                error: function(xhr) {
                    console.error('Resource fetch error', xhr);
                    handleData([]);
                }
            });
        }

        let clickCnt = 0;

        const calDefaultViewStr   = "$caller" + "calDefaultView";
        const calDefaultScrollStr = "$caller" + "calDefaultScroll";
        const calDefaultDateStr   = "$caller" + "calDefaultDate";

        const defaultView = (localStorage.getItem(calDefaultViewStr) !== null
            ? localStorage.getItem(calDefaultViewStr)
            : '$defaultView'
        );

        if (localStorage.getItem(calDefaultDateStr) !== null) {
            const d = new Date(localStorage.getItem(calDefaultDateStr));
            if (isNaN(d.getTime())) {
                localStorage.removeItem(calDefaultDateStr);
            }
        }

        const initialDate = (localStorage.getItem(calDefaultDateStr) !== null
            ? localStorage.getItem(calDefaultDateStr)
            : '$initialdate'
        );

        const calendarEl = document.getElementById('calendar');
        const calendar = new FullCalendar.Calendar(calendarEl, {
            schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
            initialDate: initialDate,

            customButtons: {
                hoyButton: {
                    text: 'Hoy',
                    click: function() {
                        calendar.today();

                        if (calendar.view.type === 'resourceTimelineOneMonth') {
                            $(".fc-day-today").attr("id","scrollTo");
                            if (typeof $("#scrollTo").position() !== 'undefined') {
                                $(".fc-scroller").animate({
                                    scrollLeft: $("#scrollTo").position().left
                                }, 2000);
                            }
                        }
                    }
                }
            },

            datesSet: event => {
                localStorage.setItem(calDefaultDateStr, event.startStr);
            },

            headerToolbar: {
              start: '',
              center: 'title',
              end: ''
            },

            footerToolbar: {
              left: 'hoyButton prev,next',
              right: '$views'
            },

            initialView: defaultView,

            views: {
                resourceTimelineOneDay: {
                    type: 'resourceTimeline',
                    duration: { days: 1 },
                    buttonText: 'Dia',
                    resourceAreaWidth: '100px',
                    height: 'auto'
                },
                resourceTimelineOneWeek: {
                    type: 'resourceTimeline',
                    duration: { weeks: 1 },
                    buttonText: 'Semana',
                    resourceAreaWidth: '100px',
                    height: 'auto'
                },
                resourceTimelineOneMonth: {
                    type: 'resourceTimeline',
                    duration: { months: 1 },
                    buttonText: 'Mes Line',
                    slotMinWidth: 120,
                    slotDuration: '24:00:00',
                    resourceAreaWidth: '100px',
                    height: 'auto',
                    slotLabelContent(arg) {
                        const isHourLevel = (arg.level === 1);
                        if (isHourLevel) {
                            return "";
                        } else {
                            return arg.text;
                        }
                    }
                }
            },

            dateClick: function(info) {
                clickCnt++;
                if (clickCnt === 1) {
                    oneClickTimer = setTimeout(function() {
                        clickCnt = 0;
                    }, 400);
                } else if (clickCnt === 2) {
                    clearTimeout(oneClickTimer);
                    clickCnt = 0;
                    calendar.changeView('resourceTimelineOneDay');
                    calendar.gotoDate(info.date);
                }
            },

            eventClick: function(info) {
                clickCnt++;
                if (clickCnt === 1) {
                    oneClickTimer = setTimeout(function() {
                        clickCnt = 0;
                        if (typeof info.event.extendedProps.urlshow !== 'undefined') {
                            window.location.href = info.event.extendedProps.urlshow;
                        }
                    }, 400);
                } else if (clickCnt === 2) {
                    clearTimeout(oneClickTimer);
                    clickCnt = 0;
                    if (typeof info.event.extendedProps.urledit !== 'undefined') {
                        window.location.href = info.event.extendedProps.urledit;
                    }
                }
            },

            eventDidMount: function (info) {
                if (info.el._tippy) info.el._tippy.destroy();

                tippy(info.el, {
                    content: (() => {
                        const tip = info.event.extendedProps.tooltip;

                        if (Array.isArray(tip) && tip.length > 0) {
                            return tip.map(line => `<div>\${line}</div>`).join('');
                        } else if (typeof tip === 'string' && tip.trim() !== '') {
                            return tip;
                        } else {
                            return info.event.title || '';
                        }
                    })(),
                    allowHTML: true,
                    trigger: 'mouseenter focus',
                    delay: [100, 80],
                    placement: 'top',
                    touch: ['hold', 120],
                    appendTo: document.body
                });

                // 🔹 Deshabilitar selección táctil
                info.el.style.userSelect = 'none';
                info.el.style.webkitUserSelect = 'none';
                info.el.style.webkitTouchCallout = 'none';
                info.el.style.touchAction = 'manipulation';
            
                // 🔹 Cambiar cursor si hay acciones de click o doble click
                if (
                    (info.event.extendedProps.urlshow && info.event.extendedProps.urlshow !== '') ||
                    (info.event.extendedProps.urledit && info.event.extendedProps.urledit !== '')
                ) {
                    info.el.style.cursor = 'pointer';
                }
            },

            resourceAreaHeaderContent: '$defaultLabel',
            locale: 'es',
            nowIndicator: true,
            contentHeight: 800,
            editable: false,
            refetchResourcesOnNavigate: true,

            // 🔹 RESOURCES usando getResources (con credenciales)
            resources: function(fetchInfo, successCallback, failureCallback) {
                getResources(fetchInfo.start, fetchInfo.end, fetchInfo.timeZone, function(resources) {

                    if (localStorage.getItem(calDefaultScrollStr) === null) {
                        setTimeout(function() {
                            $(".fc-day-today").attr("id", "scrollTo");
                            if (typeof $("#scrollTo").position() !== 'undefined') {
                                $(".fc-scroller").animate({
                                    scrollLeft: $("#scrollTo").position().left
                                }, 2000);
                            }
                        }, 500);
                    }

                    successCallback(resources);

                    setTimeout(function() {
                        const viewType = calendar.view.type;
                        if (viewType.startsWith('resourceTimeline')) {
                            calendar.setOption('height', 'auto');
                            calendar.updateSize();
                        }
                    }, 300);
                });
            },

            // 🔹 EVENTS usando AJAX con credenciales explícitas
            events: function(fetchInfo, successCallback, failureCallback) {
                $.ajax({
                    url: '$defaultEventUrl',
                    data: {
                        start: fetchInfo.startStr,
                        end:   fetchInfo.endStr
                    },
                    xhrFields: { withCredentials: true },   // 👈 AQUÍ TAMBIÉN
                    crossDomain: true,
                    success: function(data) {
                        successCallback(data);
                    },
                    error: function(xhr) {
                        console.error('Event fetch error', xhr);
                        failureCallback(xhr);
                    }
                });
            }
        });

        calendar.render();

        $(".fc-scroller").on("scroll", function() {
            localStorage.setItem(calDefaultViewStr, calendar.view.type);
            localStorage.setItem(calDefaultScrollStr, $(this).scrollLeft());
        });

        if (localStorage.getItem(calDefaultScrollStr) !== null) {
            $(".fc-scroller").animate({
                scrollLeft: localStorage.getItem(calDefaultScrollStr)
            }, 2000);
        }
    });
JS;

        return <<<HTML
    <script>{$script}</script>

    <div class="box box-primary calendar-box">
      <div class="calendar-outer">
        <div id="calendar" class="calendar-shell"></div>
      </div>
    </div>
HTML;
    }
}
