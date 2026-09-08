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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

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
final class get_status_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $assign;

    /** @var \stdClass */
    protected $teacher;

    /** @var \stdClass */
    protected $student;

    /**
     * Build a course with an assignment, a teacher and a student who has submitted.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->assign = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->student = $generator->create_and_enrol($this->course, 'student');
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

        $this->expectException(\required_capability_exception::class);

        get_status::execute($this->assign->id, $this->student->id);
    }

    /**
     * A user outside the course cannot read submission status.
     */
    public function test_outsider_cannot_read_status(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $this->expectException(\moodle_exception::class);

        get_status::execute($this->assign->id, $this->student->id);
    }

    /**
     * A user who is not enrolled cannot have their status read, even by a grader.
     */
    public function test_unenrolled_target_user_is_refused(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($this->teacher);

        $this->expectException(\required_capability_exception::class);

        get_status::execute($this->assign->id, $outsider->id);
    }

    /**
     * A deleted target user is refused rather than returning a partial record.
     */
    public function test_deleted_target_user_is_refused(): void {
        $this->setUser($this->teacher);
        delete_user($this->student);

        $this->expectException(\moodle_exception::class);

        get_status::execute($this->assign->id, $this->student->id);
    }

    /**
     * An unknown assignment id is refused.
     */
    public function test_unknown_assignment_is_refused(): void {
        $this->setUser($this->teacher);

        $this->expectException(\dml_missing_record_exception::class);

        get_status::execute(-1, $this->student->id);
    }

    /**
     * The endpoint always reports itself as a single operation.
     */
    public function test_is_always_a_single_operation(): void {
        $this->assertTrue(get_status::is_singleoperation());
    }
}
