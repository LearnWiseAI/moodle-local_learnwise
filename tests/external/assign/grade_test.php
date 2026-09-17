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

use advanced_testcase;
use assign;
use cm_info;
use comment;
use context_module;
use dml_missing_record_exception;
use invalid_parameter_exception;
use local_learnwise\external\baseapi;
use moodle_exception;
use required_capability_exception;
use stdClass;

/**
 * Tests for the assignment grading endpoint.
 *
 * These focus on the access checks added alongside the grading call: the assignment must
 * belong to the stated course, the target user must be a participant, and the caller must
 * be allowed to view that user's submission.
 *
 * The plugin's external classes load the deprecated lib/externallib.php at file scope,
 * which Moodle only permits inside an isolated process.
 *
 * @runTestsInSeparateProcesses
 * @covers     \local_learnwise\external\assign\grade
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_test extends advanced_testcase {
    /** @var stdClass */
    protected $course;

    /** @var stdClass */
    protected $assign;

    /** @var stdClass */
    protected $teacher;

    /** @var stdClass */
    protected $student;

    /**
     * Build a course with one assignment, a teacher and an enrolled student.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        parent::setUp();
        $this->resetAfterTest();

        grade::$ids = [];

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->assign = $generator->create_module('assign', ['course' => $this->course->id, 'grade' => 100,
            'assignfeedback_comments_enabled' => 1]);

        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->student = $generator->create_and_enrol($this->course, 'student');

        $prop = new \ReflectionProperty($generator, 'generators');
        $prop->setAccessible(true);
        $componentgenerators = $prop->getValue($generator);
        $currentcomponentdir = \core_component::get_component_directory(\local_learnwise\constants::COMPONENT);
        foreach (['core_grading', 'gradingform_guide', 'gradingform_rubric'] as $component) {
            $componentdir = \core_component::get_component_directory($component);
            if (!file_exists("{$componentdir}/tests/generator/lib.php")) {
                require_once("{$currentcomponentdir}/tests/generator/{$component}/lib.php");
                $generatorclassname = "{$component}_generator";
                $componentgenerators[$component] = new $generatorclassname($generator);
            }
        }
        $prop->setValue($generator, $componentgenerators);
    }

    /**
     * Reset the static state shared by every API class.
     */
    protected function tearDown(): void {
        baseapi::$my = null;
        baseapi::$ids = [];
        parent::tearDown();
    }

    /**
     * Build a minimal valid rubric assessment payload.
     *
     * @param float $submissiongrade The grade to award
     * @return array
     */
    protected function assessment(float $submissiongrade): array {
        return [
            'submission_grade' => $submissiongrade,
            'general_feedback' => 'Well done.',
        ];
    }

    /**
     * A teacher can grade an enrolled student on an assignment in the stated course.
     */
    public function test_teacher_can_grade_enrolled_student(): void {
        global $DB;
        $this->setUser($this->teacher);

        $result = grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $this->student->id,
            $this->assessment(80.0)
        );

        $this->assertTrue($result['success']);

        $grade = $DB->get_record('assign_grades', [
            'assignment' => $this->assign->id,
            'userid' => $this->student->id,
        ]);
        $this->assertEquals(80.0, (float) $grade->grade);
    }

    /**
     * A valid zero grade is saved and reported as successful.
     */
    public function test_zero_grade_reports_success(): void {
        $this->setUser($this->teacher);

        $result = grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $this->student->id,
            $this->assessment(0.0)
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('error', $result);
    }

    /**
     * Grading an assignment while naming a different course is rejected.
     *
     * Without this check the course_id parameter is unvalidated, so a caller authorised on
     * one course could pass that course's id while grading an assignment in another.
     */
    public function test_course_id_must_match_the_assignment(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $this->setUser($this->teacher);

        $this->expectException(invalid_parameter_exception::class);
        $this->expectExceptionMessage('The assignment does not belong to the specified course.');

        grade::execute(
            $othercourse->id,
            $this->assign->cmid,
            $this->student->id,
            $this->assessment(50.0)
        );
    }

    /**
     * The course mismatch is caught before any grade record is written.
     */
    public function test_course_mismatch_writes_no_grade(): void {
        global $DB;
        $othercourse = $this->getDataGenerator()->create_course();
        $this->setUser($this->teacher);

        try {
            grade::execute($othercourse->id, $this->assign->cmid, $this->student->id, $this->assessment(50.0));
            $this->fail('Expected invalid_parameter_exception');
        } catch (invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records('assign_grades', ['assignment' => $this->assign->id]));
        }
    }

    /**
     * A user who is not enrolled in the course cannot be graded.
     */
    public function test_unenrolled_user_cannot_be_graded(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($this->teacher);

        $this->expectException(invalid_parameter_exception::class);
        $this->expectExceptionMessage('The user is not a participant in this assignment.');

        grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $outsider->id,
            $this->assessment(50.0)
        );
    }

    /**
     * A user enrolled on a different course cannot be graded on this assignment.
     */
    public function test_user_from_another_course_cannot_be_graded(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $otherstudent = $this->getDataGenerator()->create_and_enrol($othercourse, 'student');
        $this->setUser($this->teacher);

        $this->expectException(invalid_parameter_exception::class);
        $this->expectExceptionMessage('The user is not a participant in this assignment.');

        grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $otherstudent->id,
            $this->assessment(50.0)
        );
    }

    /**
     * A teacher may not grade another teacher, who is not an assignment participant.
     */
    public function test_non_submitting_role_is_not_a_participant(): void {
        $otherteacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->teacher);

        $this->expectException(invalid_parameter_exception::class);
        $this->expectExceptionMessage('The user is not a participant in this assignment.');

        grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $otherteacher->id,
            $this->assessment(50.0)
        );
    }

    /**
     * A student cannot grade anyone, including themselves.
     */
    public function test_student_cannot_grade(): void {
        $this->setUser($this->student);

        $this->expectException(required_capability_exception::class);

        grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $this->student->id,
            $this->assessment(100.0)
        );
    }

    /**
     * A user with no role in the course at all cannot grade.
     */
    public function test_outsider_cannot_grade(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $this->expectException(moodle_exception::class);

        grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $this->student->id,
            $this->assessment(50.0)
        );
    }

    /**
     * A suspended enrolment makes the user a non-participant, so grading is refused.
     *
     * This is the case require_view_submission() and get_participant() agree on: both
     * consult show_only_active_users(), which excludes suspended users from a grader
     * lacking moodle/course:viewsuspendedusers.
     */
    public function test_suspended_student_cannot_be_graded_without_capability(): void {
        global $DB;

        // Suspend the student's enrolment.
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('manual');
        $plugin->update_user_enrol($instance, $this->student->id, ENROL_USER_SUSPENDED);

        // Remove the capability that would let the teacher see suspended users.
        $context = context_module::instance($this->assign->cmid);
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        assign_capability('moodle/course:viewsuspendedusers', CAP_PREVENT, $teacherrole->id, $context->id, true);

        $this->setUser($this->teacher);

        $this->expectException(invalid_parameter_exception::class);
        $this->expectExceptionMessage('The user is not a participant in this assignment.');

        grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $this->student->id,
            $this->assessment(50.0)
        );
    }

    /**
     * Separate groups do NOT restrict grading through this endpoint.
     *
     * assign::can_view_submission() short-circuits to true for anyone holding
     * mod/assign:grade, and execute() has already required that capability, so
     * require_view_submission() cannot reject a same-course grader here. A teacher
     * confined to one group can still grade a student in another.
     *
     * This test documents the behaviour as it stands rather than endorsing it; if group
     * separation should be enforced, the endpoint needs its own groups_* check and this
     * test should be inverted.
     */
    public function test_separate_groups_do_not_restrict_grading(): void {
        global $DB;

        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->assign->cmid]);
        rebuild_course_cache($this->course->id, true);

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $this->teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $this->student->id]);

        // Make sure the teacher cannot simply see all groups.
        $context = context_module::instance($this->assign->cmid);
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        assign_capability('moodle/site:accessallgroups', CAP_PREVENT, $teacherrole->id, $context->id, true);

        $this->setUser($this->teacher);

        $result = grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $this->student->id,
            $this->assessment(70.0)
        );

        $this->assertTrue($result['success'], 'Group separation is currently not enforced by this endpoint');
    }

    /**
     * A non-existent course module id is rejected rather than silently ignored.
     */
    public function test_unknown_assignment_is_rejected(): void {
        $this->setUser($this->teacher);

        $this->expectException(dml_missing_record_exception::class);

        grade::execute($this->course->id, -1, $this->student->id, $this->assessment(50.0));
    }

    /**
     * The declared return structure matches what execute() actually returns.
     */
    public function test_execute_returns_matches_payload(): void {
        $this->setUser($this->teacher);

        $result = grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $this->student->id,
            $this->assessment(80.0)
        );

        $cleaned = grade::clean_returnvalue(grade::single_structure(), $result);
        $this->assertTrue($cleaned['success']);
    }

    /**
     * Parameters are validated: a non-numeric user id is refused.
     */
    public function test_parameters_are_validated(): void {
        $this->setUser($this->teacher);

        $this->expectException(invalid_parameter_exception::class);

        grade::execute($this->course->id, $this->assign->cmid, 'not-an-id', $this->assessment(50.0));
    }

    /**
     * The endpoint declares itself as a write operation.
     */
    public function test_endpoint_is_declared_as_write(): void {
        $this->assertSame('write', grade::crudtype());
        $this->assertSame('local_learnwise_assign_grade', grade::function_name());
    }

    /**
     * Grading is a write operation.
     */
    public function test_it_is_a_write_operation(): void {
        $this->assertSame('write', grade::crudtype());
        $this->assertSame('local_learnwise_assign_grade', grade::function_name());
    }

    /**
     * The API is driven by the course, the assignment, the user and the marks.
     */
    public function test_execute_parameters(): void {
        $params = grade::execute_parameters();

        $this->assertSame(
            ['course_id', 'assignment_id', 'user_id', 'rubric_assessment', 'advancedgradinginstanceid'],
            array_keys($params->keys)
        );
        $this->assertSame(VALUE_DEFAULT, $params->keys['advancedgradinginstanceid']->required);
    }

    /**
     * Both rubric and marking guide feedback can be submitted, and both are optional.
     */
    public function test_execute_parameters_accept_rubric_and_guide_feedback(): void {
        $assessment = grade::execute_parameters()->keys['rubric_assessment'];

        $this->assertArrayHasKey('submission_grade', $assessment->keys);
        $this->assertSame(VALUE_OPTIONAL, $assessment->keys['general_feedback']->required);
        $assessments = $assessment->keys['rubric_assessments'];
        $this->assertSame(VALUE_OPTIONAL, $assessments->required);
        $this->assertSame(VALUE_OPTIONAL, $assessments->keys['rubric_feedback_array']->required);
        $this->assertSame(VALUE_OPTIONAL, $assessments->keys['guide_feedback_array']->required);
    }

    /**
     * The API always answers with one verdict rather than a list.
     */
    public function test_it_is_always_a_single_operation(): void {
        $this->assertTrue(grade::is_singleoperation());
        $this->assertSame(['success', 'error'], array_keys(grade::single_structure()->keys));
        $this->assertSame(VALUE_OPTIONAL, grade::single_structure()->keys['error']->required);
    }

    /**
     * The assignment is resolved into everything the grading code needs.
     */
    public function test_validate_assignment(): void {
        [$course, $cm, $assign] = $this->create_assignment();
        $this->setAdminUser();

        [$assignment, $foundcourse, $foundcm, $context] = grade::validate_assignment($assign->id);

        $this->assertInstanceOf(assign::class, $assignment);
        $this->assertSame((int) $course->id, (int) $foundcourse->id);
        $this->assertSame((int) $cm->id, (int) $foundcm->id);
        $this->assertSame((int) context_module::instance($cm->id)->id, (int) $context->id);
    }

    /**
     * A simple numeric grade is saved against the student.
     */
    public function test_execute_saves_a_simple_grade(): void {
        [$course, $cm, $assign, $teacher, $student] = $this->create_assignment();
        $this->setUser($teacher);

        $response = grade::execute($course->id, $cm->id, $student->id, [
            'submission_grade' => 75.0,
            'general_feedback' => 'Good work',
        ], null);

        $this->assertTrue($response['success']);
        $assignment = new assign(context_module::instance($cm->id), $cm, $course);
        $this->assertEquals(75.0, $assignment->get_user_grade($student->id, false)->grade);
    }

    /**
     * The grader is recorded as the user who called the API.
     */
    public function test_execute_records_the_grader(): void {
        [$course, $cm, $assign, $teacher, $student] = $this->create_assignment();
        $this->setUser($teacher);

        grade::execute($course->id, $cm->id, $student->id, [
            'submission_grade' => 75.0,
            'general_feedback' => 'Good work',
        ], null);

        $assignment = new assign(context_module::instance($cm->id), $cm, $course);
        $this->assertSame((int) $teacher->id, (int) $assignment->get_user_grade($student->id, false)->grader);
    }

    /**
     * General feedback is stored alongside the grade.
     */
    public function test_execute_saves_the_general_feedback(): void {
        global $DB;

        [$course, $cm, $assign, $teacher, $student] = $this->create_assignment();
        $this->setUser($teacher);

        grade::execute($course->id, $cm->id, $student->id, [
            'submission_grade' => 75.0,
            'general_feedback' => 'Nicely argued',
        ], null);

        $assignment = new assign(context_module::instance($cm->id), $cm, $course);
        $usergrade = $assignment->get_user_grade($student->id, false);
        $comment = $DB->get_record('assignfeedback_comments', ['grade' => $usergrade->id]);
        $this->assertNotFalse($comment);
        $this->assertContains('Nicely argued', $comment->commenttext);
    }

    /**
     * A grade of zero is saved successfully.
     */
    public function test_execute_reports_a_zero_grade_as_success(): void {
        [$course, $cm, $assign, $teacher, $student] = $this->create_assignment();
        $this->setUser($teacher);

        $response = grade::execute($course->id, $cm->id, $student->id, [
            'submission_grade' => 0.0,
            'general_feedback' => '',
        ], null);

        $this->assertTrue($response['success']);
        global $DB;
        $this->assertEquals(0, $DB->get_field('assign_grades', 'grade', [
            'assignment' => $assign->id, 'userid' => $student->id,
        ]));
    }

    /**
     * Naming the wrong course for the assignment is rejected.
     */
    public function test_execute_rejects_a_mismatched_course(): void {
        [, $cm, , $teacher, $student] = $this->create_assignment();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->setUser($teacher);

        $this->expectException(invalid_parameter_exception::class);
        grade::execute($othercourse->id, $cm->id, $student->id, [
            'submission_grade' => 75.0,
            'general_feedback' => '',
        ], null);
    }

    /**
     * Grading somebody who is not on the assignment is rejected.
     */
    public function test_execute_rejects_a_non_participant(): void {
        [$course, $cm, , $teacher] = $this->create_assignment();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);

        $this->expectException(invalid_parameter_exception::class);
        grade::execute($course->id, $cm->id, $outsider->id, [
            'submission_grade' => 75.0,
            'general_feedback' => '',
        ], null);
    }

    /**
     * A student cannot grade.
     */
    public function test_execute_requires_the_grade_capability(): void {
        [$course, $cm, , , $student] = $this->create_assignment();
        $this->setUser($student);

        $this->expectException(required_capability_exception::class);
        grade::execute($course->id, $cm->id, $student->id, [
            'submission_grade' => 75.0,
            'general_feedback' => '',
        ], null);
    }

    /**
     * An unknown assignment is rejected.
     */
    public function test_execute_rejects_an_unknown_assignment(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->expectException(dml_missing_record_exception::class);
        grade::execute($course->id, -1, 1, [
            'submission_grade' => 75.0,
            'general_feedback' => '',
        ], null);
    }

    /**
     * Gradebook and workflow restrictions reject both grade and feedback edits.
     *
     * @dataProvider disabled_grading_provider
     * @param string $restriction The restriction to apply.
     */
    public function test_disabled_grading_preserves_grade_and_feedback(string $restriction): void {
        global $DB;
        $this->setUser($this->teacher);
        grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $this->assessment(60));
        $context = context_module::instance($this->assign->cmid);
        if ($restriction === 'workflow') {
            $DB->set_field('assign', 'markingworkflow', 1, ['id' => $this->assign->id]);
            $assignment = new assign($context, get_coursemodule_from_id('assign', $this->assign->cmid), $this->course);
            $flags = $assignment->get_user_flags($this->student->id, true);
            $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW;
            $assignment->update_user_flags($flags);
            $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
            foreach (['reviewgrades', 'managegrades', 'releasegrades'] as $capability) {
                assign_capability('mod/assign:' . $capability, CAP_PREVENT, $roleid, $context->id, true);
            }
            accesslib_clear_all_caches_for_unit_testing();
        } else {
            $item = $DB->get_record('grade_items', ['itemmodule' => 'assign', 'iteminstance' => $this->assign->id]);
            if ($restriction === 'itemlocked') {
                $DB->set_field('grade_items', 'locked', time(), ['id' => $item->id]);
            } else {
                $DB->set_field('grade_grades', $restriction, time(), ['itemid' => $item->id, 'userid' => $this->student->id]);
            }
        }
        $beforegrade = $DB->get_record('assign_grades', ['assignment' => $this->assign->id, 'userid' => $this->student->id]);
        $beforefeedback = $DB->get_records('assignfeedback_comments', ['grade' => $beforegrade->id]);
        $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, [
            'submission_grade' => 90, 'general_feedback' => 'Must not be saved',
        ]);
        $this->assertFalse($result['success']);
        $this->assertEquals($beforegrade, $DB->get_record('assign_grades', ['id' => $beforegrade->id]));
        $this->assertEquals($beforefeedback, $DB->get_records('assignfeedback_comments', ['grade' => $beforegrade->id]));
    }

    /**
     * Restrictions that must prevent all grading writes.
     *
     * @return array
     */
    public static function disabled_grading_provider(): array {
        return [['locked'], ['overridden'], ['itemlocked'], ['workflow']];
    }

    /**
     * A locked assignment cannot create a new grade as a side effect of a rejected request.
     */
    public function test_locked_assignment_does_not_create_a_grade(): void {
        global $DB;
        $DB->set_field('grade_items', 'locked', time(), ['itemmodule' => 'assign', 'iteminstance' => $this->assign->id]);
        $this->setUser($this->teacher);
        $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $this->assessment(80));
        $this->assertFalse($result['success']);
        $this->assertFalse($DB->record_exists('assign_grades', ['assignment' => $this->assign->id]));
    }

    /**
     * A rejected numeric mark does not create a grade or save feedback.
     */
    public function test_out_of_range_grade_saves_nothing(): void {
        global $DB;
        $this->setUser($this->teacher);
        foreach ([101, -2] as $mark) {
            $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $this->assessment($mark));
            $this->assertFalse($result['success']);
            $this->assertFalse($DB->record_exists('assign_grades', ['assignment' => $this->assign->id]));
            $this->assertSame(0, $DB->count_records('assignfeedback_comments'));
        }
    }

    /**
     * Feedback without an awarded mark is a valid successful operation.
     */
    public function test_feedback_only_reports_success(): void {
        global $DB;
        $this->setUser($this->teacher);
        $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $this->assessment(-1));
        $this->assertTrue($result['success']);
        $grade = $DB->get_record('assign_grades', ['assignment' => $this->assign->id, 'userid' => $this->student->id]);
        $this->assertEquals(-1, $grade->grade);
        $this->assertSame('Well done.', $DB->get_field('assignfeedback_comments', 'commenttext', ['grade' => $grade->id]));
    }

    /**
     * Omitting optional feedback preserves the existing comment while updating the mark.
     */
    public function test_grade_without_feedback_preserves_comments(): void {
        global $DB;
        $this->setUser($this->teacher);
        grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $this->assessment(60));
        $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, ['submission_grade' => 70]);
        $this->assertTrue($result['success']);
        $grade = $DB->get_record('assign_grades', ['assignment' => $this->assign->id, 'userid' => $this->student->id]);
        $this->assertEquals(70, $grade->grade);
        $this->assertSame('Well done.', $DB->get_field('assignfeedback_comments', 'commenttext', ['grade' => $grade->id]));
    }

    /**
     * Rubric and guide grading still persist their criteria and comments.
     *
     * @dataProvider advanced_grading_provider
     * @param string $method The grading method.
     */
    public function test_advanced_grading_saves_criteria(string $method): void {
        global $DB;
        $this->setUser($this->teacher);
        $context = context_module::instance($this->assign->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_' . $method);
        $criteria = $method === 'rubric' ? ['Quality' => ['Poor' => 0, 'Good' => 10]] : [
            'Quality' => ['description' => 'Quality', 'descriptionmarkers' => 'Quality', 'maxscore' => 10],
        ];
        $controller = $generator->create_instance($context, 'mod_assign', 'submissions', 'Assessment', '', $criteria);
        $definition = $controller->get_definition();
        $payload = $this->assessment(80);
        if ($method === 'rubric') {
            $criterion = reset($definition->rubric_criteria);
            $level = end($criterion['levels']);
            $payload['rubric_assessments']['rubric_feedback_array'] = [[
                'rubric_section_id' => $criterion['id'], 'graded_lms_rubric_rating_id' => $level['id'], 'content' => 'Good',
            ]];
        } else {
            $criterion = reset($definition->guide_criteria);
            $payload['rubric_assessments']['guide_feedback_array'] = [[
                'rubric_section_id' => $criterion['id'], 'graded_score' => 10, 'content' => 'Good',
            ]];
        }
        $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $payload);
        $this->assertTrue($result['success']);
        $grade = $DB->get_record('assign_grades', ['assignment' => $this->assign->id, 'userid' => $this->student->id]);
        $this->assertEquals(100, $grade->grade);
        $instance = $DB->get_record('grading_instances', ['itemid' => $grade->id, 'definitionid' => $definition->id]);
        $this->assertTrue($DB->record_exists('gradingform_' . $method . '_fillings', ['instanceid' => $instance->id]));
        $beforefillings = $DB->get_records('gradingform_' . $method . '_fillings', ['instanceid' => $instance->id]);
        $beforefeedback = $DB->get_records('assignfeedback_comments', ['grade' => $grade->id]);
        $DB->set_field('grade_items', 'locked', time(), ['itemmodule' => 'assign', 'iteminstance' => $this->assign->id]);
        $payload['general_feedback'] = 'Must not be saved';
        $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $payload);
        $this->assertFalse($result['success']);
        $this->assertEquals($grade, $DB->get_record('assign_grades', ['id' => $grade->id]));
        $this->assertEquals(
            $beforefillings,
            $DB->get_records('gradingform_' . $method . '_fillings', ['instanceid' => $instance->id])
        );
        $this->assertEquals($beforefeedback, $DB->get_records('assignfeedback_comments', ['grade' => $grade->id]));
    }

    /**
     * Core rejection rolls back feedback and newly created advanced grading instances.
     */
    public function test_core_rejected_grade_rolls_back_feedback(): void {
        global $DB;
        $this->setUser($this->teacher);
        grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $this->assessment(80));
        // A lower maximum leaves an existing mark outside the range accepted by core.
        $DB->set_field('assign', 'grade', 50, ['id' => $this->assign->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_guide');
        $generator->create_instance(
            context_module::instance($this->assign->cmid),
            'mod_assign',
            'submissions',
            'Assessment',
            '',
            ['Quality' => ['description' => 'Quality', 'descriptionmarkers' => 'Quality', 'maxscore' => 10]]
        );
        $beforegrade = $DB->get_record('assign_grades', ['assignment' => $this->assign->id, 'userid' => $this->student->id]);
        $beforefeedback = $DB->get_records('assignfeedback_comments', ['grade' => $beforegrade->id]);
        $beforeinstances = $DB->count_records('grading_instances');
        $this->preventResetByRollback();
        $response = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, [
            'submission_grade' => -1, 'general_feedback' => 'Must be rolled back',
        ]);
        $this->assertFalse($response['success']);
        $this->assertSame(get_string('gradingfailed', 'local_learnwise'), $response['error']);
        $this->assertEquals($beforegrade, $DB->get_record('assign_grades', ['id' => $beforegrade->id]));
        $this->assertEquals($beforefeedback, $DB->get_records('assignfeedback_comments', ['grade' => $beforegrade->id]));
        $this->assertSame($beforeinstances, $DB->count_records('grading_instances'));
    }

    /**
     * Invalid guide criteria cannot create a grade, feedback or a grading instance.
     */
    public function test_invalid_advanced_criteria_roll_back_new_records(): void {
        global $DB;
        $this->setUser($this->teacher);
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_guide');
        $controller = $generator->create_instance(
            context_module::instance($this->assign->cmid),
            'mod_assign',
            'submissions',
            'Assessment',
            '',
            ['Quality' => ['description' => 'Quality', 'descriptionmarkers' => 'Quality', 'maxscore' => 10]]
        );
        $definition = $controller->get_definition();
        $criterion = reset($definition->guide_criteria);
        $payload = $this->assessment(80);
        $payload['rubric_assessments']['guide_feedback_array'] = [[
            'rubric_section_id' => $criterion['id'], 'graded_score' => 11, 'content' => 'Invalid mark',
        ]];
        $this->preventResetByRollback();
        $response = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $payload);
        $this->assertFalse($response['success']);
        $this->assertSame(get_string('gradingfailed', 'local_learnwise'), $response['error']);
        $this->assertFalse($DB->record_exists('assign_grades', ['assignment' => $this->assign->id]));
        $this->assertSame(0, $DB->count_records('assignfeedback_comments'));
        $this->assertSame(0, $DB->count_records('grading_instances'));
        $this->assertSame(0, $DB->count_records('gradingform_guide_fillings'));
    }

    /**
     * A permitted workflow state saves the mark without claiming that an unreleased grade failed.
     */
    public function test_permitted_workflow_state_reports_success(): void {
        global $DB;
        $DB->set_field('assign', 'markingworkflow', 1, ['id' => $this->assign->id]);
        $this->setUser($this->teacher);
        $assignment = new assign(
            context_module::instance($this->assign->cmid),
            get_coursemodule_from_id('assign', $this->assign->cmid),
            $this->course
        );
        $flags = $assignment->get_user_flags($this->student->id, true);
        $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_INMARKING;
        $assignment->update_user_flags($flags);
        $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $this->assessment(80));
        $this->assertTrue($result['success']);
        $this->assertEquals(80, $DB->get_field('assign_grades', 'grade', [
            'assignment' => $this->assign->id, 'userid' => $this->student->id,
        ]));
    }

    /**
     * Valid scale selections still save, while invalid indices leave the grade untouched.
     */
    public function test_scale_grades_validate_the_selected_option(): void {
        global $DB;
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Needs work,Meets expectations']);
        $DB->set_field('assign', 'grade', -$scale->id, ['id' => $this->assign->id]);
        $this->setUser($this->teacher);
        foreach ([1, 2] as $mark) {
            $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $this->assessment($mark));
            $this->assertTrue($result['success']);
        }
        $before = $DB->get_record('assign_grades', ['assignment' => $this->assign->id, 'userid' => $this->student->id]);
        $this->assertEquals(2, $before->grade);
        foreach ([0, 3, 1.5] as $mark) {
            $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $this->assessment($mark));
            $this->assertFalse($result['success']);
            $this->assertEquals($before, $DB->get_record('assign_grades', ['id' => $before->id]));
        }
    }

    /**
     * An unavailable advanced grading form cannot report a successful grade save.
     */
    public function test_unavailable_advanced_form_saves_nothing(): void {
        global $DB;
        $this->setUser($this->teacher);
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_guide');
        $controller = $generator->create_instance(
            context_module::instance($this->assign->cmid),
            'mod_assign',
            'submissions',
            'Assessment',
            '',
            ['Quality' => ['description' => 'Quality', 'descriptionmarkers' => 'Quality', 'maxscore' => 10]]
        );
        $DB->set_field(
            'grading_definitions',
            'status',
            \gradingform_controller::DEFINITION_STATUS_DRAFT,
            ['id' => $controller->get_definition()->id]
        );
        $result = grade::execute($this->course->id, $this->assign->cmid, $this->student->id, $this->assessment(80));
        $this->assertFalse($result['success']);
        $this->assertFalse($DB->record_exists('assign_grades', ['assignment' => $this->assign->id]));
    }

    /**
     * Advanced grading methods supported by Moodle and the plugin.
     *
     * @return array
     */
    public static function advanced_grading_provider(): array {
        return [['rubric'], ['guide']];
    }

    /**
     * Build a course with an assignment, a teacher and a student.
     *
     * @return array
     */
    protected function create_assignment() {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'grade' => 100,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $cm = get_coursemodule_from_id('assign', $assign->cmid, 0, false, MUST_EXIST);
        return [$course, $cm, $assign, $teacher, $student];
    }

    /**
     * Test that comment tracking works with feedback plugin enabled.
     */
    public function test_comment_uses_feedback_plugin_when_enabled(): void {
        global $DB;

        [$course, $cm, $assign, $teacher, $student] = $this->create_assignment();
        $this->setUser($teacher);
        $result = grade::execute(
            $course->id,
            $assign->cmid,
            $student->id,
            [
                'submission_grade' => 80.0,
                'general_feedback' => 'Via feedback plugin',
            ]
        );

        $this->assertTrue($result['success']);

        // Verify comment was saved via feedback plugin, not tracking table.
        $assigncontext = context_module::instance($cm->id);
        $grade = new assign($assigncontext, $cm, $course);
        $usergrade = $grade->get_user_grade($student->id, false);

        if ($usergrade) {
            $feedbackcomment = $DB->get_record('assignfeedback_comments', ['grade' => $usergrade->id]);
            $this->assertNotNull($feedbackcomment);
            $this->assertStringContainsString('Via feedback plugin', $feedbackcomment->commenttext);
        }
    }

    /**
     * Test that a comment is created and tracked when feedback comments are disabled.
     */
    public function test_comment_created_and_tracked_when_plugin_disabled(): void {
        [$comment, $track, $result, , $assign] = $this->create_comment_with_tracking();
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($comment, 'No comments found in database');
        $this->assertStringContainsString('Great submission!', $comment->content);
        $this->assertNotNull($track, 'Comment not tracked in local_learnwise_comnt_tracks');
        $this->assertEquals($comment->id, $track->commentid);
        $assigncontext = context_module::instance($assign->cmid);
        $this->assertEquals($assigncontext->id, $comment->contextid);
        $this->assertEquals($comment->commentarea, 'submission_comments');
        $assignobject = new assign($assigncontext, null, null);
        $submission = $assignobject->get_user_submission($this->student->id, false);
        $this->assertIsObject($submission);
        $this->assertEquals($submission->id, $comment->itemid);
    }

    /**
     * Test that an existing tracked comment is updated on subsequent grade submission.
     */
    public function test_existing_tracked_comment_is_updated(): void {
        global $DB;
        [$comment1, $track, $result1, $course, $assign] = $this->create_comment_with_tracking();
        $this->assertTrue($result1['success']);
        $this->assertNotEmpty($comment1);
        $comment1id = $comment1->id;
        $originaltime = $comment1->timecreated;

        // Wait a moment to ensure time difference.
        sleep(1);

        // Second submission with updated comment.
        $result2 = grade::execute(
            $course->id,
            $assign->cmid,
            $this->student->id,
            [
                'submission_grade' => 85.0,
                'general_feedback' => 'Updated feedback',
            ]
        );
        $this->assertTrue($result2['success']);

        $countcomments = $DB->count_records('comments', [
            'userid' => $this->teacher->id,
            'component' => 'assignsubmission_comments',
        ]);
        $this->assertSame(1, $countcomments, 'Multiple comments created instead of updating existing');
        $updatedcomment = $DB->get_record('comments', ['id' => $comment1id]);
        $this->assertNotNull($updatedcomment);
        $this->assertStringContainsString('Updated feedback', $updatedcomment->content);
        $this->assertEquals($originaltime, $updatedcomment->timecreated);

        // Verify track record was updated.
        $track = $DB->get_record('local_learnwise_comnt_tracks', [
            'commentid' => $comment1id,
        ]);
        $this->assertNotNull($track);
        $this->assertGreaterThanOrEqual($originaltime, $track->timeupdated);
    }

    /**
     * Test that comment deletion removes tracking record.
     */
    public function test_comment_deletion_removes_track_record(): void {
        global $DB;

        [$comment, $track, , $course, $assign] = $this->create_comment_with_tracking();

        // Verify both exist before deletion.
        $this->assertNotNull($comment);
        $this->assertNotNull($track);
        $commentid = $comment->id;
        $trackid = $track->id;

        $cm = get_fast_modinfo($course)->get_cm($assign->cmid);

        $comment = $DB->get_record('comments', ['id' => $commentid]);
        $this->assertNotEmpty($comment, 'Comment not exist');

        // Delete the comment.
        $args = new stdClass();
        $args->course = $course;
        $args->cm = $cm;
        $args->itemid = $comment->itemid;
        $args->contextid = $comment->contextid;
        $args->area = $comment->commentarea;
        $args->component = $comment->component;

        $manager = new comment($args);
        $manager->delete($commentid);

        // Verify comment is deleted.
        $deletedcomment = $DB->get_record('comments', ['id' => $commentid]);
        $this->assertFalse($deletedcomment);

        // Verify track record is also deleted by observer.
        $deletedtrack = $DB->get_record('local_learnwise_comnt_tracks', ['id' => $trackid]);
        $this->assertFalse($deletedtrack, 'Track record was not deleted when comment was deleted');
    }

    /**
     * Test that observer gracefully handles deletion of non-existent comment ID.
     */
    public function test_observer_handles_non_existent_comment_id(): void {
        global $DB;

        // Create a valid event with non-existent comment ID.
        $fakecommentid = 999999;

        $event = \assignsubmission_comments\event\comment_deleted::create([
            'objectid' => $fakecommentid,
            'context' => context_module::instance($this->assign->cmid),
            'other' => [
                'itemid' => $fakecommentid,
            ],
        ]);

        // Should not throw exception.
        try {
            $event->trigger();
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->fail("Observer failed on non-existent comment ID: " . $e->getMessage());
        }

        // Verify database is unchanged.
        $this->assertFalse($DB->record_exists('local_learnwise_comnt_tracks', [
            'commentid' => $fakecommentid,
        ]));
    }

    /**
     * Create a comment and return both comment and track records.
     *
     * @return array [comment, track, $result, $course, $assign]
     */
    protected function create_comment_with_tracking(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'grade' => 100,
            'assignfeedback_comments_enabled' => 0,
        ]);
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->teacher->id, $course->id, 'editingteacher');
        $this->setUser($this->teacher);

        $result = grade::execute(
            $course->id,
            $assign->cmid,
            $this->student->id,
            [
                'submission_grade' => 80.0,
                'general_feedback' => 'Great submission!',
            ]
        );

        $assigncontext = context_module::instance($assign->cmid);
        $assignment = new assign($assigncontext, null, null);
        $submission = $assignment->get_user_submission($this->student->id, true);
        $comment = $DB->get_record('comments', [
            'userid' => $this->teacher->id,
            'contextid' => context_module::instance($assign->cmid)->id,
            'commentarea' => 'submission_comments',
            'itemid' => $submission->id,
        ]);

        $track = $DB->get_record('local_learnwise_comnt_tracks', [
            'commentid' => $comment->id,
        ]);

        return [$comment, $track, $result, $course, $assign];
    }

    /**
     * Test that a comment is created and tracked for the same user on a second assignment in the same course.
     */
    public function test_comment_created_and_tracked_for_same_user_on_second_assignment(): void {
        global $DB;
        // Create the first assignment and comment.
        [$firstcomment, $firsttrack, $firstresult, $firstcourse, ] =
            $this->create_comment_with_tracking();

        $this->assertTrue($firstresult['success']);
        $this->assertNotEmpty($firstcomment, 'No comment found for the first assignment');
        $this->assertNotNull(
            $firsttrack,
            'First comment not tracked in local_learnwise_comnt_tracks'
        );

        // Create a second assignment in the same course for the same user.
        $secondassign = $this->getDataGenerator()->create_module('assign', [
            'course' => $firstcourse->id,
            'grade' => 100,
            'assignfeedback_comments_enabled' => 0,
        ]);

        $secondresult = grade::execute(
            $firstcourse->id,
            $secondassign->cmid,
            $this->student->id,
            [
                'submission_grade' => 70.0,
                'general_feedback' => 'second assignment!',
            ]
        );

        $assignmentcontext = context_module::instance($secondassign->cmid);
        $submissionassignment = new assign($assignmentcontext, null, null);
        $secondsubmission = $submissionassignment->get_user_submission($this->student->id, true);

        $secondcomment = $DB->get_record('comments', [
            'userid' => $this->teacher->id,
            'contextid' => $assignmentcontext->id,
            'commentarea' => 'submission_comments',
            'itemid' => $secondsubmission->id,
        ]);

        $this->assertIsObject($secondcomment, 'No comment found for the second assignment');

        $secondtrack = $DB->get_record('local_learnwise_comnt_tracks', [
            'commentid' => $secondcomment->id,
        ]);

        $this->assertTrue($secondresult['success']);
        $this->assertNotEmpty($secondcomment, 'No comment found for the second assignment');
        $this->assertStringContainsString('second assignment!', $secondcomment->content);

        // Verify that the second comment was tracked.
        $this->assertIsObject($secondtrack);
        $this->assertEquals($secondcomment->id, $secondtrack->commentid);

        // Verify that the second comment belongs to the second assignment context.
        $this->assertEquals(
            $assignmentcontext->id,
            $secondcomment->contextid
        );
        $this->assertEquals(
            'submission_comments',
            $secondcomment->commentarea
        );

        $this->assertIsObject($secondsubmission);
        $this->assertEquals(
            $secondsubmission->id,
            $secondcomment->itemid
        );

        // Ensure the second comment is distinct from the first comment.
        $this->assertNotEquals(
            $firstcomment->id,
            $secondcomment->id
        );

        // Ensure the second comment has its own tracking record.
        $this->assertNotEquals(
            $firsttrack->id,
            $secondtrack->id
        );

        // Confirm both comments belong to the same user.
        $this->assertEquals(
            $firstcomment->userid,
            $secondcomment->userid
        );

        // Confirm the comments belong to different assignments.
        $this->assertNotEquals(
            $firstcomment->contextid,
            $secondcomment->contextid
        );
    }
}
