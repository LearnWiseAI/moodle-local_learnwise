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

namespace local_learnwise\local;

use core_calendar\action_factory;
use core_calendar\local\event\container;
use core_calendar\local\event\data_access\event_vault;
use core_calendar\local\event\entities\event_interface;
use core_calendar\local\event\factories\event_factory;
use core_calendar\local\event\mappers\event_mapper;
use core_calendar\local\event\strategies\raw_event_retrieval_strategy;

/**
 * Class calendarcontainer
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendarcontainer extends container {

    /**
     * @var bool no filter events.
     */
    public static $nofilter = false;

    /**
     * Summary of init
     * @return mixed
     */
    private static function init() {
        if (empty(self::$eventfactory)) {
            self::$actionfactory = new action_factory();
            self::$eventmapper = new event_mapper(
                // The event mapper we return from here needs to know how to
                // make events, so it needs an event factory. However we can't
                // give it the same one as we store and return in the container
                // as that one uses all our plumbing to control event visibility.
                //
                // So we make a new even factory that doesn't do anyting other than
                // return the instance.
                new event_factory(
                    // Never apply actions, simply return.
                    function(event_interface $event) {
                        return $event;
                    },
                    // Never hide an event.
                    function() {
                        return true;
                    },
                    // Never bail out early when instantiating an event.
                    function() {
                        return false;
                    },
                    self::$coursecache,
                    self::$modulecache
                )
            );

            self::$eventfactory = new event_factory(
                [self::class, 'apply_component_provide_event_action'],
                [self::class, 'apply_component_is_event_visible'],
                function ($dbrow) {
                    $requestinguserid = self::get_requesting_user();

                    if (!empty($dbrow->categoryid)) {
                        // This is a category event. Check that the category is visible to this user.
                        $category = \core_course_category::get($dbrow->categoryid, IGNORE_MISSING, true, $requestinguserid);

                        if (empty($category) || !$category->is_uservisible($requestinguserid)) {
                            return true;
                        }
                    }

                    // For non-module events we assume that all checks were done in core_calendar_is_event_visible callback.
                    // For module events we also check that the course module and course itself are visible to the user.
                    if (empty($dbrow->modulename)) {
                        return false;
                    }

                    $instances = get_fast_modinfo($dbrow->courseid, $requestinguserid)->instances;

                    // If modinfo doesn't know about the module, we should ignore it.
                    if (!isset($instances[$dbrow->modulename]) || !isset($instances[$dbrow->modulename][$dbrow->instance])) {
                        return true;
                    }

                    $cm = $instances[$dbrow->modulename][$dbrow->instance];

                    // If the module is not visible to the current user, we should ignore it.
                    // We have to check enrolment here as well because the uservisible check
                    // looks for the "view" capability however some activities (such as Lesson)
                    // have that capability set on the "Authenticated User" role rather than
                    // on "Student" role, which means uservisible returns true even when the user
                    // is no longer enrolled in the course.
                    // So, with the following we are checking -
                    // 1) Only process modules if $cm->uservisible is true.
                    // 2) Only process modules for courses a user has the capability to view OR they are enrolled in.
                    // 3) Only process modules for courses that are visible OR if the course is not visible, the user
                    // has the capability to view hidden courses.
                    $coursecontext = \context_course::instance($dbrow->courseid);
                    if (!$cm->uservisible && (!is_enrolled($coursecontext, $requestinguserid) && !self::$nofilter)) {
                        return true;
                    }

                    if (!$cm->get_course()->visible &&
                            !has_capability('moodle/course:viewhiddencourses', $coursecontext, $requestinguserid)) {
                        return true;
                    }

                    if (!has_capability('moodle/course:view', $coursecontext, $requestinguserid) &&
                            !is_enrolled($coursecontext, $requestinguserid) && !self::$nofilter) {
                        return true;
                    }

                    // Ok, now check if we are looking at a completion event.
                    if ($dbrow->eventtype === \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED
                        && !self::$nofilter) {
                        // Need to have completion enabled before displaying these events.
                        $course = new \stdClass();
                        $course->id = $dbrow->courseid;
                        $completion = new \completion_info($course);

                        return (bool) !$completion->is_enabled($cm);
                    }

                    return false;
                },
                self::$coursecache,
                self::$modulecache
            );
        }

        if (empty(self::$eventvault)) {
            self::$eventretrievalstrategy = new raw_event_retrieval_strategy();
            self::$eventvault = new event_vault(self::$eventfactory, self::$eventretrievalstrategy);
        }
    }

    /**
     * Returns the event vault instance.
     *
     * @return event_vault
     */
    public static function get_event_vault() {
        self::init();
        return self::$eventvault;
    }

}
