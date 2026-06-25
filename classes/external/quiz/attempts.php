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

namespace local_learnwise\external\quiz;

use external_function_parameters;
use external_single_structure;
use external_value;
use local_learnwise\external\baseapi;
use mod_quiz_external;

/**
 * Class attempts
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempts extends baseapi {
    /**
     * The name of the API.
     *
     * @var string
     */
    public static $route = 'attempts';

    /**
     * Summary of execute_parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return static::base_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
        ]);
    }

    /**
     * Returns the description of the execute function.
     *
     * @param int $courseid ID of course
     * @param int $quizid ID of quiz
     * @return array
     */
    public static function execute($courseid, $quizid) {
        global $DB, $USER;
        $params = static::validate_parameters(static::execute_parameters(), [
            'courseid' => $courseid,
            'quizid' => $quizid,
        ]);
        $params['userid'] = $USER->id;

        $modinfo = get_fast_modinfo($params['courseid'], $params['userid']);
        $cm = $modinfo->get_cm($params['quizid']);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $result = mod_quiz_external::get_user_attempts($cm->instance, $params['userid'], 'all', true);

        $attempts = $result['attempts'];

        foreach ($attempts as &$attempt) {
            $attempt = (array) $attempt;
            if (static::skip_record($attempt['id'])) {
                continue;
            }
            if (empty($attempt['timefinish'])) {
                $attempt['timefinish'] = null;
            }

            if ($attempt['sumgrades'] > 0) {
                $attempt['grade'] = quiz_rescale_grade($attempt['sumgrades'], $quiz);
            }
            if (static::is_singleoperation()) {
                return $attempt;
            }
        }

        return $attempts;
    }

    /**
     * Returns the fields that should be treated as Unix timestamps.
     *
     * @return array
     */
    public static function get_unixtimestamp_fields() {
        return ['timestart', 'timefinish'];
    }

    /**
     * Summary of single_structure
     * @return external_single_structure
     */
    public static function single_structure() {
        $definedorder = array_flip(['id', 'state', 'sumgrades', 'grade', 'timestart', 'timefinish']);
        $returnstructure = mod_quiz_external::get_user_attempts_returns();
        $attemptstructure = $returnstructure->keys['attempts']->content;
        $attemptstructure->keys = array_filter($attemptstructure->keys, function ($key) {
            return in_array($key, ['id', 'state', 'sumgrades', 'timestart', 'timefinish']);
        }, ARRAY_FILTER_USE_KEY);
        $attemptstructure->keys['grade'] = new external_value(
            PARAM_FLOAT,
            'grade for the quiz attempts ',
            VALUE_DEFAULT,
            null
        );
        $attemptstructure->keys = array_replace(
            $definedorder,
            array_intersect_key($attemptstructure->keys, $definedorder)
        );
        return $attemptstructure;
    }
}
