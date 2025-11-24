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

namespace local_learnwise\external\assign;

use assign;
use context_module;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_learnwise\external\baseapi;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Class grade
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade extends baseapi {
    /**
     * The name of the API function.
     *
     * @var string
     */
    public static $route = 'grade';

    /**
     * Returns the parameters for the execute function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'course_id' => new external_value(PARAM_INT, 'Course ID'),
            'assignment_id' => new external_value(PARAM_INT, 'Assignment ID'),
            'user_id' => new external_value(PARAM_INT, 'User ID'),
            'rubric_assessment' => new external_single_structure([
                'submission_grade' => new external_value(PARAM_FLOAT, 'Submission Grade'),
                'rubric_assessments' => new external_single_structure([
                    'rubric_feedback_array' => new external_multiple_structure(
                        new external_single_structure([
                            'rubric_section_id' => new external_value(PARAM_TEXT, 'Rubric Section ID'),
                            'content' => new external_value(PARAM_TEXT, 'Remarks content', VALUE_OPTIONAL),
                            'graded_lms_rubric_rating_id' => new external_value(
                                PARAM_TEXT,
                                'Graded LMS Rubric Rating ID',
                                VALUE_OPTIONAL
                            ),
                            'graded_score' => new external_value(PARAM_FLOAT, 'Graded Score', VALUE_OPTIONAL),
                        ]),
                        'Rubric Feedback Array',
                        VALUE_OPTIONAL
                    ),
                ], 'Rubric Assessments', VALUE_OPTIONAL),
                'general_feedback' => new external_value(PARAM_TEXT, 'General Feedback', VALUE_OPTIONAL),
            ]),
        ]);
    }

    /**
     * Grade user assignment
     *
     * @param int $courseid
     * @param int $assignmentid
     * @param int $userid
     * @param array $rubricassessment
     * @throws \moodle_exception
     * @return array
     */
    public static function execute($courseid, $assignmentid, $userid, $rubricassessment) {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'course_id' => $courseid,
                'assignment_id' => $assignmentid,
                'user_id' => $userid,
                'rubric_assessment' => $rubricassessment,
            ]
        );

        $cm = $DB->get_record('course_modules', ['id' => $params['assignment_id']], '*', MUST_EXIST);
        [$assignment, $course, $cm, $context] = self::validate_assignment($cm->instance);

        $grade = $assignment->get_user_grade($params['user_id'], true);
        $originalgrade = $grade->grade;
        $gradingdisabled = $assignment->grading_disabled($params['user_id']);

        $rubricassessment = (object) $params['rubric_assessment'];
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $gradinginstance = null;
        if ($gradingmethod = $gradingmanager->get_active_method()) {
            $controller = $gradingmanager->get_controller($gradingmethod);
            if ($controller->is_form_available()) {
                $itemid = null;
                if ($grade) {
                    $itemid = $grade->id;
                }
                if ($gradingdisabled && $itemid) {
                    $gradinginstance = $controller->get_current_instance($USER->id, $itemid);
                } else if (!$gradingdisabled) {
                    $instanceid = optional_param('advancedgradinginstanceid', null, PARAM_INT);
                    $gradinginstance = $controller->get_or_create_instance(
                        $instanceid,
                        $USER->id,
                        $itemid
                    );
                }
            }
        } else if (is_null($gradingmethod)) {
            $grade->grade = grade_floatval(unformat_float($rubricassessment->submission_grade));
        }

        if ($gradinginstance) {
            $grademenu = make_grades_menu($assignment->get_instance()->grade);
            $allowgradedecimals = $assignment->get_instance()->grade > 0;
            $gradinginstance->get_controller()->set_grade_range($grademenu, $allowgradedecimals);
        }

        if (!$gradingdisabled && $gradinginstance) {
            $criteria = [];
            if (!empty($rubricassessment->rubric_assessments)) {
                foreach ($rubricassessment->rubric_assessments['rubric_feedback_array'] as $feedback) {
                    $content = !empty($feedback['content']) ? $feedback['content'] : null;
                    $criteria[$feedback['rubric_section_id']] = [
                        'levelid' => $feedback['graded_lms_rubric_rating_id'],
                        'remark' => $content,
                    ];
                }
            }
            if (!empty($criteria)) {
                $advancegradingdata = ['criteria' => $criteria];
                $grade->grade = $gradinginstance->submit_and_get_grade(
                    $advancegradingdata,
                    $grade->id
                );
            }
        }
        $grade->grader = $USER->id;

        $feedbackmodified = false;
        $feedbackplugins = $assignment->load_plugins('assignfeedback');
        foreach ($feedbackplugins as $plugin) {
            if ($plugin->is_enabled() && $plugin->is_visible()) {
                $formdata = new stdClass();
                $formdata->assignfeedbackcomments_editor = [
                    'text' => $rubricassessment->general_feedback,
                    'format' => 1,
                ];
                $gradingmodified = $plugin->is_feedback_modified($grade, $formdata);
                if ($gradingmodified) {
                    if (!$plugin->save($grade, $formdata)) {
                        throw new \moodle_exception('error', 'moodle', '', $plugin->get_error());
                    }
                    $feedbackmodified = true;
                }
            }
        }

        if (
            ($originalgrade !== null && $originalgrade != -1) ||
                ($grade->grade !== null && $grade->grade != -1) || $feedbackmodified
        ) {
            $assignment->update_grade($grade);
        }

        if ($grade->grade > 0) {
            return ['success' => true];
        } else {
            return [
                'success' => false,
                'error' => get_string('gradingdisabled', 'local_learnwise'),
            ];
        }
    }

    /**
     * Returns the structure of the response for the execute function.
     *
     * @return external_single_structure
     */
    public static function single_structure() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status (True for success, False for failure)'),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Validate assignment id and get necessary data for ws to run
     *
     * @param int $assignid
     * @return array<assign|context_module|mixed>
     */
    public static function validate_assignment($assignid) {
        global $DB;

        $assign = $DB->get_record('assign', ['id' => $assignid], 'id', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($assign, 'assign');

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        $assign = new assign($context, $cm, $course);

        return [$assign, $course, $cm, $context];
    }

    /**
     * Indicate function response is singular
     *
     * @return bool
     */
    public static function is_singleoperation() {
        return true;
    }
}
