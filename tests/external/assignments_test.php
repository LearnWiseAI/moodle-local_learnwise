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

use advanced_testcase;
use context_module;
use moodle_exception;

/**
 * Tests for the assignments API.
 *
 * @covers     \local_learnwise\external\assignments
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class assignments_test extends advanced_testcase {
    /**
     * Reset the static state shared by every API class.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        parent::setUp();
        $this->resetAfterTest();
        baseapi::$my = null;
        baseapi::$ids = [];
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
     * The API is driven by a course id.
     */
    public function test_execute_parameters_take_a_course_id(): void {
        $params = assignments::execute_parameters();

        $this->assertArrayHasKey('courseid', $params->keys);
        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
    }

    /**
     * The three assignment dates are reported as ISO 8601.
     */
    public function test_dates_are_declared_as_timestamps(): void {
        $this->assertSame(['timedue', 'opendate', 'closedate'], assignments::get_unixtimestamp_fields());

        $structure = assignments::execute_returns()->content;

        $this->assertInstanceOf(timestampvalue::class, $structure->keys['timedue']);
        $this->assertInstanceOf(timestampvalue::class, $structure->keys['opendate']);
        $this->assertInstanceOf(timestampvalue::class, $structure->keys['closedate']);
    }

    /**
     * A course without assignments yields an empty list rather than an error.
     */
    public function test_execute_on_a_course_without_assignments(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->assertSame([], assignments::execute($course->id));
    }

    /**
     * Each assignment is reported with its course module id, name, section and dates.
     */
    public function test_execute_describes_an_assignment(): void {
        $duedate = time() + DAYSECS;
        $opendate = time() - DAYSECS;
        $cutoff = time() + (2 * DAYSECS);
        $course = $this->getDataGenerator()->create_course(['numsections' => 1, 'format' => 'topics']);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Essay',
            'section' => 1,
            'duedate' => $duedate,
            'allowsubmissionsfromdate' => $opendate,
            'cutoffdate' => $cutoff,
        ]);
        $this->setAdminUser();

        $response = assignments::execute($course->id);

        $this->assertCount(1, $response);
        $this->assertSame((int) $assign->cmid, (int) $response[0]['id']);
        $this->assertSame('Essay', $response[0]['name']);
        $this->assertSame((int) $course->id, (int) $response[0]['course_id']);
        $this->assertSame($duedate, (int) $response[0]['timedue']);
        $this->assertSame($opendate, (int) $response[0]['opendate']);
        $this->assertSame($cutoff, (int) $response[0]['closedate']);
        $this->assertSame(get_section_name($course, 1), $response[0]['sectionname']);
    }

    /**
     * Dates that were never set are reported as null, not as zero.
     */
    public function test_execute_reports_unset_dates_as_null(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'duedate' => 0,
            'allowsubmissionsfromdate' => 0,
            'cutoffdate' => 0,
        ]);
        $this->setAdminUser();

        $response = assignments::execute($course->id);

        $this->assertNull($response[0]['timedue']);
        $this->assertNull($response[0]['opendate']);
        $this->assertNull($response[0]['closedate']);
    }

    /**
     * An assignment in the general section is reported without a section name.
     */
    public function test_execute_reports_no_section_name_for_the_general_section(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'section' => 0]);
        $this->setAdminUser();

        $response = assignments::execute($course->id);

        $this->assertNull($response[0]['sectionname']);
    }

    /**
     * The description is flattened to text and its embedded files are listed separately.
     */
    public function test_execute_reports_the_description_as_text(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'intro' => '<p>Write <strong>500</strong> words.</p>',
            'introformat' => FORMAT_HTML,
            'alwaysshowdescription' => 1,
        ]);
        $this->setAdminUser();

        $response = assignments::execute($course->id);

        $this->assertStringContainsString('500', $response[0]['description']);
        $this->assertStringNotContainsString('<strong>', $response[0]['description']);
        $this->assertSame([], $response[0]['descriptionfiles']);
        $this->assertSame([], $response[0]['additionalfiles']);
    }

    /**
     * The student flavour reports whether the caller has submitted.
     */
    public function test_execute_reports_submission_state_for_my(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        baseapi::$my = true;

        $response = assignments::execute($course->id);

        $this->assertArrayHasKey('submitted', $response[0]);
        $this->assertNull($response[0]['submitted']);
    }

    /**
     * Pinning a course module id returns that single assignment rather than a list.
     */
    public function test_execute_returns_a_single_assignment_when_pinned(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'First']);
        $second = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Second']);
        $this->setAdminUser();
        assignments::set_id($second->cmid);

        $response = assignments::execute($course->id);

        $this->assertSame((int) $second->cmid, (int) $response['id']);
        $this->assertSame('Second', $response['name']);
    }

    /**
     * Without an advanced grading method the grader is told the maximum grade instead.
     */
    public function test_execute_reports_simple_grading_settings_to_a_grader(): void {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 80]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        assignments::set_id($assign->cmid);

        $response = assignments::execute($course->id);

        $this->assertSame(0, (int) $response['rubric_settings']['id']);
        $this->assertSame(get_string('gradingmethodnone', 'core_grading'), $response['rubric_settings']['title']);
        $this->assertEquals(80, $response['rubric_settings']['points_possible']);
    }

    /**
     * Grading details are not offered to a student.
     */
    public function test_execute_hides_grading_settings_from_a_student(): void {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        assignments::set_id($assign->cmid);

        $response = assignments::execute($course->id);

        $this->assertArrayNotHasKey('rubric_settings', $response);
        $this->assertArrayNotHasKey('rubric', $response);
    }

    /**
     * A user who may not view the assignment is refused rather than handed an empty response.
     */
    public function test_execute_refuses_a_single_assignment_the_user_cannot_view(): void {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        assign_capability(
            'mod/assign:view',
            CAP_PROHIBIT,
            $this->get_student_roleid(),
            context_module::instance($assign->cmid)->id,
            true
        );
        assignments::set_id($assign->cmid);

        $this->expectException(moodle_exception::class);
        assignments::execute($course->id);
    }

    /**
     * The structure exposes the grading fields only where they are populated.
     */
    public function test_single_structure_matches_the_flavour(): void {
        baseapi::$my = true;
        $mystructure = assignments::single_structure();

        $this->assertArrayHasKey('submitted', $mystructure->keys);
        $this->assertArrayNotHasKey('rubric', $mystructure->keys);

        baseapi::$my = null;
        assignments::set_id(1);
        $singlestructure = assignments::single_structure();

        $this->assertArrayNotHasKey('submitted', $singlestructure->keys);
        $this->assertArrayHasKey('rubric', $singlestructure->keys);
        $this->assertArrayHasKey('guide', $singlestructure->keys);
        $this->assertArrayHasKey('rubric_settings', $singlestructure->keys);
    }

    /**
     * Look up the student role id.
     *
     * @return int
     */
    protected function get_student_roleid() {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    }
}
