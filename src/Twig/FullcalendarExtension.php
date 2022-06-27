<?php

namespace App\Twig;
use Symfony\Component\Routing\RouterInterface;
use \Twig\Extension\AbstractExtension;

class FullcalendarExtension extends AbstractExtension
{

    private $_router;

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
        if (null === $exists)
        {
            return null;
        }

        return $this->_router->generate('app_fullcalendar_load_event', ['calendar' => $calendar]);
    }

    public function generateResourceUrl($calendar){
        $exists = $this->_router->getRouteCollection()->get('app_fullcalendar_load_resource');
        if (null === $exists)
        {
            return null;
        }

        return $this->_router->generate('app_fullcalendar_load_resource', ['calendar' => $calendar]);
    }

    public function fullcalendar($calendars, $defaultView = null, $views = [], $allDaySlot = false)
    {
        if (empty($views)){
            $views = ['timeGridWeek', 'dayGridMonth', 'listMonth', 'resourceTimelineOneDay'];
        }

        if (empty($defaultView)){
            $defaultView = 'dayGridMonth';
        }

        if (!is_array($calendars) && is_string($calendars)){
            $calendars = ['Default' => $calendars];
        }

        if(!is_array($views) && is_string($views)) {
            $views[] = $views;
        }

        $views = implode(' ', $views);

        foreach ($calendars as $key => $calendar){
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

        $script = <<<JS


    $(document).ready(function() {

        var resourceUrl = '$defaultResourceUrl';
        var s = $("<select style=\"margin-top: 10px; margin-left: 10px;\" id=\"calendarSelector\" />");
        
        s.change(function() {
            calendar.removeAllEvents();
            calendar.addEventSource(data[s.val()]['event']);
            resourceUrl = data[s.val()]['resource'];
            calendar.setOption('resourceAreaHeaderContent', data[s.val()]['nombre']);
        })
        
        var renderDropdown = false;
        
        var data = $calendarsUrls;
        
        for(var val in data) {
            $("<option />", {text: data[val]['nombre'], value: val}).appendTo(s);
            if (val > 0){
                renderDropdown = true;
            } 
        }
        
        if (renderDropdown === true){
            $("#calendar").before(s);
        }
        
        function getResources(start, end, timezone, handleData) {
            var params = { start: start.toISOString().slice(0, 10), end: end.toISOString().slice(0, 10) };
            var strParams = jQuery.param( params );
            
            $.ajax({
                url: resourceUrl + '?' + strParams,
                success:function(data) {
                    handleData(data);
                }
            });
        }
        
        let clickCnt = 0;
        
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
            headerToolbar: {
                left: 'today prev,next',
                center: 'title',
                right: '$views'
            },
            initialView: '$defaultView',
            views: {
                resourceTimelineOneDay: {
                    type: 'resourceTimeline',
                    duration: { days: 1 },
                    buttonText: 'Dia',
                    resourceAreaWidth: '150px'
                },
                resourceTimelineOneMonth: {
                    type: 'resourceTimeline',
                    duration: { months: 1 },
                    buttonText: 'Mes Line',
                    slotMinWidth: 35,
                    slotDuration: '06:00:00',
                    resourceAreaWidth: '150px'                   
                }
            },
            dateClick: function(info) {
                calendar.changeView('resourceTimelineOneDay');
                calendar.gotoDate(info.date)
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
            resourceAreaHeaderContent: '$defaultLabel',
            locale: 'es',
            nowIndicator: true,
            contentHeight: 800,
            editable: false,
            refetchResourcesOnNavigate: true,
            resources: function(fetchInfo, successCallback, failureCallback) {
                getResources(fetchInfo.start, fetchInfo.end, fetchInfo.timezone, function(resources) {
                    setTimeout(function(){ // Timeout
                        $(".fc-day-today").attr("id","scrollTo"); // Set an ID for the current day..
                        if (typeof $("#scrollTo").position() != 'undefined'){
                            $(".fc-scroller").animate({
                                scrollLeft: $("#scrollTo").position().left // Scroll to this ID
                            }, 2000);
                        }
                    }, 500);
                    successCallback(resources);
                });
            },
            events: '$defaultEventUrl'
        });
        calendar.render();
 
    });

JS;

        return "<script>" . $script . "</script><div style='overflow: scroll;' class='box box-primary'><div style='min-width: 800px; margin: 10px;' id='calendar'></div></div>";
    }
}