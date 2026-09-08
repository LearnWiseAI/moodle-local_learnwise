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
 * Tests for the assignment submissions endpoint.
 *
 * The endpoint validates course access but requires no grading capability of its own, so the
 * per-user can_view_submission() filter is what stops one participant reading another's
 * submission. These tests pin that down.
 *
 * The plugin's external classes load the deprecated lib/externallib.php at file scope,
 * which Moodle only permits inside an isolated process.
 *
 * @runTestsInSeparateProcesses
 * @covers     \local_learnwise\external\assign\submissions
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class submissions_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $assign;

    /** @var \stdClass */
    protected $teacher;

    /** @var \stdClass */
    protected $studenta;

    /** @var \stdClass */
    protected $studentb;

    /**
     * Build a course with an assignment and two students who have both submitted.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        submissions::$ids = [];

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->assign = $generator->create_module('assign', [
            'course' => $this->course->id,
            'grade' => 100,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->studenta = $generator->create_and_enrol($this->course, 'student');
        $this->studentb = $generator->create_and_enrol($this->course, 'student');

        $this->create_submission($this->studenta, 'Answer from student A');
        $this->create_submission($this->studentb, 'Answer from student B');
    }

    /**
     * Insert a submitted online-text submission for a user.
     *
     * @param \stdClass $user The submitting user
     * @param string $text The online text body
     * @return int The submission id
     */
    protected function create_submission(\stdClass $user, string $text): int {
        global $DB;

        $submission = (object) [
            'assignment' => $this->assign->id,
            'userid' => $user->id,
            'timecreated' => time(),
            'timemodified' => time(),
            'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            'groupid' => 0,
            'attemptnumber' => 0,
            'latest' => 1,
        ];
        $submission->id = $DB->insert_record('assign_submission', $submission);

        $DB->insert_record('assignsubmission_onlinetext', (object) [
            'assignment' => $this->assign->id,
            'submission' => $submission->id,
            'onlinetext' => $text,
            'onlineformat' => FORMAT_HTML,
        ]);

        return $submission->id;
    }

    /**
     * A teacher sees every participant's submission.
     */
    public function test_teacher_sees_all_submissions(): void {
        $this->setUser($this->teacher);

        $result = submissions::execute($this->assign->cmid);

        $userids = array_column($result, 'user_id');
        sort($userids);
        $expected = [$this->studenta->id, $this->studentb->id];
        sort($expected);
        $this->assertSame($expected, $userids);
    }

    /**
     * A student sees only their own submission, not their classmates'.
     *
     * The endpoint requires no grading capability, so without the can_view_submission()
     * filter any enrolled student could read every submission in the assignment.
     */
    public function test_student_sees_only_their_own_submission(): void {
        $this->setUser($this->studenta);

        $result = submissions::execute($this->assign->cmid);

        $this->assertCount(1, $result, 'A student must not see other participants submissions');
        $this->assertEquals($this->studenta->id, $result[0]->user_id);
    }

    /**
     * The other student's submission body never reaches a peer.
     */
    public function test_student_does_not_receive_peer_submission_text(): void {
        $this->setUser($this->studenta);

        $result = submissions::execute($this->assign->cmid);
        $bodies = array_map(function ($submission) {
            return $submission->body ?? '';
        }, $result);

        $this->assertStringNotContainsString('Answer from student B', implode(' ', $bodies));
    }

    /**
     * Submission bodies are returned to a grader as plain text.
     */
    public function test_teacher_receives_submission_body(): void {
        $this->setUser($this->teacher);

        $result = submissions::execute($this->assign->cmid);
        $bodies = array_map(function ($submission) {
            return $submission->body ?? '';
        }, $result);
        $joined = implode(' ', $bodies);

        $this->assertStringContainsString('Answer from student A', $joined);
        $this->assertStringContainsString('Answer from student B', $joined);
    }

    /**
     * A submitted-but-ungraded submission reports the submitted workflow state.
     */
    public function test_workflow_state_for_submitted_ungraded(): void {
        $this->setUser($this->teacher);

        $result = submissions::execute($this->assign->cmid);

        foreach ($result as $submission) {
            $this->assertSame(get_string('onlysubmitted', 'local_learnwise'), $submission->workflow_state);
        }
    }

    /**
     * A graded submission reports the graded workflow state.
     */
    public function test_workflow_state_for_graded(): void {
        global $DB;

        $DB->insert_record('assign_grades', (object) [
            'assignment' => $this->assign->id,
            'userid' => $this->studenta->id,
            'timecreated' => time(),
            'timemodified' => time(),
            'grader' => $this->teacher->id,
            'grade' => 75.0,
            'attemptnumber' => 0,
        ]);

        $this->setUser($this->teacher);
        $result = submissions::execute($this->assign->cmid);

        $bystudent = [];
        foreach ($result as $submission) {
            $bystudent[$submission->user_id] = $submission;
        }

        $this->assertSame(
            get_string('onlygraded', 'local_learnwise'),
            $bystudent[$this->studenta->id]->workflow_state
        );
    }

    /**
     * Single-operation mode narrows the response to one submission object.
     */
    public function test_single_operation_returns_one_submission(): void {
        $this->setUser($this->teacher);
        submissions::set_id($this->studentb->id);

        $result = submissions::execute($this->assign->cmid);

        $this->assertIsObject($result);
        $this->assertEquals($this->studentb->id, $result->user_id);
    }

    /**
     * A student cannot use single-operation mode to fetch a peer's submission.
     */
    public function test_single_operation_still_respects_visibility(): void {
        $this->setUser($this->studenta);
        submissions::set_id($this->studentb->id);

        $result = submissions::execute($this->assign->cmid);

        $this->assertSame([], $result, 'Requesting a peer by id must not bypass the visibility filter');
    }

    /**
     * A user with no access to the course cannot list submissions at all.
     */
    public function test_outsider_cannot_list_submissions(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $this->expectException(\require_login_exception::class);

        submissions::execute($this->assign->cmid);
    }

    /**
     * Participants without a submission are omitted from the response.
     */
    public function test_participants_without_submissions_are_skipped(): void {
        $newstudent = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($this->teacher);

        $result = submissions::execute($this->assign->cmid);

        $this->assertNotContains($newstudent->id, array_column($result, 'user_id'));
    }

    /**
     * An unknown course module id is rejected.
     */
    public function test_unknown_cmid_is_rejected(): void {
        $this->setUser($this->teacher);

        $this->expectException(\moodle_exception::class);

        submissions::execute(-1);
    }

    /**
     * The endpoint is a read operation with the expected external name.
     */
    public function test_endpoint_metadata(): void {
        $this->assertSame('read', submissions::crudtype());
        $this->assertSame('local_learnwise_assign_submissions', submissions::function_name());
    }
}
