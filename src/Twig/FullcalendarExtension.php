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
            $views = ['resourceTimeGridDay', 'timeGridWeek', 'dayGridMonth', 'listMonth', 'resourceTimelineTwoDays'];
        }

        if (empty($defaultView)){
            $defaultView = 'listMonth';
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
        
        
        
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
            timeZone: 'UTC',
            headerToolbar: {
                left: 'today prev,next',
                center: 'title',
                right: '$views'
            },
            initialView: '$defaultView',
            views: {
                resourceTimelineTwoDays: {
                    type: 'resourceTimeline',
                    duration: { days: 2 },
                    buttonText: '2 Dias'
                }
            },
            resourceAreaHeaderContent: '$defaultLabel',
            allDaySlot: $allDaySlot,
            locale: 'es',
            nowIndicator: true,
            navLinks: true,
            editable: false,
            dayMaxEventRows: true,
            refetchResourcesOnNavigate: true,
            resources: function(fetchInfo, successCallback, failureCallback) {
                getResources(fetchInfo.start, fetchInfo.end, fetchInfo.timezone, function(resources) {
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