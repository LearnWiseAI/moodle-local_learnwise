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
use external_function_parameters;
use external_single_structure;

/**
 * Tests for the single module API.
 *
 * @covers     \local_learnwise\external\modules
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class modules_test extends advanced_testcase {
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
        modules::$withcompletion = false;
    }

    /**
     * Reset the static state shared by every API class.
     */
    protected function tearDown(): void {
        baseapi::$my = null;
        baseapi::$ids = [];
        modules::$withcompletion = false;
        parent::tearDown();
    }

    /**
     * The module is addressed by the pinned id, so there are no input parameters.
     */
    public function test_execute_parameters_are_empty(): void {
        $params = modules::execute_parameters();

        $this->assertInstanceOf(external_function_parameters::class, $params);
        $this->assertSame([], $params->keys);
    }

    /**
     * The API always answers with one module rather than a list.
     */
    public function test_it_is_always_a_single_operation(): void {
        $this->assertTrue(modules::is_singleoperation());
        $this->assertInstanceOf(external_single_structure::class, modules::execute_returns());
    }

    /**
     * Without a pinned module id there is nothing to return.
     */
    public function test_execute_without_a_pinned_id_returns_nothing(): void {
        $this->setAdminUser();

        $this->assertSame([], modules::execute());
    }

    /**
     * The pinned module is described by id, name and type.
     */
    public function test_execute_describes_the_pinned_module(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Reading']);
        $this->setAdminUser();
        modules::set_id($page->cmid);

        $response = modules::execute();

        $this->assertSame((int) $page->cmid, (int) $response['id']);
        $this->assertSame('Reading', $response['name']);
        $this->assertSame('page', $response['type']);
    }

    /**
     * A hidden module is not described at all.
     */
    public function test_execute_refuses_a_hidden_module(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Hidden', 'visible' => 0]
        );
        $this->setAdminUser();
        modules::set_id($page->cmid);

        $this->assertSame([], modules::execute());
    }

    /**
     * Completion is only reported when the caller asks for it.
     */
    public function test_execute_reports_completion_on_demand(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Reading', 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);
        modules::set_id($page->cmid);

        $this->assertArrayNotHasKey('completionstatus', modules::execute());

        modules::$withcompletion = true;
        $response = modules::execute();
        // Moodle 5.x flags the argument the plugin passes to completion_info::get_data().
        $this->resetDebugging();

        $this->assertArrayHasKey('completionstatus', $response);
        $this->assertNull($response['completionstatus']);
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

        modules::$withcompletion = true;
        modules::set_id($page->cmid);

        $response = modules::execute();
        // Moodle 5.x flags the argument the plugin passes to completion_info::get_data().
        $this->resetDebugging();

        $this->assertSame(get_string('completed', 'local_learnwise'), $response['completionstatus']);
    }

    /**
     * The completion field is only declared when completion is being reported.
     */
    public function test_single_structure_declares_completion_on_demand(): void {
        $this->assertArrayNotHasKey('completionstatus', modules::single_structure()->keys);

        modules::$withcompletion = true;

        $this->assertArrayHasKey('completionstatus', modules::single_structure()->keys);
    }
}
