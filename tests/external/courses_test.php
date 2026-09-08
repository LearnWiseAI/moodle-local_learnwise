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

/**
 * Tests for the courses endpoint.
 *
 * The plugin's external classes load the deprecated lib/externallib.php at file scope,
 * which Moodle only permits inside an isolated process.
 *
 * @runTestsInSeparateProcesses
 * @covers     \local_learnwise\external\courses
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class courses_test extends \advanced_testcase {
    /**
     * Clear the shared single-operation and "my" flags between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        baseapi::$ids = [];
        baseapi::$my = null;
    }

    /**
     * Leave no shared endpoint state behind.
     */
    protected function tearDown(): void {
        baseapi::$ids = [];
        baseapi::$my = null;
        parent::tearDown();
    }

    /**
     * In "my" mode only the caller's enrolled courses are listed.
     */
    public function test_my_mode_lists_only_enrolled_courses(): void {
        $enrolled = $this->getDataGenerator()->create_course(['fullname' => 'Enrolled course']);
        $this->getDataGenerator()->create_course(['fullname' => 'Other course']);
        $user = $this->getDataGenerator()->create_and_enrol($enrolled, 'student');

        $this->setUser($user);
        baseapi::$my = true;

        $result = courses::execute();

        $this->assertSame([$enrolled->id], array_column($result, 'id'));
    }

    /**
     * Each listed course carries the fields the response structure declares.
     */
    public function test_course_item_shape(): void {
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Physics 101',
            'shortname' => 'PHY101',
        ]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($user);
        baseapi::$my = true;

        $result = courses::execute();
        $item = reset($result);

        $this->assertSame($course->id, $item['id']);
        $this->assertSame('Physics 101', $item['name']);
        $this->assertSame('PHY101', $item['shortname']);
        $this->assertStringContainsString('/course/view.php', $item['url']);
        $this->assertArrayHasKey('completionstatus', $item);
        $this->assertArrayHasKey('modules', $item);
    }

    /**
     * A course with no start or end date reports nulls rather than zeros.
     */
    public function test_zero_dates_become_null(): void {
        $course = $this->getDataGenerator()->create_course(['startdate' => 0, 'enddate' => 0]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($user);
        baseapi::$my = true;

        $result = courses::execute();
        $item = reset($result);
        $this->assertNull($item['startdate']);
        $this->assertNull($item['enddate']);
    }

    /**
     * Single-operation mode returns just the requested course.
     */
    public function test_single_operation_returns_one_course(): void {
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $coursea->id, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $courseb->id, 'student');

        $this->setUser($user);
        baseapi::$my = true;
        courses::set_id($courseb->id);

        $result = courses::execute();

        $this->assertSame($courseb->id, $result['id']);
    }

    /**
     * Timestamp fields are declared so they get ISO 8601 conversion.
     */
    public function test_unixtimestamp_fields_are_declared(): void {
        $this->assertSame(['startdate', 'enddate'], courses::get_unixtimestamp_fields());
    }

    /**
     * The response structure swaps fields depending on the "my" mode.
     */
    public function test_single_structure_varies_by_mode(): void {
        baseapi::$my = true;
        $mystructure = courses::single_structure();
        $this->assertArrayNotHasKey('participants', $mystructure->keys);
        $this->assertArrayHasKey('completionstatus', $mystructure->keys);

        baseapi::$my = null;
        $allstructure = courses::single_structure();
        $this->assertArrayHasKey('participants', $allstructure->keys);
        $this->assertArrayNotHasKey('completionstatus', $allstructure->keys);
    }

    /**
     * The returned payload validates against the declared return structure.
     */
    public function test_response_matches_declared_structure(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($user);
        baseapi::$my = true;

        $cleaned = courses::clean_returnvalue(courses::execute_returns(), courses::execute());

        $this->assertIsArray($cleaned);
        $this->assertNotEmpty($cleaned);
    }

    /**
     * The endpoint is a read operation with the expected external name.
     */
    public function test_endpoint_metadata(): void {
        $this->assertSame('read', courses::crudtype());
        $this->assertSame('local_learnwise_courses', courses::function_name());
        $this->assertNotEmpty(courses::description());
    }
}
