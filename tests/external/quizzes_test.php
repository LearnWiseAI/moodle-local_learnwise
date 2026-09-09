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
 * Tests for the quizzes API.
 *
 * @covers     \local_learnwise\external\quizzes
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class quizzes_test extends advanced_testcase {
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
        $params = quizzes::execute_parameters();

        $this->assertArrayHasKey('courseid', $params->keys);
        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
    }

    /**
     * The closing date is reported as ISO 8601.
     */
    public function test_the_close_date_is_declared_as_a_timestamp(): void {
        $this->assertSame(['timeclose'], quizzes::get_unixtimestamp_fields());

        $this->assertInstanceOf(timestampvalue::class, quizzes::execute_returns()->content->keys['timeclose']);
    }

    /**
     * The response is trimmed to the fields LearnWise consumes, in a fixed order.
     */
    public function test_single_structure_is_trimmed_and_ordered(): void {
        $this->assertSame(
            ['id', 'name', 'intro', 'timeclose', 'grade', 'sumgrades', 'descriptionfiles'],
            array_keys(quizzes::single_structure()->keys)
        );
    }

    /**
     * A course without quizzes yields an empty list rather than an error.
     */
    public function test_execute_on_a_course_without_quizzes(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->assertSame([], quizzes::execute($course->id));
    }

    /**
     * A quiz is keyed by its course module id rather than its instance id.
     */
    public function test_execute_keys_a_quiz_by_its_course_module_id(): void {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Midterm',
            'timeclose' => 0,
        ]);
        $this->setAdminUser();

        $response = quizzes::execute($course->id);

        $this->assertCount(1, $response);
        $this->assertSame((int) $quiz->cmid, (int) $response[0]['id']);
        $this->assertSame('Midterm', $response[0]['name']);
        $this->assertSame([], $response[0]['descriptionfiles']);
    }

    /**
     * A closing date that was never set is reported as null, not as zero.
     */
    public function test_execute_reports_an_unset_close_date_as_null(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'timeclose' => 0]);
        $this->setAdminUser();

        $response = quizzes::execute($course->id);

        $this->assertNull($response[0]['timeclose']);
    }

    /**
     * A configured closing date is passed through.
     */
    public function test_execute_reports_a_configured_close_date(): void {
        $timeclose = time() + WEEKSECS;
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'timeclose' => $timeclose]);
        $this->setAdminUser();

        $response = quizzes::execute($course->id);

        $this->assertSame($timeclose, (int) $response[0]['timeclose']);
    }

    /**
     * Pinning a course module id returns that single quiz rather than a list.
     */
    public function test_execute_returns_a_single_quiz_when_pinned(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'First']);
        $second = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Second']);
        $this->setAdminUser();
        quizzes::set_id($second->cmid);

        $response = quizzes::execute($course->id);

        $this->assertSame((int) $second->cmid, (int) $response['id']);
        $this->assertSame('Second', $response['name']);
    }
}
