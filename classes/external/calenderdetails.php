<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_learnwise\external;

defined('MOODLE_INTERNAL') || die();

use context_user;
use core_calendar\external\calendar_event_exporter;
use core_calendar\local\event\container;
use core_calendar\local\event\entities\event_interface;
use external_function_parameters;
use external_value;

global $CFG;
require_once($CFG->dirroot.'/calendar/externallib.php');

/**
 * Class getcalenderdetails
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calenderdetails extends baseapi {

    /**
     * Summary of route
     *
     * @var string
     */
    public static $route = 'calendar';

    /**
     * Summary of execute_parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters ([
            'courseid' => new external_value(PARAM_INT, 'Course being viewed', VALUE_DEFAULT, SITEID, NULL_NOT_ALLOWED),
            'categoryid' => new external_value(PARAM_INT, 'Category being viewed', VALUE_DEFAULT, null, NULL_ALLOWED),
        ]);
    }

    /**
     * Summary of execute
     *
     * @param mixed $courseid
     * @param mixed $categoryid
     */
    public static function execute($courseid, $categoryid) {
        global $USER;
        $params = static::validate_parameters(static::execute_parameters(),
        ['courseid' => $courseid, 'categoryid' => $categoryid]);

        $context = context_user::instance($USER->id);
        static::validate_context($context);

        $calendar = \calendar_information::create(time(), $params['courseid'], $params['categoryid']);
        if ($params['courseid'] > SITEID && in_array(SITEID, $calendar->courses)) {
            $calendar->courses = array_diff($calendar->courses, [SITEID]);
        }

        $data = self::calendar_get_events($calendar);

        return $data->events;
    }

    /**
     * Summary of single_structure
     *
     * @return array
     */
    public static function single_structure() {
        return calendar_event_exporter::get_read_structure();
    }

    /**
     * Summary of calendar_get_events
     *
     * @param \calendar_information $calendar Calendar information
     * @param int|null $lookahead How many days to look for events
     * @return \stdClass
     */
    public static function calendar_get_events(\calendar_information $calendar, ?int $lookahead = null) {
        global $PAGE, $CFG;

        $renderer = $PAGE->get_renderer('core_calendar');
        $type = \core_calendar\type_factory::get_calendar_instance();

        // Calculate the bounds of the month.
        $calendardate = $type->timestamp_to_date_array($calendar->time);

        $date = new \DateTime('now', \core_date::get_user_timezone_object(99));
        $eventlimit = 0;

        // Number of days in the future that will be used to fetch events.
        if (!$lookahead) {
            if (isset($CFG->calendar_lookahead)) {
                $defaultlookahead = intval($CFG->calendar_lookahead);
            } else {
                $defaultlookahead = CALENDAR_DEFAULT_UPCOMING_LOOKAHEAD;
            }
            $lookahead = get_user_preferences('calendar_lookahead', $defaultlookahead);
        }

        // Maximum number of events to be displayed on upcoming view.
        $defaultmaxevents = CALENDAR_DEFAULT_UPCOMING_MAXEVENTS;
        if (isset($CFG->calendar_maxevents)) {
            $defaultmaxevents = intval($CFG->calendar_maxevents);
        }
        $eventlimit = get_user_preferences('calendar_maxevents', $defaultmaxevents);

        $tstart = $type->convert_to_timestamp($calendardate['year'], $calendardate['mon'], $calendardate['mday'],
                $calendardate['hours']);
        $date->setTimestamp($tstart);
        $date->modify('+' . $lookahead . ' days');

        // We need to extract 1 second to ensure that we don't get into the next day.
        $date->modify('-1 second');
        $tend = $date->getTimestamp();

        list($userparam, $groupparam, $courseparam, $categoryparam) = array_map(function($param) {
            // If parameter is true, return null.
            if ($param === true) {
                return null;
            }

            // If parameter is false, return an empty array.
            if ($param === false) {
                return [];
            }

            // If the parameter is a scalar value, enclose it in an array.
            if (!is_array($param)) {
                return [$param];
            }

            // No normalisation required.
            return $param;
        }, [$calendar->users, $calendar->groups, $calendar->courses, $calendar->categories]);

        $vault = container::get_event_vault();
        $events = $vault->get_events(
            $tstart,
            $tend,
            null,
            null,
            null,
            null,
            $eventlimit,
            null,
            $userparam,
            $groupparam,
            $courseparam,
            $categoryparam,
            true,
            true,
            function (event_interface $event) use ($calendar) {
                if ($proxy = $event->get_category()) {
                    $category = $proxy->get_proxied_instance();

                    return $category->is_uservisible();
                }

                if ($calendar->courseid > SITEID && $event->get_course()->get('id') != $calendar->courseid) {
                    return false;
                }

                return true;
            }
        );

        $related = [
            'events' => $events,
            'cache' => new \core_calendar\external\events_related_objects_cache($events),
            'type' => $type,
        ];

        $upcoming = new \core_calendar\external\calendar_upcoming_exporter($calendar, $related);
        $data = $upcoming->export($renderer);

        return $data;
    }

    /**
     * Summary of get_unixtimestamp_fields
     *
     * @return array
     */
    public static function get_unixtimestamp_fields() {
        return ['timestart', 'timesort', 'timemodified'];
    }
}
