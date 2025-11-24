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
use assignfeedback_editpdf\document_services;
use assignfeedback_editpdf\page_editor;
use context_module;
use core_user;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_learnwise\external\baseapi;
use local_learnwise\external\timestampvalue;
use stdClass;

/**
 * Class submission
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submissions extends baseapi {
    /**
     * The name of the API function.
     *
     * @var string
     */
    public static $route = 'submissions';

    /**
     * Returns the parameters for the execute function.
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return static::base_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'Assignment id'),
        ]);
    }

    /**
     * Executes the function and returns an array of submissions.
     *
     * @param int $assignmentid
     * @return array|stdClass
     */
    public static function execute($assignmentid) {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $params = static::validate_parameters(static::execute_parameters(), [
            'assignmentid' => $assignmentid,
        ]);
        [$course, $cm] = get_course_and_cm_from_cmid($params['assignmentid'], 'assign', 0);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        $assign = new assign($context, $cm, $course);

        $submissions = [];
        $users = $assign->list_participants_with_filter_status_and_group(0);
        foreach ($users as $user) {
            $submission = $assign->get_user_submission($user->id, false);
            $grades = $DB->get_record('assign_grades', ['assignment' => $assign->get_instance()->id, 'userid' => $user->id]);
            if (!$submission || static::skip_record($submission->userid)) {
                continue;
            }
            if (
                $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['assignment' =>
                    $assign->get_instance()->id, 'submission' => $submission->id])
            ) {
                $intro = file_rewrite_pluginfile_urls(
                    $onlinetext->onlinetext,
                    'pluginfile.php',
                    $context->id,
                    'mod_assign',
                    'submissions_onlinetext',
                    null
                );
                $submission->body = content_to_text($intro, (int) FORMAT_MARKDOWN);
            }
            if (!empty($grades)) {
                if ($grades->grade > 0) {
                    $workflowstate = get_string('onlygraded', 'local_learnwise');
                } else if ($grades->grader < 0 && $grades->grade < 0 && $submission->status != 'submitted') {
                    $workflowstate = get_string('unsubmitted', 'local_learnwise');
                } else {
                    $workflowstate = get_string('onlysubmitted', 'local_learnwise');
                }
            } else {
                if ($submission->status == 'submitted') {
                    $workflowstate = get_string('onlysubmitted', 'local_learnwise');
                } else {
                    $workflowstate = get_string('unsubmitted', 'local_learnwise');
                }
            }
            $submission->user_id = $submission->userid;
            $submission->workflow_state = $workflowstate;
            if (static::is_singleoperation()) {
                if (has_capability('mod/assign:grade', $context)) {
                    self::get_info($assign, $submission);
                }
                return $submission;
            }
            $submissions[] = $submission;
        }
        return $submissions;
    }

    /**
     * Returns the structure of the response for the execute function.
     *
     * @param assign $assign Assign object
     * @param stdClass $submission User submission record
     * @return void
     */
    public static function get_info(assign $assign, stdClass $submission) {
        global $DB, $USER;
        $filesubmission = $assign->get_submission_plugin_by_type('file');
        if ($filesubmission) {
            foreach ($filesubmission->get_files($submission, $USER) as $storedfile) {
                $record = $DB->get_record('assignsubmission_file', ['submission' => $submission->id]);
                $file = ['id' => !empty($record) ? $record->id : 0];
                $file['url'] = self::file_url_from_stored_file($storedfile)->out(false);
                $file['title'] = $storedfile->get_filename();
                $file['content_type'] = $storedfile->get_mimetype();
                $submission->attachments[] = $file;
            }
        }
        $grade = $assign->get_user_grade($submission->userid, false);
        if ($grade) {
            $commentfeedback = $assign->get_feedback_plugin_by_type('comments');
            if ($commentfeedback) {
                $record = $commentfeedback->get_feedback_comments($grade->id);
                if ($record) {
                    $grader = core_user::get_user($grade->grader);
                    $submissioncomment = ['id' => $record->id];
                    $submissioncomment['author_id'] = !empty($grader) ? $grader->id : 0;
                    $submissioncomment['author_name'] = !empty($grader) ? fullname($grader) : '';
                    $submissioncomment['created_at'] = $grade->timemodified;
                    $commentintro = file_rewrite_pluginfile_urls(
                        $record->commenttext,
                        'pluginfile.php',
                        $assign->get_context()->id,
                        ASSIGNFEEDBACK_COMMENTS_COMPONENT,
                        ASSIGNFEEDBACK_COMMENTS_FILEAREA,
                        $grade->id
                    );
                    $submissioncomment['comment'] = content_to_text($commentintro, (int) FORMAT_MARKDOWN);
                    $submission->submission_comments[] = $submissioncomment;
                }
            }
            $editpdf = $assign->get_feedback_plugin_by_type('editpdf');
            if (!empty($submission->attachments) && $editpdf) {
                if (page_editor::has_annotations_or_comments($grade->id, false)) {
                    $fs = get_file_storage();
                    $files = $fs->get_area_files(
                        $assign->get_context()->id,
                        'assignfeedback_editpdf',
                        document_services::FINAL_PDF_FILEAREA,
                        $grade->id
                    );
                    foreach ($files as $file) {
                        if ($file->get_filesize() > 0) {
                            $submission->attachments[0]['annotated_pdf_url'] = self::file_url_from_stored_file($file)->out(false);
                        }
                    }
                }
            }
            $gradingmanager = get_grading_manager($assign->get_context(), 'mod_assign', 'submissions');
            $gradingmethod = $gradingmanager->get_active_method();
            $gradingdisabled = $assign->grading_disabled($submission->userid);
            if ($gradingmethod === 'rubric' && !$gradingdisabled) {
                $controller = $gradingmanager->get_controller($gradingmethod);
                // phpcs:ignore moodle.Commenting.InlineComment.TypeHintingMatch
                /** @var \gradingform_rubric_controller $controller */
                if ($controller->is_form_available()) {
                    $gradinginstance = $controller->get_or_create_instance(0, $USER->id, $grade->id);
                    // phpcs:ignore moodle.Commenting.InlineComment.TypeHintingMatch
                    /** @var \gradingform_rubric_instance $gradinginstance */
                    $definition = $controller->get_definition();
                    $rubricmatrix = $gradinginstance->get_rubric_filling();
                    if (!empty($rubricmatrix['criteria'])) {
                        foreach ($rubricmatrix['criteria'] as $criteriagrade) {
                            $rubricassessment = [
                                'rating_id' => $criteriagrade['levelid'],
                                'comments' => $criteriagrade['remark'],
                                'points' => 0,
                            ];
                            foreach ($definition->rubric_criteria as $crit) {
                                if ($crit['id'] == $criteriagrade['criterionid']) {
                                    foreach ($crit['levels'] as $level) {
                                        if ($level['id'] == $criteriagrade['levelid']) {
                                            $rubricassessment['points'] = $level['score'];
                                        }
                                    }
                                }
                            }
                            $submission->rubric_assessment[] = $rubricassessment;
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns the structure of the response for the execute function.
     *
     * @return external_single_structure
     */
    public static function single_structure() {
        $singlestructure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'body' => new external_value(PARAM_RAW, 'body', VALUE_OPTIONAL),
            'workflow_state' => new external_value(PARAM_ALPHA, 'status'),
            'user_id' => new external_value(PARAM_INT, 'userid'),
        ]);
        if (static::is_singleoperation()) {
            $singlestructure->keys['attachments'] = new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'file submission id'),
                    'url' => new external_value(PARAM_URL, 'file url'),
                    'title' => new external_value(PARAM_FILE, 'file name'),
                    'content_type' => new external_value(PARAM_RAW, 'file content type'),
                    'annotated_pdf_url' => new external_value(PARAM_URL, 'file content type', VALUE_DEFAULT),
                ]),
                'attachments',
                VALUE_OPTIONAL
            );
            $singlestructure->keys['submission_comments'] = new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'comment id'),
                    'author_id' => new external_value(PARAM_INT, 'commenter id'),
                    'author_name' => new external_value(PARAM_TEXT, 'commenter name'),
                    'created_at' => timestampvalue::make(new external_value(PARAM_INT, 'created at')),
                    'comment' => new external_value(PARAM_RAW, 'comment'),
                ]),
                'comments',
                VALUE_OPTIONAL
            );
            $singlestructure->keys['rubric_assessment'] = new external_multiple_structure(
                new external_single_structure([
                    'rating_id' => new external_value(PARAM_INT, 'rating id'),
                    'comments' => new external_value(PARAM_RAW, 'comments'),
                    'points' => new external_value(PARAM_FLOAT, 'points'),
                ]),
                'comments',
                VALUE_OPTIONAL
            );
            $singlestructure->required = (bool) VALUE_DEFAULT;
        }
        return $singlestructure;
    }
}
