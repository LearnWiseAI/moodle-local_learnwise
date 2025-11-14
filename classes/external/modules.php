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

use completion_info;
use context_module;
use external_single_structure;
use external_value;

/**
 * Class modules
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modules extends baseapi {
    /**
     * The route for the course modules API.
     *
     * @var string
     */
    public static $route = 'modules';

    /**
     * Indicates whether course modules should include completion information.
     *
     * @var bool
     */
    public static $withcompletion = false;

    /**
     * Returns the parameters required for the execute function.
     *
     * @return \external_function_parameters The parameters for the external function.
     */
    public static function execute_parameters() {
        return static::base_parameters();
    }

    /**
     * Executes the external function for course modules.
     *
     * @return mixed The result of the execution.
     */
    public static function execute() {
        $moduleid = static::get_id();
        if (empty($moduleid)) {
            return [];
        }

        $context = context_module::instance($moduleid);
        static::validate_context($context);

        $courseid = $context->get_course_context()->instanceid;
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($courseid);
        $cm = $modinfo->get_cm($moduleid);

        if (!$cm || !$cm->visible || !$cm->is_visible_on_course_page()) {
            return [];
        }

        $moduleinfo = [
            'id' => $cm->id,
            'name' => $cm->get_formatted_name(),
            'type' => $cm->modname,
        ];
        if (!empty(self::$withcompletion)) {
            $moduleinfo['completionstatus'] = null;
            $completioninfo = new completion_info($course);
            $completiondata = $completioninfo->get_data($cm, true, 0, $modinfo);
            if (in_array($completiondata->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS])) {
                $moduleinfo['completionstatus'] = get_string('completed', 'local_learnwise');
            }
        }
        return $moduleinfo;
    }

    /**
     * Returns the structure for a single course module.
     *
     * @return \external_single_structure The structure describing a single course module.
     */
    public static function single_structure() {
        $structure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id of module'),
            'name' => new external_value(PARAM_TEXT, 'name of module'),
            'type' => new external_value(PARAM_COMPONENT, 'type of module'),
            'completionstatus' => new external_value(PARAM_TEXT, 'completion status'),
        ]);
        if (empty(self::$withcompletion)) {
            unset($structure->keys['completionstatus']);
        }
        return $structure;
    }

    /**
     * Checks if the API is being called for a single operation.
     *
     * @return bool
     */
    public static function is_singleoperation() {
        return true;
    }
}
