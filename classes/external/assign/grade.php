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
use invalid_parameter_exception;
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

    #[\Override]
    public static function crudtype() {
        return 'write';
    }

    #[\Override]
    public static function description() {
        return 'Submit assignment grade';
    }

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
                    'guide_feedback_array' => new external_multiple_structure(
                        new external_single_structure([
                            'rubric_section_id' => new external_value(PARAM_INT, 'Guide Level ID'),
                            'content' => new external_value(PARAM_TEXT, 'Guide Remarks', VALUE_OPTIONAL),
                            'graded_score' => new external_value(PARAM_FLOAT, 'Guide score'),
                        ]),
                        'Guide Feedback Array',
                        VALUE_OPTIONAL
                    ),
                ], 'Rubric Assessments', VALUE_OPTIONAL),
                'general_feedback' => new external_value(PARAM_TEXT, 'General Feedback', VALUE_OPTIONAL),
            ]),
            'advancedgradinginstanceid' => new external_value(PARAM_INT, 'needed if user grade record not found', VALUE_DEFAULT),
        ]);
    }

    /**
     * Grade user assignment
     *
     * @param int $courseid
     * @param int $assignmentid
     * @param int $userid
     * @param array $rubricassessment
     * @param int|null $advancedgradinginstanceid
     * @throws \moodle_exception
     * @return array
     */
    public static function execute($courseid, $assignmentid, $userid, $rubricassessment, $advancedgradinginstanceid = null) {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'course_id' => $courseid,
                'assignment_id' => $assignmentid,
                'user_id' => $userid,
                'rubric_assessment' => $rubricassessment,
                'advancedgradinginstanceid' => $advancedgradinginstanceid,
            ]
        );

        $cm = $DB->get_record('course_modules', ['id' => $params['assignment_id']], '*', MUST_EXIST);
        [$assignment, $course, $cm, $context] = self::validate_assignment($cm->instance);
        require_capability('mod/assign:grade', $context);

        if ($course->id != $params['course_id']) {
            throw new invalid_parameter_exception('The assignment does not belong to the specified course.');
        }

        if (!$assignment->get_participant($params['user_id'])) {
            throw new invalid_parameter_exception('The user is not a participant in this assignment.');
        }

        $assignment->require_view_submission($params['user_id']);

        if ($assignment->grading_disabled($params['user_id'])) {
            return ['success' => false, 'error' => get_string('gradingdisabled', 'local_learnwise')];
        }

        // Workflow state belongs to the user's flags, not the assignment grade row.
        if ($assignment->get_instance()->markingworkflow) {
            $flags = $assignment->get_user_flags($params['user_id'], false);
            if (
                !empty($flags->workflowstate) && $flags->workflowstate !== ASSIGN_MARKING_WORKFLOW_STATE_NOTMARKED &&
                    !array_key_exists($flags->workflowstate, $assignment->get_marking_workflow_states_for_current_user())
            ) {
                return ['success' => false, 'error' => get_string('gradingdisabled', 'local_learnwise')];
            }
        }

        $rubricassessment = (object) $params['rubric_assessment'];
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $gradingmethod = $gradingmanager->get_active_method();
        if (is_null($gradingmethod)) {
            $mark = $rubricassessment->submission_grade;
            $maximum = $assignment->get_instance()->grade;
            $invalid = !is_finite($mark) || ($mark != -1 && ($mark < 0 || ($maximum >= 0 && $mark > $maximum)));
            if ($maximum < 0 && $mark != -1) {
                $scale = $DB->get_record('scale', ['id' => -$maximum], '*', MUST_EXIST);
                $options = make_menu_from_list($scale->scale);
                $invalid = $invalid || $mark != (int) $mark || !array_key_exists((int) $mark, $options);
            }
            if ($invalid) {
                return ['success' => false, 'error' => get_string('gradingfailed', 'local_learnwise')];
            }
        }

        if ($gradingmethod && !$gradingmanager->get_controller($gradingmethod)->is_form_available()) {
            return ['success' => false, 'error' => get_string('gradingfailed', 'local_learnwise')];
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $grade = $assignment->get_user_grade($params['user_id'], true);
            $originalgrade = $grade->grade;
            $gradinginstance = null;
            if ($gradingmethod) {
                $controller = $gradingmanager->get_controller($gradingmethod);
                if ($controller->is_form_available()) {
                    $itemid = null;
                    if ($grade) {
                        $itemid = $grade->id;
                    }
                    $gradinginstance = $controller->get_or_create_instance(
                        $params['advancedgradinginstanceid'],
                        $USER->id,
                        $itemid
                    );
                }
            } else if (is_null($gradingmethod)) {
                $grade->grade = grade_floatval(unformat_float($rubricassessment->submission_grade));
            }

            if ($gradinginstance) {
                $grademenu = make_grades_menu($assignment->get_instance()->grade);
                $allowgradedecimals = $assignment->get_instance()->grade > 0;
                $gradinginstance->get_controller()->set_grade_range($grademenu, $allowgradedecimals);
            }

            if ($gradinginstance) {
                $criteria = [];
                if (!empty($rubricassessment->rubric_assessments)) {
                    if (isset($rubricassessment->rubric_assessments['rubric_feedback_array'])) {
                        foreach ($rubricassessment->rubric_assessments['rubric_feedback_array'] as $feedback) {
                            $content = !empty($feedback['content']) ? $feedback['content'] : null;
                            $criteria[$feedback['rubric_section_id']] = [
                                'levelid' => $feedback['graded_lms_rubric_rating_id'] ?? null,
                                'remark' => $content,
                                'grade' => !empty($feedback['graded_score']) ? $feedback['graded_score'] : 0,
                            ];
                        }
                    }
                    if (isset($rubricassessment->rubric_assessments['guide_feedback_array'])) {
                        foreach ($rubricassessment->rubric_assessments['guide_feedback_array'] as $feedback) {
                            $content = !empty($feedback['content']) ? $feedback['content'] : null;
                            $criteria[$feedback['rubric_section_id']] = [
                                'remark' => $content,
                                'score' => !empty($feedback['graded_score']) ? $feedback['graded_score'] : 0,
                            ];
                        }
                    }
                }
                if (!empty($criteria)) {
                    $advancegradingdata = ['criteria' => $criteria];
                    if (!$gradinginstance->validate_grading_element($advancegradingdata)) {
                        throw new \moodle_exception('gradingfailed', 'local_learnwise');
                    }
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
                if (isset($rubricassessment->general_feedback) && $plugin->is_enabled() && $plugin->is_visible()) {
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
                if (!$assignment->update_grade($grade)) {
                    throw new \moodle_exception('gradingfailed', 'local_learnwise');
                }
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        return ['success' => true];
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
