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
use completion_info;

/**
 * Tests for the course modules API.
 *
 * @covers     \local_learnwise\external\course_modules
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class course_modules_test extends advanced_testcase {
    /**
     * Reset the static state shared by every API class.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        parent::setUp();
        $this->resetAfterTest();
        baseapi::$my = null;
        baseapi::$ids = [];
        course_modules::$withcompletion = false;
    }

    /**
     * Reset the static state shared by every API class.
     */
    protected function tearDown(): void {
        baseapi::$my = null;
        baseapi::$ids = [];
        course_modules::$withcompletion = false;
        parent::tearDown();
    }

    /**
     * The API is driven by a course id.
     */
    public function test_execute_parameters_take_a_course_id(): void {
        $params = course_modules::execute_parameters();

        $this->assertArrayHasKey('courseid', $params->keys);
        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
    }

    /**
     * Every module of the course is listed with its course module id, name and type.
     */
    public function test_execute_lists_the_course_modules(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Reading']);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Essay']);
        $this->setAdminUser();

        $response = course_modules::execute($course->id);

        $byid = [];
        foreach ($response as $module) {
            $byid[(int) $module['id']] = $module;
        }
        $this->assertSame('Reading', $byid[(int) $page->cmid]['name']);
        $this->assertSame('page', $byid[(int) $page->cmid]['type']);
        $this->assertSame('Essay', $byid[(int) $assign->cmid]['name']);
        $this->assertSame('assign', $byid[(int) $assign->cmid]['type']);
    }

    /**
     * A course with no activities yields an empty list rather than an error.
     */
    public function test_execute_on_an_empty_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->assertSame([], course_modules::execute($course->id));
    }

    /**
     * The site wide flavour hides modules that are not visible.
     */
    public function test_execute_skips_hidden_modules(): void {
        $course = $this->getDataGenerator()->create_course();
        $visible = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Shown']);
        $hidden = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Hidden', 'visible' => 0]
        );
        $this->setAdminUser();

        $ids = array_map('intval', array_column(course_modules::execute($course->id), 'id'));

        $this->assertContains((int) $visible->cmid, $ids);
        $this->assertNotContains((int) $hidden->cmid, $ids);
    }

    /**
     * Completion is only reported when the caller asks for it.
     */
    public function test_execute_reports_completion_on_demand(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Reading', 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $without = course_modules::execute($course->id);
        $this->assertArrayNotHasKey('completionstatus', $without[0]);

        course_modules::$withcompletion = true;
        $with = course_modules::execute($course->id);
        // Moodle 5.x flags the argument the plugin passes to completion_info::get_data().
        $this->resetDebugging();
        $this->assertArrayHasKey('completionstatus', $with[0]);
        $this->assertNull($with[0]['completionstatus']);
    }

    /**
     * A completed activity is reported as completed.
     */
    public function test_execute_reports_a_completed_module(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Reading', 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $completion = new completion_info(get_course($course->id));
        $completion->update_state(get_fast_modinfo($course->id)->get_cm($page->cmid), COMPLETION_COMPLETE, $user->id);

        course_modules::$withcompletion = true;
        $response = course_modules::execute($course->id);
        // Moodle 5.x flags the argument the plugin passes to completion_info::get_data().
        $this->resetDebugging();

        $this->assertSame(get_string('completed', 'local_learnwise'), $response[0]['completionstatus']);
    }

    /**
     * Pinning a course module id returns that single module rather than a list.
     */
    public function test_execute_returns_a_single_module_when_pinned(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'First']);
        $second = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Second']);
        $this->setAdminUser();
        course_modules::set_id($second->cmid);

        $response = course_modules::execute($course->id);

        $this->assertSame((int) $second->cmid, (int) $response['id']);
        $this->assertSame('Second', $response['name']);
    }

    /**
     * The completion field is only declared when completion is being reported.
     */
    public function test_single_structure_declares_completion_on_demand(): void {
        $this->assertArrayNotHasKey('completionstatus', course_modules::single_structure()->keys);

        course_modules::$withcompletion = true;

        $this->assertArrayHasKey('completionstatus', course_modules::single_structure()->keys);
    }
}
