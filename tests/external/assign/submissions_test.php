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
use local_learnwise\external\baseapi;
use local_learnwise\external\timestampvalue;
use moodle_exception;
use require_login_exception;
use stdClass;

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
final class submissions_test extends advanced_testcase {
    /** @var stdClass */
    protected $course;

    /** @var stdClass */
    protected $assign;

    /** @var stdClass */
    protected $teacher;

    /** @var stdClass */
    protected $studenta;

    /** @var stdClass */
    protected $studentb;

    /**
     * Build a course with an assignment and two students who have both submitted.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

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
     * Reset the static state shared by every API class.
     */
    protected function tearDown(): void {
        baseapi::$my = null;
        baseapi::$ids = [];
        parent::tearDown();
    }

    /**
     * Insert a submitted online-text submission for a user.
     *
     * @param stdClass $user The submitting user
     * @param string $text The online text body
     * @return int The submission id
     */
    protected function create_submission(stdClass $user, string $text): int {
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

        $this->assertNotContains('Answer from student B', implode(' ', $bodies));
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

        $this->assertContains('Answer from student A', $joined);
        $this->assertContains('Answer from student B', $joined);
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

        $this->assertInternalType('object', $result);
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

        $this->expectException(require_login_exception::class);

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

        $this->expectException(moodle_exception::class);

        submissions::execute(-1);
    }

    /**
     * The endpoint is a read operation with the expected external name.
     */
    public function test_endpoint_metadata(): void {
        $this->assertSame('read', submissions::crudtype());
        $this->assertSame('local_learnwise_assign_submissions', submissions::function_name());
    }

    /**
     * The API is driven by the assignment's course module id.
     */
    public function test_execute_parameters_take_an_assignment_id(): void {
        $params = submissions::execute_parameters();

        $this->assertSame(['assignmentid'], array_keys($params->keys));
        $this->assertSame(PARAM_INT, $params->keys['assignmentid']->type);
    }

    /**
     * An assignment nobody has started yields an empty list rather than an error.
     */
    public function test_execute_without_submissions(): void {
        [$course, $cm, $teacher] = $this->create_assignment();
        $this->setUser($teacher);

        $this->assertSame([], submissions::execute($cm->id));
    }

    /**
     * A submitted submission is reported with the submitting user and its state.
     */
    public function test_execute_reports_a_submitted_submission(): void {
        [$course, $cm, $teacher, $student] = $this->create_assignment();
        $this->submit($course, $cm, $student, ASSIGN_SUBMISSION_STATUS_SUBMITTED);
        $this->setUser($teacher);

        $response = submissions::execute($cm->id);

        $this->assertCount(1, $response);
        $this->assertSame((int) $student->id, (int) $response[0]->user_id);
        $this->assertSame(get_string('onlysubmitted', 'local_learnwise'), $response[0]->workflow_state);
    }

    /**
     * A draft submission is reported as unsubmitted.
     */
    public function test_execute_reports_a_draft_as_unsubmitted(): void {
        [$course, $cm, $teacher, $student] = $this->create_assignment();
        $this->submit($course, $cm, $student, ASSIGN_SUBMISSION_STATUS_DRAFT);
        $this->setUser($teacher);

        $response = submissions::execute($cm->id);

        $this->assertSame(get_string('unsubmitted', 'local_learnwise'), $response[0]->workflow_state);
    }

    /**
     * A graded submission is reported as graded.
     */
    public function test_execute_reports_a_graded_submission(): void {
        [$course, $cm, $teacher, $student] = $this->create_assignment();
        $this->submit($course, $cm, $student, ASSIGN_SUBMISSION_STATUS_SUBMITTED);
        $this->setUser($teacher);
        grade::execute($course->id, $cm->id, $student->id, [
            'submission_grade' => 80.0,
            'general_feedback' => 'Well argued',
        ], null);

        $response = submissions::execute($cm->id);

        $this->assertSame(get_string('onlygraded', 'local_learnwise'), $response[0]->workflow_state);
    }

