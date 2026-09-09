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
use invalid_parameter_exception;

/**
 * Tests for the named user API.
 *
 * @covers     \local_learnwise\external\users
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class users_test extends advanced_testcase {
    /**
     * Reset the static state shared by every API class.
     */
    protected function setUp(): void {
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
     * Unlike the "me" route, this one demands an explicit user id.
     */
    public function test_execute_parameters_require_the_user_id(): void {
        $params = users::execute_parameters();

        $this->assertSame(VALUE_REQUIRED, $params->keys['userid']->required);
        $this->assertSame(NULL_NOT_ALLOWED, $params->keys['userid']->allownull);
    }

    /**
     * Tightening the parameters must not leak back into the parent route.
     */
    public function test_execute_parameters_do_not_affect_the_parent_route(): void {
        users::execute_parameters();

        $params = userdetails::execute_parameters();

        $this->assertSame(VALUE_DEFAULT, $params->keys['userid']->required);
        $this->assertSame(NULL_ALLOWED, $params->keys['userid']->allownull);
    }

    /**
     * Omitting the user id is a parameter error rather than a fallback to the caller.
     */
    public function test_execute_rejects_a_missing_user_id(): void {
        $this->setAdminUser();

        $this->expectException(invalid_parameter_exception::class);
        users::validate_parameters(users::execute_parameters(), []);
    }

    /**
     * The route reuses the parent implementation to fetch the profile.
     */
    public function test_execute_returns_the_requested_user(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Alan', 'lastname' => 'Turing']);
        $this->setAdminUser();

        $details = users::execute($user->id);

        $this->assertSame((int) $user->id, (int) $details['id']);
        $this->assertSame('Alan Turing', $details['fullname']);
    }
}
