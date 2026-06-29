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
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_learnwise\util;
use mod_quiz_external;

/**
 * Class quizzes
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizzes extends baseapi {
    /**
     * The name of the API.
     *
     * @var string
     */
    public static $route = 'quizzes';

    #[\Override]
    public static function description() {
        return 'Get quizzes';
    }

    /**
     * Summary of execute_parameters
     * @return external_function_parameters
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
     * @return array
     */
    public static function execute($courseid) {
        $params = static::validate_parameters(static::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = context_course::instance($params['courseid']);
        if (static::is_singleoperation()) {
            $context = context_module::instance(static::get_id());
        }
        static::validate_context($context);

        $result = mod_quiz_external::get_quizzes_by_courses([$params['courseid']]);

        $quizzes = $result['quizzes'];

        foreach ($quizzes as &$quiz) {
            $quiz['id'] = $quiz['coursemodule'];
            if (static::skip_record($quiz['coursemodule'])) {
                continue;
            }
            $cmcontext = context_module::instance($quiz['id']);
            if (empty($quiz['timeclose'])) {
                $quiz['timeclose'] = null;
            }
            $quiz['descriptionfiles'] = util::extract_pluginfile_urls_from_text(
                $quiz['intro'],
                $cmcontext->id,
                'mod_quiz',
                'intro',
                null
            );
            if (static::is_singleoperation()) {
                return $quiz;
            }
        }

        return $quizzes;
    }

    /**
     * Returns the fields that should be treated as Unix timestamps.
     *
     * @return array
     */
    public static function get_unixtimestamp_fields() {
        return ['timeclose'];
    }

    /**
     * Summary of single_structure
     * @return external_single_structure
     */
    public static function single_structure() {
        $definedorder = array_flip(['id', 'name', 'intro', 'timeclose', 'grade', 'sumgrades', 'descriptionfiles']);
        $returnstructure = mod_quiz_external::get_quizzes_by_courses_returns();
        $quizstructure = $returnstructure->keys['quizzes']->content;
        $quizstructure->keys['descriptionfiles'] = new external_multiple_structure(
            new external_value(PARAM_URL, 'Description file url'),
            'URL of description files',
            VALUE_OPTIONAL
        );
        $quizstructure->keys = array_replace(
            $definedorder,
            array_intersect_key($quizstructure->keys, $definedorder)
        );
        return $quizstructure;
    }
}
