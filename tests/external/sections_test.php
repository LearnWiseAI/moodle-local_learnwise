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

/**
 * Tests for the course sections API.
 *
 * @covers     \local_learnwise\external\sections
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class sections_test extends advanced_testcase {
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
     * Hide one section of a course.
     *
     * @param stdClass $course Course the section belongs to
     * @param int $sectionnum Section number to hide
     * @return void
     */
    protected function hide_section($course, $sectionnum) {
        global $DB;

        $DB->set_field('course_sections', 'visible', 0, ['course' => $course->id, 'section' => $sectionnum]);
        rebuild_course_cache($course->id, true);
    }

    /**
     * The API is driven by a course id.
     */
    public function test_execute_parameters_take_a_course_id(): void {
        $params = sections::execute_parameters();

        $this->assertArrayHasKey('courseid', $params->keys);
        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
    }

    /**
     * Every section of the course is listed, general section included.
     */
    public function test_execute_lists_every_section(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 3, 'format' => 'topics']);
        $this->setAdminUser();

        $response = sections::execute($course->id);

        $this->assertCount(4, $response);
        $this->assertSame([0, 1, 2, 3], array_map('intval', array_column($response, 'section')));
    }

    /**
     * Each section carries its name, visibility and formatted summary.
     */
    public function test_execute_describes_a_section(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['numsections' => 1, 'format' => 'topics']);
        $DB->set_field(
            'course_sections',
            'name',
            'Week one',
            ['course' => $course->id, 'section' => 1]
        );
        $DB->set_field(
            'course_sections',
            'summary',
            '<p>Getting started</p>',
            ['course' => $course->id, 'section' => 1]
        );
        rebuild_course_cache($course->id, true);
        $this->setAdminUser();

        $response = sections::execute($course->id);
        $section = $response[1];

        $this->assertSame('Week one', $section['name']);
        $this->assertSame(1, (int) $section['visible']);
        $this->assertContains('Getting started', $section['summary']);
        $this->assertEquals(FORMAT_HTML, $section['summaryformat']);
        $this->assertTrue((bool) $section['uservisible']);
        $this->assertTrue((bool) $section['useravailable']);
    }

    /**
     * A hidden section stays hidden from a student.
     */
    public function test_execute_hides_hidden_sections_from_students(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2, 'format' => 'topics']);
        $this->hide_section($course, 2);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $response = sections::execute($course->id);

        $this->assertSame([0, 1], array_map('intval', array_column($response, 'section')));
    }

    /**
     * A hidden section is still listed for a user who may view hidden sections.
     */
    public function test_execute_shows_hidden_sections_to_a_privileged_user(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2, 'format' => 'topics']);
        $this->hide_section($course, 2);
        $this->setAdminUser();

        $response = sections::execute($course->id);

        $this->assertSame([0, 1, 2], array_map('intval', array_column($response, 'section')));
        $this->assertSame(0, (int) $response[2]['visible']);
    }

    /**
     * Pinning a section id returns that single section rather than a list.
     */
    public function test_execute_returns_a_single_section_when_pinned(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['numsections' => 2, 'format' => 'topics']);
        $sectionid = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 2]);
        $this->setAdminUser();
        sections::set_id($sectionid);

        $response = sections::execute($course->id);

        $this->assertSame((int) $sectionid, (int) $response['id']);
        $this->assertSame(2, (int) $response['section']);
    }

    /**
     * The declared structure covers everything execute() can emit.
     */
    public function test_single_structure_declares_the_reported_fields(): void {
        $keys = array_keys(sections::single_structure()->keys);

        $this->assertSame(
            [
                'id',
                'name',
                'visible',
                'summary',
                'summaryformat',
                'section',
                'uservisible',
                'useravailable',
                'availabilityinfo',
            ],
            $keys
        );
    }

    /**
     * The response validates against the API's own declared structure.
     */
    public function test_execute_matches_the_declared_structure(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 1, 'format' => 'topics']);
        $this->setAdminUser();

        $cleaned = sections::clean_returnvalue(sections::execute_returns(), sections::execute($course->id));

        $this->assertCount(2, $cleaned);
        $this->assertArrayHasKey('name', $cleaned[0]);
    }
}
