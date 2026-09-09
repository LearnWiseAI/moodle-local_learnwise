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
use Exception;
use external_single_structure;
use invalid_parameter_exception;

/**
 * Tests for the file accessibility API.
 *
 * @covers     \local_learnwise\external\files
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class files_test extends advanced_testcase {
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
     * The API takes the path of the file to probe.
     */
    public function test_execute_parameters_take_a_path(): void {
        $params = files::execute_parameters();

        $this->assertArrayHasKey('path', $params->keys);
        $this->assertSame(PARAM_PATH, $params->keys['path']->type);
    }

    /**
     * The API always answers with one verdict rather than a list.
     */
    public function test_it_is_always_a_single_operation(): void {
        $this->assertTrue(files::is_singleoperation());
        $this->assertInstanceOf(external_single_structure::class, files::execute_returns());
    }

    /**
     * The verdict is a single boolean.
     */
    public function test_single_structure_is_a_single_verdict(): void {
        $structure = files::single_structure();

        $this->assertSame(['accessible'], array_keys($structure->keys));
        $this->assertSame(PARAM_BOOL, $structure->keys['accessible']->type);
    }

    /**
     * A path that is not a path at all is rejected before any request is made.
     */
    public function test_execute_rejects_an_invalid_path(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(invalid_parameter_exception::class);
        files::execute('../../etc/passwd');
    }

    /**
     * The short lived key minted for the probe is always cleaned up again.
     */
    public function test_execute_does_not_leave_a_user_key_behind(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $path = '/1/mod_assign/intro/0/notes.pdf';

        try {
            files::execute($path);
        } catch (Exception $e) {
            // The probe itself needs a reachable web server, which a unit test does not have.
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertFalse($DB->record_exists('user_private_key', [
            'script' => 'local_learnwise_' . sha1($path),
            'userid' => $user->id,
        ]));
    }
}
