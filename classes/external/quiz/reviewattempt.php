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

use context_module;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_learnwise\external\baseapi;

/**
 * Class reviewattempt
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reviewattempt extends baseapi {
    /**
     * The name of the API.
     *
     * @var string
     */
    public static $route = 'review';

    /**
     * Summary of execute_parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return static::base_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id'),
        ]);
    }

    /**
     * Returns the description of the execute function.
     *
     * @param int $courseid ID of course
     * @param int $quizid ID of quiz
     * @param int $attemptid ID of quiz attempt
     * @return array
     */
    public static function execute($courseid, $quizid, $attemptid) {
        global $CFG, $DB, $PAGE, $USER;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $params = static::validate_parameters(static::execute_parameters(), [
            'courseid' => $courseid,
            'quizid' => $quizid,
            'attemptid' => $attemptid,
        ]);
        $params['userid'] = $USER->id;

        $context = context_module::instance($params['quizid']);
        self::validate_context($context);

        $attemptobj = quiz_create_attempt_handling_errors($params['attemptid'], $params['quizid']);
        $attemptobj->check_review_capability();

        $options = $attemptobj->get_display_options(true);

        $grade = quiz_rescale_grade($attemptobj->get_attempt()->sumgrades, $attemptobj->get_quiz(), false);

        $questions = [];

        foreach ($attemptobj->get_slots('all') as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            if ($attemptobj->is_blocked_by_previous_question($slot)) {
                continue;
            }

            $questiondata = [];

            $qtoutput = $qa->get_question()->get_renderer($PAGE);
            $behaviouroutput = $qa->get_behaviour()->get_renderer($PAGE);
            $questiondata['question'] = $qtoutput->formulation_and_controls($qa, $options);

            if ($options->marks && $qa->get_max_mark() > 0) {
                $questiondata['mark'] = (float) $qa->format_mark($options->markdp);
                $questiondata['maxmark'] = (float) $qa->format_max_mark($options->markdp);
            }

            $questiondata['feedback'] = $qtoutput->feedback($qa, $options) .
                $behaviouroutput->feedback($qa, $options) .
                $options->extrainfocontent;
            if (empty($questiondata['feedback'])) {
                $questiondata['feedback'] = null;
            }

            $questiondata['questiontype'] = $qa->get_question()->get_type_name();
            if ($options->correctness) {
                $questionstate = $qa->get_state();
                if ($questionstate->is_correct()) {
                    $questiondata['correctness'] = 'correct';
                } else if ($questionstate->is_partially_correct()) {
                    $questiondata['correctness'] = 'partiallycorrect';
                } else if ($questionstate->is_incorrect()) {
                    $questiondata['correctness'] = 'wrong';
                } else if ($questionstate->is_gave_up()) {
                    $questiondata['correctness'] = 'notanswered';
                } else if ($questionstate->get_summary_state() === 'needsgrading') {
                    $questiondata['correctness'] = 'needsgrading';
                }
            }

            $questiondata['comment'] = $qtoutput->manual_comment($qa, $options) .
                $behaviouroutput->manual_comment($qa, $options);
            if (empty($questiondata['comment'])) {
                unset($questiondata['comment']);
            }

            $questions[] = $questiondata;
        }

        return [
            'grade' => $grade,
            'questions' => $questions,
        ];
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
     * Summary of single_structure
     * @return external_single_structure
     */
    public static function single_structure() {
        $questionstructure = new external_single_structure([
            'question' => new external_value(PARAM_RAW, 'question test', VALUE_OPTIONAL),
            'response' => new external_value(PARAM_RAW, 'student\'s response', VALUE_OPTIONAL),
            'mark' => new external_value(PARAM_FLOAT, 'question attempt\'s mark given to student', VALUE_OPTIONAL),
            'maxmark' => new external_value(PARAM_FLOAT, 'question attempt\'s max marks', VALUE_OPTIONAL),
            'feedback' => new external_value(PARAM_RAW, 'question attempt\'s feedback', VALUE_OPTIONAL),
            'comment' => new external_value(PARAM_RAW, 'question attempt\'s comment', VALUE_OPTIONAL),
            'correctness' => new external_value(PARAM_RAW, 'question attempt\'s correctness', VALUE_OPTIONAL),
            'status' => new external_value(PARAM_RAW, 'question attempt\'s status', VALUE_OPTIONAL),
            'questiontype' => new external_value(PARAM_RAW, 'question type', VALUE_OPTIONAL),
        ]);
        return new external_single_structure([
            'grade' => new external_value(PARAM_FLOAT, 'grade for the quiz (or empty or "notyetgraded")'),
            'questions' => new external_multiple_structure($questionstructure, 'questions'),
        ]);
    }
}
