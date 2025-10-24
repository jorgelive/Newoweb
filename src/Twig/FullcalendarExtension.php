<?php

namespace App\Twig;
use Symfony\Component\Routing\RouterInterface;
use \Twig\Extension\AbstractExtension;

class FullcalendarExtension extends AbstractExtension
{

    private RouterInterface $_router;

    public function __construct(RouterInterface $router)
    {
        $this->_router = $router;
    }

    public function getName() {
        return 'fullcalendar';
    }

    public function getFunctions()
    {

        return array(
            'fullcalendar' => new \Twig\TwigFunction(
                'fullcalendar',
                array($this, 'fullcalendar'),
                array('is_safe' => array('html'))
            ),
        );
    }

    public function generateUrl($calendar){
        $exists = $this->_router->getRouteCollection()->get('app_fullcalendar_load_event');
        if(null === $exists)
        {
            return null;
        }

        return $this->_router->generate('app_fullcalendar_load_event', ['calendar' => $calendar]);
    }

    public function generateResourceUrl($calendar){
        $exists = $this->_router->getRouteCollection()->get('app_fullcalendar_load_resource');
        if(null === $exists)
        {
            return null;
        }

        return $this->_router->generate('app_fullcalendar_load_resource', ['calendar' => $calendar]);
    }

