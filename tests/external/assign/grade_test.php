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

use invalid_parameter_exception;
use required_capability_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

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
final class grade_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $assign;

    /** @var \stdClass */
    protected $teacher;

    /** @var \stdClass */
    protected $student;

    /**
     * Build a course with one assignment, a teacher and an enrolled student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        grade::$ids = [];

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->assign = $generator->create_module('assign', ['course' => $this->course->id, 'grade' => 100]);

        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->student = $generator->create_and_enrol($this->course, 'student');
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
        $this->assertEqualsWithDelta(80.0, (float) $grade->grade, 0.001);
    }

    /**
     * A zero grade is reported as an unsuccessful grading rather than a silent success.
     */
    public function test_zero_grade_reports_failure(): void {
        $this->setUser($this->teacher);

        $result = grade::execute(
            $this->course->id,
            $this->assign->cmid,
            $this->student->id,
            $this->assessment(0.0)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
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

        $this->expectException(\moodle_exception::class);

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
        $context = \context_module::instance($this->assign->cmid);
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
        $context = \context_module::instance($this->assign->cmid);
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

        $this->expectException(\dml_missing_record_exception::class);

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
}
