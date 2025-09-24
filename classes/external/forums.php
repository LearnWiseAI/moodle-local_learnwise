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

use context_course;
use context_module;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_learnwise\external\forum\discussions;

/**
 * Class forums
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forums extends baseapi {
    /**
     * The name of the API.
     *
     * @var string
     */
    public static $route = 'forums';

    /**
     * Returns the parameters for the execute function.
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return static::base_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * Returns the description of the execute function.
     *
     * @param int $courseid ID of course
     * @return external_description
     */
    public static function execute($courseid) {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $params = static::validate_parameters(static::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = context_course::instance($params['courseid']);
        if (static::is_singleoperation()) {
            $context = context_module::instance(static::get_id());
        }
        static::validate_context($context);
        if (static::is_singleoperation()) {
            require_capability('mod/forum:viewdiscussion', $context);
        }

        $course = get_course($params['courseid']);

        $modinfo = get_fast_modinfo($course);

        $foruminfos = [];
        foreach ($modinfo->get_instances_of('forum') as $cm) {
            if (static::skip_record($cm->id)) {
                continue;
            }

            $context = context_module::instance($cm->id);
            if (!has_capability('mod/forum:viewdiscussion', $context)) {
                continue;
            }

            $foruminfo = [
                'id' => $cm->id,
                'name' => $cm->get_formatted_name(),
            ];

            if (static::is_singleoperation()) {
                $foruminfo['discussions'] = discussions::execute($params['courseid'], $cm->id);
                return $foruminfo;
            }

            $foruminfos[] = $foruminfo;
        }
        return $foruminfos;
    }

    /**
     * Returns the structure of a single forum.
     *
     * @return external_single_structure
     */
    public static function single_structure() {
        $structure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'course module id of assignment'),
            'name' => new external_value(PARAM_TEXT, 'name of assignment'),
        ]);
        if (static::is_singleoperation()) {
            $structure->keys['discussions'] = new external_multiple_structure(
                discussions::single_structure()
            );
        }
        return $structure;
    }
}
