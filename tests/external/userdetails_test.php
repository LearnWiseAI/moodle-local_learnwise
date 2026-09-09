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
use dml_missing_record_exception;
use external_single_structure;

/**
 * Tests for the current user API.
 *
 * @covers     \local_learnwise\external\userdetails
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class userdetails_test extends advanced_testcase {
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
     * The user id is optional and defaults to the caller.
     */
    public function test_execute_parameters_make_the_user_id_optional(): void {
        $params = userdetails::execute_parameters();

        $this->assertSame(VALUE_DEFAULT, $params->keys['userid']->required);
        $this->assertSame(0, $params->keys['userid']->default);
        $this->assertSame(NULL_ALLOWED, $params->keys['userid']->allownull);
    }

    /**
     * The API always answers with one user rather than a list.
     */
    public function test_it_is_always_a_single_operation(): void {
        $this->assertTrue(userdetails::is_singleoperation());
        $this->assertInstanceOf(external_single_structure::class, userdetails::execute_returns());
    }

    /**
     * Access times are reported as ISO 8601 rather than as raw timestamps.
     */
    public function test_access_times_are_declared_as_timestamps(): void {
        $this->assertSame(['firstaccess', 'lastaccess'], userdetails::get_unixtimestamp_fields());

        $structure = userdetails::execute_returns();

        $this->assertInstanceOf(timestampvalue::class, $structure->keys['firstaccess']);
        $this->assertInstanceOf(timestampvalue::class, $structure->keys['lastaccess']);
    }

    /**
     * Passing no user id returns the caller's own profile.
     */
    public function test_execute_defaults_to_the_current_user(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $this->setUser($user);

        $details = userdetails::execute(0);

        $this->assertSame((int) $user->id, (int) $details['id']);
        $this->assertSame('Ada Lovelace', $details['fullname']);
    }

    /**
     * An explicit user id is honoured.
     */
    public function test_execute_accepts_an_explicit_user_id(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Grace', 'lastname' => 'Hopper']);
        $this->setAdminUser();

        $details = userdetails::execute($user->id);

        $this->assertSame((int) $user->id, (int) $details['id']);
        $this->assertSame('Grace Hopper', $details['fullname']);
    }

    /**
     * A missing user is rejected rather than silently ignored.
     */
    public function test_execute_rejects_an_unknown_user(): void {
        $this->setAdminUser();

        $this->expectException(dml_missing_record_exception::class);
        userdetails::execute(-1);
    }
}