    public function fullcalendar($caller, $calendars, $defaultView = null, $views = [], $allDaySlot = false)
    {
        if(empty($views)){
            $views = ['timeGridWeek', 'dayGridMonth', 'listMonth', 'resourceTimelineOneDay'];
        }

        if(empty($defaultView)){
            $defaultView = 'dayGridMonth';
        }

        if(!is_array($calendars) && is_string($calendars)){
            $calendars = ['Default' => $calendars];
        }

        if(!is_array($views) && is_string($views)) {
            $views[] = $views;
        }

        $views = implode(' ', $views);

        foreach($calendars as $key => $calendar){
            $calendarsUrls[] = [
                'nombre' => $key,
                'event' => $this->generateUrl($calendar),
                'resource' =>  $this->generateResourceUrl($calendar)
                ];
        }

        $arrayKeys = array_keys($calendarsUrls);
        $defaultLabel = $calendarsUrls[$arrayKeys[0]]['nombre'];
        $defaultEventUrl = $calendarsUrls[$arrayKeys[0]]['event'];
        $defaultResourceUrl = $calendarsUrls[$arrayKeys[0]]['resource'];

        $calendarsUrls = json_encode($calendarsUrls);

        if($allDaySlot === true){
            $allDaySlot = 'true';
        }else{
            $allDaySlot = 'false';
        }

        $initialdate = (new \DateTime('today'))->format('Y-m-d');

        $script = <<<JS


    $(document).ready(function() {

        let resourceUrl = '$defaultResourceUrl';
        const s = $("<select style=\"margin-top: 10px; margin-left: 10px;\" id=\"calendarSelector\" />"); // FIX: const
        let oneClickTimer = null;
    
        s.change(function() {
            // elimina eventos de la vista (no la "vista" completa)
            calendar.removeAllEvents();
    
            // FIX: este bloque elimina "fuentes de eventos", no recursos
            const removeEvents = calendar.getEventSources();
            removeEvents.forEach(src => {
                 src.remove();
            });
    
            calendar.addEventSource(data[s.val()]['event']);
    
            // Actualiza origen de recursos y refetch
            resourceUrl = data[s.val()]['resource'];
            calendar.refetchResources();
    
            calendar.setOption('resourceAreaHeaderContent', data[s.val()]['nombre']);
        });
    
        let renderDropdown = false;
    
        const data = $calendarsUrls; // FIX: const
    
        // FIX: for...in da claves string; comparar con > 0 es frágil.
        // Usamos un contador simple para decidir mostrar el dropdown
        let idx = 0;
        for (const val in data) {
            $("<option />", { text: data[val]['nombre'], value: val }).appendTo(s);
            idx++;
        }
        if (idx > 1) { // FIX: más claro: solo renderiza si hay más de una opción
            renderDropdown = true;
        }
    
        if (renderDropdown === true) {
            $("#calendar").before(s);
        }
    
        // FIX: firma simplificada; no necesitas timezone aquí
        function getResources(start, end, _timezone, handleData) {
            const params = {
                start: start.toISOString().slice(0, 10),
                end:   end.toISOString().slice(0, 10)
            };
    
            $.ajax({
                url: resourceUrl,
                data: params, // FIX: usa 'data' en vez de concatenar querystring
                success: function(data) {
                    handleData(data);
                },
                error: function(xhr) { // FIX: maneja error (opcional)
                    console.error('Resource fetch error', xhr);
                    handleData([]); // devuelve vacío para no romper la UI
                }
            });
        }
    
        let clickCnt = 0;
    
        const calDefaultViewStr = "$caller" + "calDefaultView";
        const calDefaultScrollStr = "$caller" + "calDefaultScroll";
        const calDefaultDateStr = "$caller" + "calDefaultDate";
    
        const defaultView = (localStorage.getItem(calDefaultViewStr) !== null ? localStorage.getItem(calDefaultViewStr) : '$defaultView');
    
        if (localStorage.getItem(calDefaultDateStr) !== null) {
            // FIX: valida fecha correctamente
            const d = new Date(localStorage.getItem(calDefaultDateStr));
            if (isNaN(d.getTime())) {
                localStorage.removeItem(calDefaultDateStr);
            }
        }
    
        const initialDate = (localStorage.getItem(calDefaultDateStr) !== null ? localStorage.getItem(calDefaultDateStr) : '$initialdate');
        const calendarEl = document.getElementById('calendar');
        const calendar = new FullCalendar.Calendar(calendarEl, {
            schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
            initialDate: initialDate,
            customButtons: {
    
                hoyButton: {
                    text: 'Hoy',
                    click: function() {
                        calendar.today();
    
                        if (calendar.view.type == 'resourceTimelineOneMonth') {
                            $(".fc-day-today").attr("id","scrollTo"); // Set an ID for the current day..
                                
                            if (typeof $("#scrollTo").position() != 'undefined') {
                                $(".fc-scroller").animate({
                                    scrollLeft: $("#scrollTo").position().left // Scroll to this ID
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
              start: '',             // nada a la izquierda
              center: 'title',       // el título ocupa la línea superior
              end: ''                // nada a la derecha
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
                    height: 'auto',
                },
                resourceTimelineOneMonth: {
                    type: 'resourceTimeline',
                    duration: { months: 1 },
                    buttonText: 'Mes Line',
                    slotMinWidth: 120,
                    slotDuration: '24:00:00',
                    resourceAreaWidth: '100px',
                    height: 'auto',
                    // (opcional) esconder las etiquetas del nivel de horas
                    slotLabelContent(arg) {
                        const isHourLevel = arg.level === 1; // si ves que no funciona, prueba === 0
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
                // Evita tooltips duplicados
                if (info.el._tippy) info.el._tippy.destroy();
    
                tippy(info.el, {
                    content: (() => {
                        const tip = info.event.extendedProps.tooltip;
                        
                        if (Array.isArray(tip) && tip.length > 0) {
                            // Caso 1: array → cada string en una línea
                            return tip.map(line => `<div>\${line}</div>`).join('');
                        } else if (typeof tip === 'string' && tip.trim() !== '') {
                            // Caso 2: cadena → se muestra directamente
                            return tip;
                        } else {
                            // Caso 3: sin tiptool → usa el título
                            return info.event.title || '';
                        }
                    })(),
                    allowHTML: true,
                    trigger: 'mouseenter focus',
                    delay: [100, 80],
                    placement: 'top',
                    touch: ['hold', 120], // o simplemente 'hold' si tu versión no soporta array
                    appendTo: document.body
                });
    
                // Prevenir selección / callout en móviles
                info.el.style.userSelect = 'none';
                info.el.style.webkitUserSelect = 'none';
                info.el.style.webkitTouchCallout = 'none';
                info.el.style.touchAction = 'manipulation';
            },
            resourceAreaHeaderContent: '$defaultLabel',
            locale: 'es',
            nowIndicator: true,
            contentHeight: 800,
            editable: false,
            refetchResourcesOnNavigate: true,
    
            // FIX: la firma correcta de v5/v6 pasa fetchInfo (con .timeZone, no .timezone)
            resources: function(fetchInfo, successCallback, failureCallback) {
                // Pasamos los Date a tu helper
                getResources(fetchInfo.start, fetchInfo.end, fetchInfo.timeZone, function(resources) {
                    
                    if (localStorage.getItem(calDefaultScrollStr) === null) {
                        setTimeout(function() { // Timeout
                            $(".fc-day-today").attr("id", "scrollTo"); // Set an ID for the current day..
                            if (typeof $("#scrollTo").position() != 'undefined') {
                                $(".fc-scroller").animate({
                                    scrollLeft: $("#scrollTo").position().left // Scroll to this ID
                                }, 2000);
                            }
                        }, 500);
                    }
            
                    // Devuelve los recursos cargados
                    successCallback(resources);
            
                    // 🔹 Ajusta la altura automáticamente DESPUÉS de renderizar los recursos
                    setTimeout(function() {
                        // Solo aplica el ajuste si estamos en una vista tipo resourceTimeline
                        const viewType = calendar.view.type;
                        if (viewType.startsWith('resourceTimeline')) {
                            calendar.setOption('height', 'auto'); // recalcula según recursos visibles
                            calendar.updateSize();                 // fuerza redibujo del layout
                        }
                    }, 300); // Pequeño delay para que el DOM termine de renderizar
                });
            },
            events: '$defaultEventUrl'
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