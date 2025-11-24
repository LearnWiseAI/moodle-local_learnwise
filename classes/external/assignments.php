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

use assign;
use context_course;
use context_module;
use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * Class getassignments
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignments extends baseapi {
    /**
     * Summary of route
     * @var string
     */
    public static $route = 'assignments';

    /**
     * Summary of execute_parameters
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return static::base_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * Summary of execute
     * @param mixed $courseid
     * @return array
     */
    public static function execute($courseid) {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $params = static::validate_parameters(static::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = context_course::instance($params['courseid']);
        if (static::is_singleoperation()) {
            $context = context_module::instance(static::get_id());
        }
        static::validate_context($context);
        if (static::is_singleoperation()) {
            require_capability('mod/assign:view', $context);
        }

        $course = get_course($params['courseid']);
        $cms = get_coursemodules_in_course('assign', $course->id, 'm.duedate');
        if (!$cms) {
            return [];
        }

        $usesections = course_format_uses_sections($course->format);
        $modinfo = get_fast_modinfo($course);

        if ($usesections) {
            $sections = $modinfo->get_section_info_all();
        }

        $assignmentinfos = [];
        foreach ($modinfo->instances['assign'] as $cm) {
            if (static::skip_record($cm->id)) {
                continue;
            }
            $context = context_module::instance($cm->id);
            if (!has_capability('mod/assign:view', $context)) {
                continue;
            }
            $timedue = $cms[$cm->id]->duedate;

            $sectionname = null;
            if ($usesections && $cm->sectionnum) {
                $sectionname = get_section_name($course, $sections[$cm->sectionnum]);
            }

            $context = context_module::instance($cm->id);
            $assignment = new assign($context, $cm, $course);

            // Apply overrides.
            $assignment->update_effective_access($USER->id);
            $timedue = $assignment->get_instance()->duedate;
            $opendate = $assignment->get_instance()->allowsubmissionsfromdate;
            $closedate = $assignment->get_instance()->cutoffdate;

            $submitted = false;
            if (has_capability('mod/assign:grade', $context)) {
                $submitted = $assignment->count_submissions_with_status(ASSIGN_SUBMISSION_STATUS_SUBMITTED) > 0;
            } else if (has_capability('mod/assign:submit', $context)) {
                if ($assignment->get_instance()->teamsubmission) {
                    $usersubmission = $assignment->get_group_submission($USER->id, 0, false);
                } else {
                    $usersubmission = $assignment->get_user_submission($USER->id, false);
                }

                if (!empty($usersubmission->status)) {
                    $submitted = true;
                } else {
                    $submitted = false;
                }
            }

            $assignmentinfo = [
                'id' => $cm->id,
                'name' => $cm->get_formatted_name(),
                'sectionname' => $sectionname,
                'timedue' => $timedue > 0 ? $timedue : null,
                'opendate' => $opendate > 0 ? $opendate : null,
                'closedate' => $closedate > 0 ? $closedate : null,
                'course_id' => $cm->course,
            ];

            if ($assignment->show_intro()) {
                $activity = $assignment->get_instance();
                $intro = file_rewrite_pluginfile_urls(
                    $activity->intro,
                    'pluginfile.php',
                    $context->id,
                    'mod_assign',
                    'intro',
                    null
                );
                $assignmentinfo['description'] = content_to_text($intro, FORMAT_MARKDOWN);
            }

            if (!empty(baseapi::$my)) {
                $assignmentinfo['submitted'] = $submitted ? get_string('submitted', 'local_learnwise') : null;
            } else if (has_capability('mod/assign:grade', $context) && static::is_singleoperation()) {
                $gradingmanager = get_grading_manager($assignment->get_context(), 'mod_assign', 'submissions');
                $gradingmethod = $gradingmanager->get_active_method();
                if ($gradingmethod === 'rubric') {
                    $controller = $gradingmanager->get_controller($gradingmethod);
                    // phpcs:ignore moodle.Commenting.InlineComment.TypeHintingMatch
                    /** @var \gradingform_rubric_controller $controller */
                    if ($controller->is_form_available()) {
                        $definition = $controller->get_definition();
                        $possiblepoints = 0;
                        foreach ($definition->rubric_criteria as $rubriccriteria) {
                            $rubric = [
                                'id' => $rubriccriteria['id'],
                                'points' => 0,
                                'description' => $rubriccriteria['description'],
                                'ratings' => [],
                            ];
                            foreach ($rubriccriteria['levels'] as $level) {
                                $rubric['ratings'][] = [
                                    'id' => $level['id'],
                                    'points' => $level['score'],
                                    'description' => $level['definition'],
                                ];
                            }
                            $rubric['points'] = max(array_column($rubric['ratings'], 'points'));
                            $possiblepoints += $rubric['points'];
                            $assignmentinfo['rubric'][] = $rubric;
                        }
                        $assignmentinfo['rubric_settings'] = [
                            'id' => $definition->id,
                            'title' => $definition->name,
                            'points_possible' => $possiblepoints,
                        ];
                    }
                } else if (is_null($gradingmethod)) {
                    $assignmentinfo['rubric_settings'] = [
                        'id' => 0,
                        'title' => get_string('gradingmethodnone', 'core_grading'),
                        'points_possible' => $assignment->get_instance()->grade,
                    ];
                }
            }

            if (static::is_singleoperation()) {
                return $assignmentinfo;
            }

            $assignmentinfos[] = $assignmentinfo;
        }
        return $assignmentinfos;
    }

    /**
     * Summary of execute_returns
     * @return external_single_structure
     */
    public static function single_structure() {
        $structure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'course module id of assignment'),
            'name' => new external_value(PARAM_TEXT, 'name of assignment'),
            'description' => new external_value(PARAM_TEXT, 'Description of assignment'),
            'sectionname' => new external_value(PARAM_TEXT, 'name of section that assignment belongs to'),
            'timedue' => new external_value(PARAM_INT, 'due date in unix timestamp if applied'),
            'opendate' => new external_value(PARAM_INT, 'open date in unix timestamp if applied'),
            'closedate' => new external_value(PARAM_INT, 'close date in unix timestamp if applied'),
            'course_id' => new external_value(PARAM_INT, 'course id'),
        ]);
        if (!empty(baseapi::$my)) {
            $structure->keys['submitted'] = new external_value(PARAM_TEXT, 'submitted assignment or not');
        }
        if (static::is_singleoperation()) {
            $structure->keys['rubric'] = new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'points' => new external_value(PARAM_FLOAT, 'points'),
                'description' => new external_value(PARAM_RAW, 'description'),
                'ratings' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'id'),
                    'points' => new external_value(PARAM_FLOAT, 'points'),
                    'description' => new external_value(PARAM_TEXT, 'description'),
                ])),
            ]), 'rubric', VALUE_OPTIONAL);
            $structure->keys['rubric_settings'] = new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'title' => new external_value(PARAM_TEXT, 'name'),
                'points_possible' => new external_value(PARAM_FLOAT, 'points'),
            ]);
        }
        return $structure;
    }

    /**
     * Summary of get_unixtimestamp_fields
     * @return array
     */
    public static function get_unixtimestamp_fields() {
        return ['timedue', 'opendate', 'closedate'];
    }
}