    /**
     * Online text submissions are flattened into a plain text body.
     */
    public function test_execute_reports_the_online_text_body(): void {
        [$course, $cm, $teacher, $student] = $this->create_assignment();
        $submission = $this->submit($course, $cm, $student, ASSIGN_SUBMISSION_STATUS_SUBMITTED);
        $this->add_online_text($cm, $submission, '<p>My submitted <strong>answer</strong> here</p>');
        $this->setUser($teacher);

        $response = submissions::execute($cm->id);

        $this->assertContains('My submitted', $response[0]->body);
        $this->assertContains('here', $response[0]->body);
        $this->assertNotContains('<strong>', $response[0]->body);
    }

    /**
     * Pinning a user id returns that student's submission rather than a list.
     */
    public function test_execute_returns_a_single_submission_when_pinned(): void {
        [$course, $cm, $teacher, $student] = $this->create_assignment();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');
        $this->submit($course, $cm, $student, ASSIGN_SUBMISSION_STATUS_SUBMITTED);
        $this->submit($course, $cm, $other, ASSIGN_SUBMISSION_STATUS_SUBMITTED);
        $this->setUser($teacher);
        submissions::set_id($other->id);

        $response = submissions::execute($cm->id);

        $this->assertSame((int) $other->id, (int) $response->user_id);
    }

    /**
     * The grader's feedback comes back with the pinned submission.
     */
    public function test_execute_reports_feedback_with_a_pinned_submission(): void {
        [$course, $cm, $teacher, $student] = $this->create_assignment();
        $this->submit($course, $cm, $student, ASSIGN_SUBMISSION_STATUS_SUBMITTED);
        $this->setUser($teacher);
        grade::execute($course->id, $cm->id, $student->id, [
            'submission_grade' => 80.0,
            'general_feedback' => 'Well argued',
        ], null);
        submissions::set_id($student->id);

        $response = submissions::execute($cm->id);

        $this->assertCount(1, $response->submission_comments);
        $this->assertContains('Well argued', $response->submission_comments[0]['comment']);
        $this->assertSame((int) $teacher->id, (int) $response->submission_comments[0]['author_id']);
        $this->assertSame(fullname($teacher), $response->submission_comments[0]['author_name']);
    }

    /**
     * The list flavour reports only the fields every submission has.
     */
    public function test_single_structure_matches_the_flavour(): void {
        $liststructure = submissions::single_structure();

        $this->assertSame(['id', 'body', 'workflow_state', 'user_id'], array_keys($liststructure->keys));

        submissions::set_id(1);
        $singlestructure = submissions::single_structure();

        $this->assertArrayHasKey('attachments', $singlestructure->keys);
        $this->assertArrayHasKey('submission_comments', $singlestructure->keys);
        $this->assertArrayHasKey('rubric_assessment', $singlestructure->keys);
        $this->assertArrayHasKey('guide_assessment', $singlestructure->keys);
    }

    /**
     * The time a comment was left is reported as ISO 8601.
     */
    public function test_comment_times_are_declared_as_timestamps(): void {
        submissions::set_id(1);

        $comments = submissions::single_structure()->keys['submission_comments'];

        $this->assertInstanceOf(timestampvalue::class, $comments->content->keys['created_at']);
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
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $cm = get_coursemodule_from_id('assign', $assign->cmid, 0, false, MUST_EXIST);
        return [$course, $cm, $teacher, $student, $assign];
    }

    /**
     * Record a submission in the given state.
     *
     * @param stdClass $course Course the assignment belongs to
     * @param stdClass $cm Assignment course module
     * @param stdClass $student Submitting student
     * @param string $status Submission status to record
     * @return stdClass
     */
    protected function submit($course, $cm, $student, $status) {
        global $DB;

        $assignment = new assign(context_module::instance($cm->id), $cm, $course);
        $submission = $assignment->get_user_submission($student->id, true);
        $submission->status = $status;
        $DB->update_record('assign_submission', $submission);
        return $submission;
    }

    /**
     * Attach an online text answer to a submission.
     *
     * @param stdClass $cm Assignment course module
     * @param stdClass $submission Submission to attach the text to
     * @param string $text Online text body
     * @return void
     */
    protected function add_online_text($cm, $submission, $text) {
        global $DB;

        $DB->insert_record('assignsubmission_onlinetext', (object) [
            'assignment' => $cm->instance,
            'submission' => $submission->id,
            'onlinetext' => $text,
            'onlineformat' => FORMAT_HTML,
        ]);
    }
}
