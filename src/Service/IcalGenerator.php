<?php

namespace App\Service;

use App\Model\IcalCalendar;
use Jsvrcek\ICS\Model\CalendarEvent;
use Jsvrcek\ICS\Model\CalendarAlarm;
use Jsvrcek\ICS\Model\CalendarFreeBusy;
use Jsvrcek\ICS\Model\CalendarTodo;

use Jsvrcek\ICS\Model\Relationship\Attendee;
use Jsvrcek\ICS\Model\Relationship\Organizer;

use Jsvrcek\ICS\Model\Description\Geo;
use Jsvrcek\ICS\Model\Description\Location;

use Jsvrcek\ICS\Model\Recurrence\RecurrenceRule;

use Jsvrcek\ICS\Utility\Formatter;
use Jsvrcek\ICS\CalendarStream;
use Jsvrcek\ICS\CalendarExport;
/**
 * Service IcalGenerator
 *
 * @package jorgelive\IcalBundle\Factory
 * @author  jorge GOMEZ <gomez.valencia@outlook.com>
 */
class IcalGenerator
{
    /**
     * @var \DateTimeZone
     */
    protected \DateTimeZone $timezone;

    /**
     * @var string
     */
    protected string $prodid;

    /**
     * Create new calendar
     *
     * @return IcalCalendar
     */
    public function createCalendar(): IcalCalendar
    {
        $calendar = new IcalCalendar();

        if(!is_null($this->timezone)) {
            $calendar->setTimezone($this->timezone);
        }

        if(!is_null($this->prodid)) {
            $calendar->setProdId($this->prodid);
        }

        return $calendar;
    }

    /**
     * Create new CalendarEvent
     *
     * @return CalendarEvent
     */
    public function createCalendarEvent(): CalendarEvent
    {
        return new CalendarEvent();

    }

    /**
     * Create new CalendarAlarm
     *
     * @return CalendarAlarm
     */
    public function createCalendarAlarm(): CalendarAlarm
    {
        return new CalendarAlarm();
    }

    /**
     * Create new CalendarFreeBusy
     *
     * @return CalendarFreeBusy
     */
    public function createCalendarFreeBusy(): CalendarFreeBusy
    {
        return new CalendarFreeBusy();
    }

    /**
     * Create new CalendarTodo
     *
     * @return CalendarTodo
     */
    public function createCalendarTodo(): CalendarTodo
    {
        return new CalendarTodo();

    }

    /**
     * Create new Attendee
     *
     * @return Attendee
     */
    public function createAttendee(): Attendee
    {
        return new Attendee(new Formatter());

    }

    /**
     * Create new Organizer
     *
     * @return Organizer
     */
    public function createOrganizer(): Organizer
    {
        return new Organizer(new Formatter());

    }

    /**
     * Create new Geo
     *
     * @return Geo
     */
    public function createGeo(): Geo
    {
        return new Geo();

    }

    /**
     * Create new Location
     *
     * @return Location
     */
    public function createLocation(): Location
    {
        return new Location();

    }

    /**
     * Create new RecurrenceRule
     *
     * @return RecurrenceRule
     */
    public function createRecurrenceRule(): RecurrenceRule
    {
        return new RecurrenceRule(new Formatter());

    }

    /**
     * Set default timezone for calendars
     *
     * @param string $timezone
     */
    public function setTimezone(string $timezone): self
    {
        $this->timezone = new \DateTimeZone($timezone);

        return $this;
    }

    /**
     * Set default prodid for calendars
     *
     * @param string $prodid
     */
    public function setProdid(string $prodid): self
    {
        $this->prodid = $prodid;

        return $this;
    }
}
