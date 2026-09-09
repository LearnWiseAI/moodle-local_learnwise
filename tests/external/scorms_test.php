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
 * Tests for the SCORM packages API.
 *
 * @covers     \local_learnwise\external\scorms
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class scorms_test extends advanced_testcase {
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
     * The API is driven by a course id.
     */
    public function test_execute_parameters_take_a_course_id(): void {
        $params = scorms::execute_parameters();

        $this->assertArrayHasKey('courseid', $params->keys);
        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
    }

    /**
     * A course without SCORM packages yields an empty list rather than an error.
     */
    public function test_execute_on_a_course_without_scorms(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->assertSame([], scorms::execute($course->id));
    }

    /**
     * A package is keyed by its course module id and reported with its type.
     */
    public function test_execute_lists_the_course_scorms(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id, 'name' => 'Safety']);

        $response = scorms::execute($course->id);

        $this->assertCount(1, $response);
        $this->assertSame((int) $scorm->cmid, (int) $response[0]['id']);
        $this->assertSame('Safety', $response[0]['name']);
        $this->assertSame('local', $response[0]['type']);
        $this->assertArrayNotHasKey('packageurl', $response[0]);
    }

    /**
     * Pinning a course module id adds the package location to the single response.
     */
    public function test_execute_adds_the_package_details_when_pinned(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id, 'name' => 'Safety']);
        scorms::set_id($scorm->cmid);

        $response = scorms::execute($course->id);

        $this->assertSame((int) $scorm->cmid, (int) $response['id']);
        $this->assertArrayHasKey('packageurl', $response);
        $this->assertArrayHasKey('sha1hash', $response);
    }

    /**
     * The package location is only declared for the single package flavour.
     */
    public function test_single_structure_matches_the_flavour(): void {
        $liststructure = scorms::single_structure();

        $this->assertSame(['id', 'name', 'type'], array_keys($liststructure->keys));

        scorms::set_id(1);
        $singlestructure = scorms::single_structure();

        $this->assertSame(['id', 'name', 'packageurl', 'sha1hash', 'type'], array_keys($singlestructure->keys));
    }
}
