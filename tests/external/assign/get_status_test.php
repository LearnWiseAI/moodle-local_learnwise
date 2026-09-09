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
use context_module;
use dml_missing_record_exception;
use external_single_structure;
use local_learnwise\external\baseapi;
use moodle_exception;
use required_capability_exception;
use stdClass;

/**
 * Tests for the assignment submission status endpoint.
 *
 * Unlike the other assignment endpoints this one takes the assign instance id rather than a
 * course module id, and it guards access with both mod/assign:grade and can_view_submission().
 *
 * The plugin's external classes load the deprecated lib/externallib.php at file scope,
 * which Moodle only permits inside an isolated process.
 *
 * @runTestsInSeparateProcesses
 * @covers     \local_learnwise\external\assign\get_status
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_status_test extends advanced_testcase {
    /** @var stdClass */
    protected $course;

    /** @var stdClass */
    protected $assign;

    /** @var stdClass */
    protected $teacher;

    /** @var stdClass */
    protected $student;

    /**
     * Build a course with an assignment, a teacher and a student who has submitted.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->assign = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->student = $generator->create_and_enrol($this->course, 'student');
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
     * Insert a submitted submission for the student.
     */
    protected function create_submission(): void {
        global $DB;

        $DB->insert_record('assign_submission', (object) [
            'assignment' => $this->assign->id,
            'userid' => $this->student->id,
            'timecreated' => time(),
            'timemodified' => time(),
            'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            'groupid' => 0,
            'attemptnumber' => 0,
            'latest' => 1,
        ]);
    }

    /**
     * A grader gets the student's submission record.
     */
    public function test_teacher_can_read_submission_status(): void {
        $this->create_submission();
        $this->setUser($this->teacher);

        $result = get_status::execute($this->assign->id, $this->student->id);

        $this->assertArrayHasKey('submission', $result);
        $this->assertEquals($this->student->id, $result['submission']->userid);
    }

    /**
     * With no submission the response simply carries no submission key.
     */
    public function test_no_submission_yields_empty_response(): void {
        $this->setUser($this->teacher);

        $this->assertSame([], get_status::execute($this->assign->id, $this->student->id));
    }

    /**
     * A student cannot read submission status through this endpoint.
     */
    public function test_student_cannot_read_status(): void {
        $this->create_submission();
        $this->setUser($this->student);

        $this->expectException(required_capability_exception::class);

        get_status::execute($this->assign->id, $this->student->id);
    }

    /**
     * A user outside the course cannot read submission status.
     */
    public function test_outsider_cannot_read_status(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $this->expectException(moodle_exception::class);

        get_status::execute($this->assign->id, $this->student->id);
    }

    /**
     * A user who is not enrolled cannot have their status read, even by a grader.
     */
    public function test_unenrolled_target_user_is_refused(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($this->teacher);

        $this->expectException(required_capability_exception::class);

        get_status::execute($this->assign->id, $outsider->id);
    }

    /**
     * A deleted target user is refused rather than returning a partial record.
     */
    public function test_deleted_target_user_is_refused(): void {
        $this->setUser($this->teacher);
        delete_user($this->student);

        $this->expectException(moodle_exception::class);

        get_status::execute($this->assign->id, $this->student->id);
    }

    /**
     * An unknown assignment id is refused.
     */
    public function test_unknown_assignment_is_refused(): void {
        $this->setUser($this->teacher);

        $this->expectException(dml_missing_record_exception::class);

        get_status::execute(-1, $this->student->id);
    }

    /**
     * The endpoint always reports itself as a single operation.
     */
    public function test_is_always_a_single_operation(): void {
        $this->assertTrue(get_status::is_singleoperation());
    }

    /**
     * The API is driven by an assignment instance id and a user id.
     */
    public function test_execute_parameters(): void {
        $params = get_status::execute_parameters();

        $this->assertSame(['assignid', 'userid'], array_keys($params->keys));
        $this->assertSame(PARAM_INT, $params->keys['assignid']->type);
        $this->assertSame(PARAM_INT, $params->keys['userid']->type);
    }

    /**
     * The API always answers with one status rather than a list.
     */
    public function test_it_is_always_a_single_operation(): void {
        $this->assertTrue(get_status::is_singleoperation());
        $this->assertInstanceOf(external_single_structure::class, get_status::execute_returns());
    }

    /**
     * Every field of the borrowed structure is optional, since the API reports a subset.
     */
    public function test_single_structure_is_entirely_optional(): void {
        $structure = get_status::single_structure();

        $this->assertArrayHasKey('submission', $structure->keys);
        foreach ($structure->keys as $key => $value) {
            $this->assertSame(VALUE_OPTIONAL, $value->required, "Key {$key} should be optional");
        }
    }

    /**
     * A student who has not started work yet has no submission to report.
     */
    public function test_execute_without_a_submission(): void {
        [$assign, $teacher, $student] = $this->create_assignment();
        $this->setUser($teacher);

        $this->assertSame([], get_status::execute($assign->id, $student->id));
    }

    /**
     * A started submission is reported back to the grader.
     */
    public function test_execute_reports_a_submission(): void {
        [$assign, $teacher, $student, $course, $cm] = $this->create_assignment();
        $this->create_user_submission($course, $cm, $student);
        $this->setUser($teacher);

        $response = get_status::execute($assign->id, $student->id);

        $this->assertSame((int) $student->id, (int) $response['submission']->userid);
        $this->assertSame(ASSIGN_SUBMISSION_STATUS_SUBMITTED, $response['submission']->status);
    }

    /**
     * Only a grader may look at somebody else's submission.
     */
    public function test_execute_requires_the_grade_capability(): void {
        [$assign, , $student] = $this->create_assignment();
        $this->setUser($student);

        $this->expectException(required_capability_exception::class);
        get_status::execute($assign->id, $student->id);
    }

    /**
     * An unknown assignment is rejected.
     */
    public function test_execute_rejects_an_unknown_assignment(): void {
        $this->setAdminUser();

        $this->expectException(dml_missing_record_exception::class);
        get_status::execute(-1, 1);
    }

    /**
     * An unknown user is rejected.
     */
    public function test_execute_rejects_an_unknown_user(): void {
        [$assign, $teacher] = $this->create_assignment();
        $this->setUser($teacher);

        $this->expectException(dml_missing_record_exception::class);
        get_status::execute($assign->id, -1);
    }

    /**
     * Build a course with an assignment, a teacher and a student.
     *
     * @return array
     */
    protected function create_assignment() {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $cm = get_coursemodule_from_id('assign', $assign->cmid, 0, false, MUST_EXIST);
        return [$assign, $teacher, $student, $course, $cm];
    }

    /**
     * Record a submitted submission for the given student.
     *
     * @param stdClass $course Course the assignment belongs to
     * @param stdClass $cm Assignment course module
     * @param stdClass $student Submitting student
     * @return stdClass
     */
    protected function create_user_submission($course, $cm, $student) {
        global $DB;

        $assignment = new assign(context_module::instance($cm->id), $cm, $course);
        $submission = $assignment->get_user_submission($student->id, true);
        $submission->status = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
        $DB->update_record('assign_submission', $submission);
        return $submission;
    }
}
