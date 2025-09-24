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

use completion_completion;
use completion_info;
use context_course;
use context_user;
use core_course_category;
use external_single_structure;
use external_value;
use moodle_url;

/**
 * Class getcourses
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courses extends baseapi {
    /**
     * The name of the API function.
     *
     * @var string
     */
    public static $route = 'courses';

    /**
     * Returns the parameters for the execute function.
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return static::base_parameters();
    }

    /**
     * Returns the description of the execute function.
     *
     * @return \external_description
     */
    public static function execute() {
        global $CFG, $USER;
        require_once($CFG->libdir . '/completionlib.php');

        if (static::is_singleoperation()) {
            $coursecontext = context_course::instance(static::get_id());
            static::validate_context($coursecontext);
        } else {
            $usercontext = context_user::instance($USER->id);
            static::validate_context($usercontext);
        }

        $response = [];
        if (!empty(baseapi::$my)) {
            $courses = enrol_get_all_users_courses($USER->id, true);
        } else {
            $category = core_course_category::user_top();
            $courses = $category->get_courses(['recursive' => true, 'sort' => ['fullname' => 1]]);
        }
        foreach ($courses as $course) {
            if (static::skip_record($course->id)) {
                continue;
            }
            $courseitem = [
                'id' => $course->id,
                'name' => $course->fullname,
                'shortname' => $course->shortname,
                'startdate' => $course->startdate > 0 ? $course->startdate : null,
                'enddate' => $course->enddate > 0 ? $course->enddate : null,
            ];
            $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
            $courseitem['url'] = $courseurl->out(false);
            $coursecontext = context_course::instance($course->id);
            $isenrolled = is_enrolled($coursecontext);
            if (empty(baseapi::$my) && $isenrolled) {
                $completionifo = new completion_info($course);
                $courseitem['participants'] = $completionifo->get_num_tracked_users();
            } else {
                $completion = new completion_completion([
                    'course' => $course->id,
                    'userid' => $USER->id,
                ]);
                $courseitem['completionstatus'] = $courseitem['completiondate'] = null;
                if ($completion->is_complete()) {
                    $courseitem['completionstatus'] = get_string('completed', 'local_learnwise');
                    $courseitem['completiondate'] = $completion->timecompleted;
                }
            }
            if ($isenrolled) {
                $courseitem['modules'] = course_modules::execute($course->id);
            }
            if (static::is_singleoperation()) {
                return $courseitem;
            }
            $response[] = $courseitem;
        }
        return $response;
    }

    /**
     * Returns the structure of the response for the execute function.
     *
     * @return \external_single_structure
     */
    public static function single_structure() {
        $modulestructure = course_modules::execute_returns();
        $modulestructure->required = VALUE_OPTIONAL;
        $structure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id of course'),
            'name' => new external_value(PARAM_TEXT, 'name of course'),
            'shortname' => new external_value(PARAM_TEXT, 'code of course'),
            'startdate' => new external_value(PARAM_INT, 'start date of course in unix format'),
            'enddate' => new external_value(PARAM_INT, 'start date of course in unix format'),
            'participants' => new external_value(PARAM_INT, 'count of participants', VALUE_DEFAULT, 0),
            'completionstatus' => new external_value(PARAM_TEXT, 'completion status'),
            'completiondate' => new external_value(PARAM_INT, 'completion date of course in unix format'),
            'url' => new external_value(PARAM_URL, 'url of course'),
            'modules' => $modulestructure,
        ]);
        if (!empty(baseapi::$my)) {
            unset($structure->keys['participants']);
        } else {
            unset(
                $structure->keys['completionstatus'],
                $structure->keys['completiondate']
            );
        }
        return $structure;
    }

    /**
     * Returns the fields that should be converted to unix timestamps.
     *
     * @return array
     */
    public static function get_unixtimestamp_fields() {
        return ['startdate', 'enddate'];
    }
}
