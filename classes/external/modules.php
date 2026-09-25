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

use context_module;

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
     * {@inheritdoc}
     */
    public static function description() {
        return 'Get module details';
    }

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
        $modinfo = get_fast_modinfo($courseid);
        $cm = $modinfo->get_cm($moduleid);

        if (!$cm || !$cm->visible || !$cm->is_visible_on_course_page()) {
            return [];
        }

        return course_modules::extract_moduleinfo($cm);
    }

    /**
     * Returns the structure for a single course module.
     *
     * @return \external_single_structure The structure describing a single course module.
     */
    public static function single_structure() {
        return course_modules::single_structure();
    }

    /**
     * Checks if the API is being called for a single operation.
     *
     * @return bool
     */
    public static function is_singleoperation() {
        return true;
    }

    /**
     * Returns the fields that should be treated as Unix timestamps.
     *
     * @return array
     */
    public static function get_unixtimestamp_fields() {
        return course_modules::get_unixtimestamp_fields();
    }
}
