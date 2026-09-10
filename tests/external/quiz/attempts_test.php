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

namespace local_learnwise\external\quiz;

use advanced_testcase;
use context_module;
use local_learnwise\external\baseapi;
use local_learnwise\external\timestampvalue;
use question_engine;
use stdClass;
use test_question_maker;

/**
 * Tests for the quiz attempts API.
 *
 * @covers     \local_learnwise\external\quiz\attempts
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class attempts_test extends advanced_testcase {
    /**
     * Reset the static state shared by every API class.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/tests/helpers.php');

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
     * The API is driven by a course id and a quiz course module id.
     */
    public function test_execute_parameters(): void {
        $params = attempts::execute_parameters();

        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
        $this->assertSame(PARAM_INT, $params->keys['quizid']->type);
    }

    /**
     * The attempt timings are reported as ISO 8601.
     */
    public function test_times_are_declared_as_timestamps(): void {
        $this->assertSame(['timestart', 'timefinish'], attempts::get_unixtimestamp_fields());

        $structure = attempts::execute_returns()->content;

        $this->assertInstanceOf(timestampvalue::class, $structure->keys['timestart']);
        $this->assertInstanceOf(timestampvalue::class, $structure->keys['timefinish']);
    }

    /**
     * The response is trimmed to the fields LearnWise consumes, in a fixed order.
     */
    public function test_single_structure_is_trimmed_and_ordered(): void {
        $this->assertSame(
            ['id', 'state', 'sumgrades', 'grade', 'timestart', 'timefinish'],
            array_keys(attempts::single_structure()->keys)
        );
    }

    /**
     * The rescaled grade defaults to nothing until an attempt has been marked.
     */
    public function test_single_structure_makes_the_grade_optional(): void {
        $gradekey = attempts::single_structure()->keys['grade'];

        $this->assertSame(PARAM_FLOAT, $gradekey->type);
        $this->assertSame(VALUE_DEFAULT, $gradekey->required);
        $this->assertNull($gradekey->default);
    }

    /**
     * A quiz nobody has attempted yields an empty list rather than an error.
     */
    public function test_execute_without_attempts(): void {
        [$course, $quiz, $user] = $this->create_quiz();
        $this->setUser($user);

        $response = attempts::execute($course->id, $quiz->cmid);
        $this->reset_deprecation_debugging();

        $this->assertSame([], $response);
    }

    /**
     * A finished attempt is reported with its state and its rescaled grade.
     */
    public function test_execute_reports_a_finished_attempt(): void {
        [$course, $quiz, $user] = $this->create_quiz(['grade' => 100, 'sumgrades' => 10]);
        $attempt = $this->create_attempt($quiz, $user, 8.0);
        $this->setUser($user);

        $response = attempts::execute($course->id, $quiz->cmid);
        $response = attempts::clean_returnvalue(attempts::execute_returns(), $response);
        $this->reset_deprecation_debugging();

        $this->assertCount(1, $response);
        $this->assertSame((int) $attempt->id, (int) $response[0]['id']);
        $this->assertSame('finished', $response[0]['state']);
        $this->assertEquals(80.0, $response[0]['grade'], '', 0.001);
    }

    /**
     * An attempt still in progress has no finish time to report.
     */
    public function test_execute_reports_an_unfinished_attempt_without_a_finish_time(): void {
        [$course, $quiz, $user] = $this->create_quiz();
        $this->create_attempt($quiz, $user, null, 'inprogress');
        $this->setUser($user);

        $response = attempts::execute($course->id, $quiz->cmid);
        $response = attempts::clean_returnvalue(attempts::execute_returns(), $response);
        $this->reset_deprecation_debugging();

        $this->assertSame('inprogress', $response[0]['state']);
        $this->assertNull($response[0]['timefinish']);
    }

    /**
     * Pinning an attempt id returns that single attempt rather than a list.
     */
    public function test_execute_returns_a_single_attempt_when_pinned(): void {
        [$course, $quiz, $user] = $this->create_quiz(['grade' => 100, 'sumgrades' => 10]);
        $this->create_attempt($quiz, $user, 4.0, 'finished', 1);
        $second = $this->create_attempt($quiz, $user, 9.0, 'finished', 2);
        $this->setUser($user);
        attempts::set_id($second->id);

        $response = attempts::execute($course->id, $quiz->cmid);
        $this->reset_deprecation_debugging();

        $this->assertSame((int) $second->id, (int) $response['id']);
        $this->assertEquals(90.0, $response['grade'], '', 0.001);
    }

    /**
     * Build a course with a quiz and an enrolled user.
     *
     * @param array $options Extra quiz settings
     * @return array
     */
    protected function create_quiz(array $options = []) {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', $options + ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        return [$course, $quiz, $user];
    }

    /**
     * Record a quiz attempt directly, so the test does not depend on the question engine.
     *
     * @param stdClass $quiz Quiz the attempt belongs to
     * @param stdClass $user User making the attempt
     * @param float|null $sumgrades Raw marks earned
     * @param string $state Attempt state
     * @param int $number Attempt number
     * @return stdClass
     */
    protected function create_attempt($quiz, $user, $sumgrades, $state = 'finished', $number = 1) {
        global $DB;

        $context = context_module::instance($quiz->cmid);
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $slot = $quba->add_question(test_question_maker::make_question('truefalse', 'true'));
        $quba->get_question_attempt($slot)->start('deferredfeedback', 1);
        question_engine::save_questions_usage_by_activity($quba);

        $attempt = (object) [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'uniqueid' => $quba->get_id(),
            'attempt' => $number,
            'layout' => '',
            'currentpage' => 0,
            'preview' => 0,
            'state' => $state,
            'timestart' => time() - HOURSECS,
            'timefinish' => $state === 'finished' ? time() - (HOURSECS / 2) : 0,
            'timemodified' => time(),
            'sumgrades' => $sumgrades,
        ];
        $attempt->id = $DB->insert_record('quiz_attempts', $attempt);
        return $attempt;
    }

    /**
     * Moodle 5.0 deprecated the attempts web service the API is built on. Clear the
     * deprecation notice so the test reports on the API's own behaviour instead.
     *
     * @return void
     */
    protected function reset_deprecation_debugging() {
        $this->resetDebugging();
    }
}
